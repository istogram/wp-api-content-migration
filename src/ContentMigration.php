<?php

namespace istogram\WpApiContentMigration;

use Roots\Acorn\Application;

class ContentMigration
{
    /**
     * The application instance.
     *
     * @var \Roots\Acorn\Application
     */
    protected $app;

    /**
     * Create a new ContentMigration instance.
     *
     * @return void
     */
    public function __construct(Application $app)
    {
        $this->app = $app;

        if ($this->app['config']->get('content-migration.allow_svg_media')) {
            add_filter( 'upload_mimes', function ( $mimes ){
                $mimes['svg'] = 'image/svg+xml';
                return $mimes;
            });
        }
    }

    /**
     * Create WP category. This method also sets the parent category if it exists.
     *
     * @param object $category
     *
     * @return void
     */
    public function createCategory($category)
    {
        $params = [
            'slug' => $category->slug,
        ];

        if ($category->parent !== 0) {
            $parent_category = $this->app->db->table('termmeta')->where('meta_key', 'wp_api_prev_category_id')->where('meta_value', $category->parent)->value('term_id');
            $params['parent'] = $parent_category;
        }

        try {
            // check if category exists
            $category_exists = get_term_by('slug', $category->slug, 'category');

            if (empty($category_exists)) {
                // create WP term using name and slug and parent
                $term = wp_insert_term($category->name, 'category', $params);

                // save term meta for category
                update_term_meta($term['term_id'], 'wp_api_prev_category_id', $category->id);
            }
        } catch (\Exception $e) {
            $this->app->log->info('Error creating WP category : '.$e->getMessage());
        }
    }

    /**
     * Create WP tag. This method also sets the slug for the tag.
     *
     * @param object $tag
     *
     * @return void
     */
    public function createTag($tag)
    {
        try {
            // create WP term using name and slug
            $term_id = wp_insert_term($tag->name, 'post_tag', [
                'slug' => $tag->slug,
            ]);

            // save term meta for tag
            update_term_meta($term_id['term_id'], 'wp_api_prev_tag_id', $tag->id);
        } catch (\Exception $e) {
            $this->app->log->info('Error creating WP tag : '.$e->getMessage());
        }
    }

    /**
     * Create WP media. This method also sets the alt text for the media.
     *
     * @param object $media
     *
     * @return void
     */
    public function createMedia($media)
    {
        // set params
        $params = [
            'file' => $media->source_url,
        ];

        try {
            // check if media exists
            $media_exists = get_posts([
                'post_type' => 'attachment',
                'meta_key' => 'source_url',
                'meta_value' => $media->source_url,
                'numberposts' => 1,
            ]);

            if (!empty($media_exists)) {
                $this->app->log->info('Media already exists : '.$media->source_url);
                return;
            }

            // download to temp dir
            $temp_file = download_url($params['file']);

            if (is_wp_error($temp_file)) {
                $this->app->log->info('Error downloading WP media : '.$temp_file->get_error_message());
                return false;
            }

            // move the temp file into the uploads directory
            $file = [
                'name' => basename($params['file']),
                'type' => mime_content_type($temp_file),
                'tmp_name' => $temp_file,
                'size' => filesize($temp_file),
            ];

            $upload = wp_handle_sideload(
                $file,
                [
                    'test_form' => false, // no needs to check 'action' parameter
                ]
            );

            if (!empty($sideload['error'])) {
                return false;
            }

            $caption = !empty($media->caption->rendered) ? $media->caption->rendered : $media->title->rendered;
            $description = !empty($media->description->rendered) ? $media->description->rendered : $media->caption->rendered;

            // create attachment
            $attachment = [
                'post_title' => $media->title->rendered,
                'post_excerpt' => sanitize_text_field($caption),
                'post_content' => sanitize_text_field($description),
                'post_status' => 'inherit',
                'post_mime_type' => $media->mime_type,
            ];

            $attach_id = wp_insert_attachment($attachment, $upload['file']);

            // set attachment metadata
            wp_update_attachment_metadata($attach_id, wp_generate_attachment_metadata($attach_id, $upload['file']));

            // save media meta
            update_post_meta($attach_id, 'wp_api_prev_featured_media_id', $media->id);

            // update alt text
            update_post_meta($attach_id, '_wp_attachment_image_alt', sanitize_text_field($media->alt_text ?? $media->caption->rendered));
        } catch (\Exception $e) {
            $this->app->log->info('Error creating WP media : '.$e->getMessage());
        }
    }

    /**
     * Create WP post. This method also sets the featured image, categories and tags.
     *
     * @param object $post
     *
     * @return void
     */
    public function createPost($post)
    {
        // set post content
        $content = $post->content->rendered;

        // find previous image urls and replace with new urls
        $content = preg_replace_callback('/<img[^>]+src="([^">]+)"/', function ($matches) use ($post) {
            // get attachment_id for meta_key 'wp_api_prev_featured_media_id'
            $media_id = $this->app->db->table('postmeta')->where('meta_key', 'wp_api_prev_featured_media_id')->where('meta_value', $post->featured_media)->value('post_id');

            if (!empty($media_id)) {
                $media_url = wp_get_attachment_url($media_id);

                return str_replace($matches[1], $media_url, $matches[0]);
            }

            return $matches[0];
        }, $content);

        // find links around images and remove
        $content = preg_replace('/<a[^>]+>(<img[^>]+>)<\/a>/', '$1', $content);

        // set post excerpt
        $excerpt = $post->excerpt->rendered;

        // strip html tags from excerpt
        $excerpt = strip_tags($excerpt);

        // set post status
        $status = $post->status;

        // set post type
        $type = $post->type;

        // set post title
        $title = $post->title->rendered;

        // set post slug
        $slug = $post->slug;

        // set post author
        $author = $post->author;

        // set post created date
        $created = $post->date;

        // set post categories from saved meta
        $categories = [];

        foreach ($post->categories as $category) {
            // get term_id for meta_key 'wp_api_prev_category_id'
            $categories[] = $this->app->db->table('termmeta')->where('meta_key', 'wp_api_prev_category_id')->where('meta_value', $category)->value('term_id');
        }

        // set post tags from saved meta
        $tags = [];

        foreach ($post->tags as $tag) {
            // get term_id for meta_key 'wp_api_prev_tag_id'
            $tag_id = $this->app->db->table('termmeta')->where('meta_key', 'wp_api_prev_tag_id')->where('meta_value', $tag)->value('term_id');

            // check if tag exists
            if (!empty($tag_id)) {
                $tags[] = get_term($tag_id)->name;
            }
        }

        // get post_id for meta_key 'wp_api_prev_featured_media_id'
        $media = $this->app->db->table('postmeta')->where('meta_key', 'wp_api_prev_featured_media_id')->where('meta_value', $post->featured_media)->value('post_id');

        // set post meta
        $meta = $post->meta;

        try {
            // create WP post
            $post_id = wp_insert_post([
                'post_content' => $content,
                'post_excerpt' => $excerpt,
                'post_status' => $status,
                'post_type' => $type,
                'post_title' => $title,
                'post_name' => $slug,
                'post_author' => $author,
                'post_category' => $categories,
                'tags_input' => $tags,
                'meta_input' => $meta,
                'post_date' => $created,
            ]);

            // check if post has media
            if (!empty($media)) {
                // add featured image to post
                set_post_thumbnail($post_id, $media);
            }
        } catch (\Exception $e) {
            $this->app->log->info('Error creating WP post : '.$e->getMessage());
        }
    }

    /**
     * Create WP page. This method also sets the parent page if it exists.
     *
     * @param object $page
     *
     * @return void
     */
    public function createPage($page)
    {
        // set page content
        $content = $page->content->rendered;

        // process content to remove anything that includes []
        $content = preg_replace('/\[[^\]]+\]/', '', $content);

        // set page excerpt
        $excerpt = $page->excerpt->rendered;

        // set page status
        $status = $page->status;

        // set page title
        $title = $page->title->rendered;

        // set page author
        $author = $page->author;

        // set page meta
        $meta = $page->meta;

        // set parent page from saved meta
        $parentId = $this->app->db->table('postmeta')->where('meta_key', 'wp_api_prev_page_id')->where('meta_value', $page->parent)->value('post_id');

        try {
            // create WP page
            $page_id = wp_insert_post([
                'post_content' => $content,
                'post_excerpt' => $excerpt,
                'post_status' => $status,
                'post_type' => 'page',
                'post_title' => $title,
                'post_author' => $author,
                'meta_input' => $meta,
                'post_parent' => $parentId,
            ]);

            // save parent page to meta
            if (!empty($page->parent)) {
                update_post_meta($page_id, 'wp_api_prev_page_parent_id', $page->parent);
            }

            update_post_meta($page_id, 'wp_api_prev_page_id', $page->id);
        } catch (\Exception $e) {
            $this->app->log->info('Error creating WP page : '.$e->getMessage());
        }
    }
}
