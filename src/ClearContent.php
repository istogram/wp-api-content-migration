<?php

namespace istogram\WpApiContentMigration;

use Roots\Acorn\Application;

class ClearContent
{
    /**
     * The application instance.
     *
     * @var \Roots\Acorn\Application
     */
    protected $app;

    /**
     * Create a new ClearContent instance.
     *
     * @return void
     */
    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    /**
     * Clear WP taxonomies. This will delete all categories and tags.
     *
     * @return void
     */
    public function clearTaxonomies()
    {
        try {
            // delete all terms
            $terms = get_terms([
                'taxonomy' => 'category',
                'hide_empty' => false,
            ]);

            foreach ($terms as $term) {
                wp_delete_term($term->term_id, 'category');
            }

            $terms = get_terms([
                'taxonomy' => 'post_tag',
                'hide_empty' => false,
            ]);

            foreach ($terms as $term) {
                wp_delete_term($term->term_id, 'post_tag');
            }

            // delete all term metadata
            $this->clearImportedMeta('category');
            $this->clearImportedMeta('tag');

            // return response
            return 'Taxonomies cleared';
        } catch (\Exception $e) {
            return $e->getMessage();
        }
    }

    /**
     * Clear WP media. This will delete all media files and their metadata.
     *
     * @return void
     */
    public function clearMedia()
    {
        try {
            // delete all media
            $media = get_posts([
                'post_type' => 'attachment',
                'numberposts' => -1,
                'post_status' => null,
            ]);

            foreach ($media as $medium) {
                wp_delete_attachment($medium->ID, true);
            }

            // delete all media metadata
            $this->app->db->table('postmeta')->where('meta_key', '_wp_attached_file')->delete();
            $this->app->db->table('postmeta')->where('meta_key', '_wp_attachment_metadata')->delete();
            $this->clearImportedMeta('featured_media');

            // delete files in uploads directory
            $uploads_dir = wp_upload_dir();

            $files = glob($uploads_dir['basedir'].'/*');

            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }

            return 'Media cleared';
        } catch (\Exception $e) {
            return $e->getMessage();
        }
    }

    /**
     * Clear WP posts. This will also clear all post metadata.
     *
     * @return void
     */
    public function clearPosts()
    {
        try {
            // delete all posts
            $posts = get_posts([
                'post_type' => 'post',
                'numberposts' => -1,
                'post_status' => null,
            ]);

            foreach ($posts as $post) {
                wp_delete_post($post->ID, true);
            }

            // delete all post metadata
            $this->clearImportedMeta('post');

            return 'Posts cleared';
        } catch (\Exception $e) {
            return $e->getMessage();
        }
    }

    /**
     * Clear WP pages. This method also clears all imported page metadata.
     *
     * @return void
     */
    public function clearPages()
    {
        try {
            // delete all pages
            $pages = get_posts([
                'post_type' => 'page',
                'numberposts' => -1,
                'post_status' => null,
            ]);

            foreach ($pages as $page) {
                wp_delete_post($page->ID, true);
            }

            // delete all page metadata
            $this->clearImportedMeta('page');

            return 'Pages cleared';
        } catch (\Exception $e) {
            return $e->getMessage();
        }
    }

    /**
     * Clear imported meta data. This method is used to clear meta data
     * that was imported from WP API.
     *
     * @return void
     */
    public function clearImportedMeta($type)
    {
        switch ($type) {
            case 'category':
                $this->app->db->table('termmeta')->where('meta_key', 'wp_api_prev_category_id')->delete();
                break;
            case 'tag':
                $this->app->db->table('termmeta')->where('meta_key', 'wp_api_prev_tag_id')->delete();
                break;
            case 'featured_media':
                $this->app->db->table('postmeta')->where('meta_key', 'wp_api_prev_featured_media_id')->delete();
                break;
            case 'post':
                $this->app->db->table('postmeta')->where('meta_key', 'wp_api_prev_post_id')->delete();
                break;
            case 'page':
                $this->app->db->table('postmeta')->where('meta_key', 'wp_api_prev_page_id')->delete();
                $this->app->db->table('postmeta')->where('meta_key', 'wp_api_prev_page_parent_id')->delete();
                break;
        }
    }
}
