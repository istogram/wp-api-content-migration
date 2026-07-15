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
            add_filter('upload_mimes', function ($mimes) {
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
            // check if tag exists
            $tag_exists = get_term_by('slug', $tag->slug, 'post_tag');

            if (empty($tag_exists)) {
                // create WP term using name and slug
                $term = wp_insert_term($tag->name, 'post_tag', [
                    'slug' => $tag->slug,
                ]);

                if (!is_wp_error($term)) {
                    // save term meta for tag
                    update_term_meta($term['term_id'], 'wp_api_prev_tag_id', $tag->id);
                }
            }
        } catch (\Exception $e) {
            $this->app->log->info('Error creating WP tag : '.$e->getMessage());
        }
    }

    /**
     * Create WP media. This method also sets the alt text for the media.
     * Preserves the original directory structure from the source site.
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

            // Extract the original path structure from source_url
            // Example: https://example.com/wp-content/uploads/2023/05/image.jpg -> 2023/05/image.jpg
            $upload_dir = wp_upload_dir();
            $original_path = $this->extractUploadPath($media->source_url);

            // Build the target directory path
            $target_dir = $upload_dir['basedir'] . '/' . dirname($original_path);
            $target_file = $upload_dir['basedir'] . '/' . $original_path;
            $target_url = $upload_dir['baseurl'] . '/' . $original_path;

            // Create directory if it doesn't exist
            if (!file_exists($target_dir)) {
                wp_mkdir_p($target_dir);
            }

            // Move file to target location
            if (!copy($temp_file, $target_file)) {
                $this->app->log->info('Error moving file to target location: ' . $target_file);
                @unlink($temp_file);
                return false;
            }

            // Clean up temp file
            @unlink($temp_file);

            $caption = !empty($media->caption->rendered) ? $media->caption->rendered : $media->title->rendered;
            $description = !empty($media->description->rendered) ? $media->description->rendered : $media->caption->rendered;

            // create attachment
            $attachment = [
                'post_title' => $media->title->rendered,
                'post_excerpt' => sanitize_text_field($caption),
                'post_content' => sanitize_text_field($description),
                'post_status' => 'inherit',
                'post_mime_type' => $media->mime_type,
                'guid' => $target_url,
            ];

            $attach_id = wp_insert_attachment($attachment, $target_file);

            // set attachment metadata
            wp_update_attachment_metadata($attach_id, wp_generate_attachment_metadata($attach_id, $target_file));

            // save media meta
            update_post_meta($attach_id, 'wp_api_prev_featured_media_id', $media->id);
            update_post_meta($attach_id, 'source_url', $media->source_url);

            // update alt text
            update_post_meta($attach_id, '_wp_attachment_image_alt', sanitize_text_field($media->alt_text ?? $media->caption->rendered));
        } catch (\Exception $e) {
            $this->app->log->info('Error creating WP media : '.$e->getMessage());
        }
    }

    /**
     * Extract the upload path from a source URL
     * Handles various URL structures to find the path after /uploads/
     *
     * @param string $source_url
     * @return string
     */
    protected function extractUploadPath($source_url)
    {
        // Parse the URL
        $parsed = parse_url($source_url);
        $path = $parsed['path'];

        // Find the position of /uploads/ in the path
        $uploads_pos = strpos($path, '/uploads/');

        if ($uploads_pos !== false) {
            // Extract everything after /uploads/
            return substr($path, $uploads_pos + strlen('/uploads/'));
        }

        // Fallback: if /uploads/ not found, use the basename with current year/month
        // This maintains some structure even for edge cases
        $upload_dir = wp_upload_dir();
        return $upload_dir['subdir'] . '/' . basename($source_url);
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

            // save post meta for comment migration
            update_post_meta($post_id, 'wp_api_prev_post_id', $post->id);
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

    /**
     * Create WP comment. This method also sets the parent comment if it exists.
     *
     * @param object $comment
     *
     * @return void
     */
    public function createComment($comment)
    {
        // get post_id for meta_key 'wp_api_prev_post_id'
        $post_id = $this->app->db->table('postmeta')
            ->where('meta_key', 'wp_api_prev_post_id')
            ->where('meta_value', $comment->post)
            ->value('post_id');

        // skip if post doesn't exist
        if (empty($post_id)) {
            $this->app->log->info('Skipping comment - post not found for original post ID: '.$comment->post);
            return;
        }

        // get parent comment id if this is a reply
        $parent_id = 0;
        if ($comment->parent !== 0) {
            $parent_id = $this->app->db->table('commentmeta')
                ->where('meta_key', 'wp_api_prev_comment_id')
                ->where('meta_value', $comment->parent)
                ->value('comment_id');

            // if parent not found, set to 0
            if (empty($parent_id)) {
                $parent_id = 0;
            }
        }

        try {
            // create WP comment
            $comment_id = wp_insert_comment([
                'comment_post_ID' => $post_id,
                'comment_author' => $comment->author_name ?? '',
                'comment_author_email' => $comment->author_email ?? '',
                'comment_author_url' => $comment->author_url ?? '',
                'comment_content' => $comment->content->rendered,
                'comment_date' => $comment->date,
                'comment_approved' => 1,
                'comment_parent' => $parent_id,
            ]);

            // save comment meta
            if ($comment_id) {
                update_comment_meta($comment_id, 'wp_api_prev_comment_id', $comment->id);
            }
        } catch (\Exception $e) {
            $this->app->log->info('Error creating WP comment : '.$e->getMessage());
        }
    }

    /**
     * Create a WooCommerce product category (product_cat taxonomy).
     * Sets the parent term (if already imported) and category thumbnail.
     *
     * @param object $category
     *
     * @return void
     */
    public function createProductCategory($category)
    {
        $params = [
            'slug' => $category->slug,
        ];

        if (!empty($category->description)) {
            $params['description'] = $category->description;
        }

        // resolve parent term from previously imported meta
        if (!empty($category->parent) && $category->parent !== 0) {
            $parent = $this->app->db->table('termmeta')
                ->where('meta_key', 'wp_api_prev_product_cat_id')
                ->where('meta_value', $category->parent)
                ->value('term_id');

            if (!empty($parent)) {
                $params['parent'] = $parent;
            }
        }

        try {
            // check if the product category exists
            $exists = get_term_by('slug', $category->slug, 'product_cat');

            if (!empty($exists)) {
                // a term with this slug already exists (e.g. the default
                // "Uncategorized" term or a partial previous run). Record the
                // source-id mapping so products and child categories can resolve
                // it, then leave the existing term otherwise untouched.
                update_term_meta($exists->term_id, 'wp_api_prev_product_cat_id', $category->id);

                return;
            }

            $term = wp_insert_term($category->name, 'product_cat', $params);

            if (!is_wp_error($term)) {
                update_term_meta($term['term_id'], 'wp_api_prev_product_cat_id', $category->id);

                // set the category thumbnail if present
                if (!empty($category->image)) {
                    $image_id = $this->resolveImageId($category->image);

                    if ($image_id) {
                        update_term_meta($term['term_id'], 'thumbnail_id', $image_id);
                    }
                }
            }
        } catch (\Exception $e) {
            $this->app->log->info('Error creating WP product category : '.$e->getMessage());
        }
    }

    /**
     * Create a WooCommerce product tag (product_tag taxonomy).
     *
     * @param object $tag
     *
     * @return void
     */
    public function createProductTag($tag)
    {
        try {
            // check if the product tag exists
            $exists = get_term_by('slug', $tag->slug, 'product_tag');

            if (!empty($exists)) {
                // record the source-id mapping on the pre-existing term so
                // products can resolve it, then leave it otherwise untouched.
                update_term_meta($exists->term_id, 'wp_api_prev_product_tag_id', $tag->id);

                return;
            }

            $params = [
                'slug' => $tag->slug,
            ];

            if (!empty($tag->description)) {
                $params['description'] = $tag->description;
            }

            $term = wp_insert_term($tag->name, 'product_tag', $params);

            if (!is_wp_error($term)) {
                update_term_meta($term['term_id'], 'wp_api_prev_product_tag_id', $tag->id);
            }
        } catch (\Exception $e) {
            $this->app->log->info('Error creating WP product tag : '.$e->getMessage());
        }
    }

    /**
     * Create a WooCommerce product from a wc/v3 product object.
     *
     * Products are created through WooCommerce's CRUD classes (WC_Product_*)
     * so that all internal bookkeeping (postmeta, lookup tables, HPOS) is
     * handled by WooCommerce itself. Categories, tags and images are mapped
     * from previously imported content. Product relationships (up-sells,
     * cross-sells, grouped children) reference source IDs and are resolved
     * later by linkProductRelationships() once every product exists.
     *
     * @param object $product
     *
     * @return void
     */
    public function createProduct($product)
    {
        if (!class_exists('WC_Product_Simple')) {
            $this->app->log->info('WooCommerce is not active - skipping product : '.($product->slug ?? $product->id));
            return;
        }

        try {
            // skip products that were already imported
            if (!empty($this->mappedProductId($product->id))) {
                $this->app->log->info('Product already exists : '.$product->slug);
                return;
            }

            // instantiate the correct product type
            switch ($product->type ?? 'simple') {
                case 'variable':
                    $obj = new \WC_Product_Variable();
                    break;
                case 'grouped':
                    $obj = new \WC_Product_Grouped();
                    break;
                case 'external':
                    $obj = new \WC_Product_External();
                    break;
                default:
                    $obj = new \WC_Product_Simple();
                    break;
            }

            // core fields
            $obj->set_name($product->name);

            if (!empty($product->slug)) {
                $obj->set_slug($product->slug);
            }

            $status = $product->status ?? 'publish';
            $obj->set_status(in_array($status, ['publish', 'draft', 'pending', 'private'], true) ? $status : 'publish');
            $obj->set_featured((bool) ($product->featured ?? false));

            if (!empty($product->catalog_visibility)) {
                $obj->set_catalog_visibility($product->catalog_visibility);
            }

            $obj->set_description($product->description ?? '');
            $obj->set_short_description($product->short_description ?? '');

            // sku (guarded - duplicate SKUs throw a WC_Data_Exception)
            if (!empty($product->sku)) {
                try {
                    $obj->set_sku($product->sku);
                } catch (\WC_Data_Exception $e) {
                    $this->app->log->info('SKU skipped for '.$product->slug.' : '.$e->getMessage());
                }
            }

            // pricing
            $obj->set_regular_price((string) ($product->regular_price ?? ''));
            $obj->set_sale_price((string) ($product->sale_price ?? ''));

            if (!empty($product->date_on_sale_from)) {
                $obj->set_date_on_sale_from($product->date_on_sale_from);
            }

            if (!empty($product->date_on_sale_to)) {
                $obj->set_date_on_sale_to($product->date_on_sale_to);
            }

            // tax
            if (!empty($product->tax_status)) {
                $obj->set_tax_status($product->tax_status);
            }

            if (isset($product->tax_class)) {
                $obj->set_tax_class($product->tax_class);
            }

            // stock
            $obj->set_manage_stock((bool) ($product->manage_stock ?? false));

            if (isset($product->stock_quantity) && $product->stock_quantity !== null) {
                $obj->set_stock_quantity($product->stock_quantity);
            }

            if (!empty($product->stock_status)) {
                $obj->set_stock_status($product->stock_status);
            }

            if (!empty($product->backorders)) {
                $obj->set_backorders($product->backorders);
            }

            $obj->set_sold_individually((bool) ($product->sold_individually ?? false));

            // shipping / dimensions
            if (isset($product->weight)) {
                $obj->set_weight((string) $product->weight);
            }

            if (!empty($product->dimensions)) {
                $obj->set_length((string) ($product->dimensions->length ?? ''));
                $obj->set_width((string) ($product->dimensions->width ?? ''));
                $obj->set_height((string) ($product->dimensions->height ?? ''));
            }

            // flags
            $obj->set_virtual((bool) ($product->virtual ?? false));
            $obj->set_downloadable((bool) ($product->downloadable ?? false));

            if (isset($product->reviews_allowed)) {
                $obj->set_reviews_allowed((bool) $product->reviews_allowed);
            }

            if (isset($product->purchase_note)) {
                $obj->set_purchase_note($product->purchase_note);
            }

            if (isset($product->menu_order)) {
                $obj->set_menu_order((int) $product->menu_order);
            }

            // external / affiliate product fields
            if ($obj instanceof \WC_Product_External) {
                if (!empty($product->external_url)) {
                    $obj->set_product_url($product->external_url);
                }

                if (!empty($product->button_text)) {
                    $obj->set_button_text($product->button_text);
                }
            }

            // categories (map source term ids to local term ids)
            $category_ids = [];

            foreach ($product->categories ?? [] as $cat) {
                $tid = $this->app->db->table('termmeta')
                    ->where('meta_key', 'wp_api_prev_product_cat_id')
                    ->where('meta_value', $cat->id)
                    ->value('term_id');

                if (!empty($tid)) {
                    $category_ids[] = (int) $tid;
                }
            }

            if (!empty($category_ids)) {
                $obj->set_category_ids($category_ids);
            }

            // tags (map source term ids to local term ids)
            $tag_ids = [];

            foreach ($product->tags ?? [] as $tag) {
                $tid = $this->app->db->table('termmeta')
                    ->where('meta_key', 'wp_api_prev_product_tag_id')
                    ->where('meta_value', $tag->id)
                    ->value('term_id');

                if (!empty($tid)) {
                    $tag_ids[] = (int) $tid;
                }
            }

            if (!empty($tag_ids)) {
                $obj->set_tag_ids($tag_ids);
            }

            // images (first is featured, the rest form the gallery)
            $images = $product->images ?? [];

            if (!empty($images)) {
                $featured = $this->resolveImageId($images[0]);

                if ($featured) {
                    $obj->set_image_id($featured);
                }

                $gallery = [];

                foreach (array_slice($images, 1) as $img) {
                    $gid = $this->resolveImageId($img);

                    if ($gid) {
                        $gallery[] = $gid;
                    }
                }

                if (!empty($gallery)) {
                    $obj->set_gallery_image_ids($gallery);
                }
            }

            // attributes (treated as custom product-level attributes)
            $attributes = $this->buildProductAttributes($product->attributes ?? []);

            if (!empty($attributes)) {
                $obj->set_attributes($attributes);
            }

            // save to obtain the new product id
            $new_id = $obj->save();

            // default attributes for variable products
            if ($obj instanceof \WC_Product_Variable && !empty($product->default_attributes)) {
                $defaults = [];

                foreach ($product->default_attributes as $da) {
                    $key = !empty($da->slug) ? $da->slug : sanitize_title($da->name);
                    $defaults[$key] = $da->option;
                }

                $obj->set_default_attributes($defaults);
                $obj->save();
            }

            // id mapping used by variations, relationships and re-runs
            update_post_meta($new_id, 'wp_api_prev_product_id', $product->id);

            // defer relationships until every product exists
            if (!empty($product->upsell_ids)) {
                update_post_meta($new_id, 'wp_api_prev_upsell_ids', wp_json_encode($product->upsell_ids));
            }

            if (!empty($product->cross_sell_ids)) {
                update_post_meta($new_id, 'wp_api_prev_cross_sell_ids', wp_json_encode($product->cross_sell_ids));
            }

            if (!empty($product->grouped_products)) {
                update_post_meta($new_id, 'wp_api_prev_grouped_ids', wp_json_encode($product->grouped_products));
            }
        } catch (\Exception $e) {
            $this->app->log->info('Error creating WP product : '.$e->getMessage());
        }
    }

    /**
     * Create a single variation for a previously imported variable product.
     *
     * @param object $variation        A wc/v3 product variation object.
     * @param int    $parent_source_id The source id of the parent product.
     *
     * @return void
     */
    public function createProductVariation($variation, $parent_source_id)
    {
        if (!class_exists('WC_Product_Variation')) {
            return;
        }

        try {
            $parent_id = $this->mappedProductId($parent_source_id);

            if (empty($parent_id)) {
                $this->app->log->info('Skipping variation - parent product not found for source id : '.$parent_source_id);
                return;
            }

            // skip variations that were already imported
            $existing = $this->app->db->table('postmeta')
                ->where('meta_key', 'wp_api_prev_variation_id')
                ->where('meta_value', $variation->id)
                ->value('post_id');

            if (!empty($existing)) {
                return;
            }

            $obj = new \WC_Product_Variation();
            $obj->set_parent_id($parent_id);
            $variation_status = $variation->status ?? 'publish';
            $obj->set_status(in_array($variation_status, ['publish', 'private'], true) ? $variation_status : 'publish');

            // variation attributes (key: slug or sanitized name, value: option)
            $attrs = [];

            foreach ($variation->attributes ?? [] as $va) {
                $key = !empty($va->slug) ? $va->slug : sanitize_title($va->name);
                $attrs[$key] = $va->option;
            }

            if (!empty($attrs)) {
                $obj->set_attributes($attrs);
            }

            if (!empty($variation->sku)) {
                try {
                    $obj->set_sku($variation->sku);
                } catch (\WC_Data_Exception $e) {
                    $this->app->log->info('Variation SKU skipped : '.$e->getMessage());
                }
            }

            $obj->set_regular_price((string) ($variation->regular_price ?? ''));
            $obj->set_sale_price((string) ($variation->sale_price ?? ''));
            $obj->set_description($variation->description ?? '');

            $obj->set_manage_stock((bool) ($variation->manage_stock ?? false));

            if (isset($variation->stock_quantity) && $variation->stock_quantity !== null) {
                $obj->set_stock_quantity($variation->stock_quantity);
            }

            if (!empty($variation->stock_status)) {
                $obj->set_stock_status($variation->stock_status);
            }

            if (isset($variation->weight)) {
                $obj->set_weight((string) $variation->weight);
            }

            if (!empty($variation->dimensions)) {
                $obj->set_length((string) ($variation->dimensions->length ?? ''));
                $obj->set_width((string) ($variation->dimensions->width ?? ''));
                $obj->set_height((string) ($variation->dimensions->height ?? ''));
            }

            $obj->set_virtual((bool) ($variation->virtual ?? false));
            $obj->set_downloadable((bool) ($variation->downloadable ?? false));

            if (!empty($variation->image)) {
                $image_id = $this->resolveImageId($variation->image);

                if ($image_id) {
                    $obj->set_image_id($image_id);
                }
            }

            $new_id = $obj->save();
            update_post_meta($new_id, 'wp_api_prev_variation_id', $variation->id);
        } catch (\Exception $e) {
            $this->app->log->info('Error creating WP product variation : '.$e->getMessage());
        }
    }

    /**
     * Resolve deferred product relationships (up-sells, cross-sells and
     * grouped children) once every product has been imported, mapping the
     * stored source ids to the newly created local product ids.
     *
     * @return void
     */
    public function linkProductRelationships()
    {
        if (!class_exists('WC_Product_Simple')) {
            return;
        }

        $rows = $this->app->db->table('postmeta')
            ->where('meta_key', 'wp_api_prev_product_id')
            ->get();

        foreach ($rows as $row) {
            $local_id = $row->post_id;
            $product = \wc_get_product($local_id);

            if (!$product) {
                continue;
            }

            $changed = false;

            $upsell = get_post_meta($local_id, 'wp_api_prev_upsell_ids', true);

            if (!empty($upsell)) {
                $ids = $this->mapProductIds(json_decode($upsell, true));

                if (!empty($ids)) {
                    $product->set_upsell_ids($ids);
                    $changed = true;
                }
            }

            $cross = get_post_meta($local_id, 'wp_api_prev_cross_sell_ids', true);

            if (!empty($cross)) {
                $ids = $this->mapProductIds(json_decode($cross, true));

                if (!empty($ids)) {
                    $product->set_cross_sell_ids($ids);
                    $changed = true;
                }
            }

            if ($product instanceof \WC_Product_Grouped) {
                $grouped = get_post_meta($local_id, 'wp_api_prev_grouped_ids', true);

                if (!empty($grouped)) {
                    $ids = $this->mapProductIds(json_decode($grouped, true));

                    if (!empty($ids)) {
                        $product->set_children($ids);
                        $changed = true;
                    }
                }
            }

            if ($changed) {
                $product->save();
            }
        }
    }

    /**
     * Map an array of source product ids to their local product ids.
     *
     * @param array $source_ids
     *
     * @return array
     */
    protected function mapProductIds($source_ids)
    {
        $mapped = [];

        foreach ((array) $source_ids as $sid) {
            $nid = $this->mappedProductId($sid);

            if (!empty($nid)) {
                $mapped[] = (int) $nid;
            }
        }

        return $mapped;
    }

    /**
     * Get the local product id for a given source product id.
     *
     * @param int $source_id
     *
     * @return int|null
     */
    protected function mappedProductId($source_id)
    {
        return $this->app->db->table('postmeta')
            ->where('meta_key', 'wp_api_prev_product_id')
            ->where('meta_value', $source_id)
            ->value('post_id');
    }

    /**
     * Build an array of WC_Product_Attribute objects from the source product's
     * attributes. Attributes are imported as custom, product-level attributes
     * (global pa_* attribute taxonomies are not recreated).
     *
     * @param array $attributes
     *
     * @return array
     */
    protected function buildProductAttributes($attributes)
    {
        $result = [];

        foreach ((array) $attributes as $attr) {
            $attribute = new \WC_Product_Attribute();
            $attribute->set_id(0);
            $attribute->set_name($attr->name);
            $attribute->set_options(isset($attr->options) && is_array($attr->options) ? $attr->options : []);
            $attribute->set_position(isset($attr->position) ? (int) $attr->position : 0);
            $attribute->set_visible(isset($attr->visible) ? (bool) $attr->visible : true);
            $attribute->set_variation(isset($attr->variation) ? (bool) $attr->variation : false);

            $result[] = $attribute;
        }

        return $result;
    }

    /**
     * Resolve a WooCommerce image object to a local attachment id.
     *
     * First tries to reuse an attachment already imported by the media step
     * (matched on the source attachment id). If none is found, the image is
     * sideloaded from its source url as a fallback.
     *
     * @param object $image
     *
     * @return int
     */
    protected function resolveImageId($image)
    {
        if (empty($image)) {
            return 0;
        }

        // reuse a previously migrated attachment by source id
        if (!empty($image->id)) {
            $mapped = $this->app->db->table('postmeta')
                ->where('meta_key', 'wp_api_prev_featured_media_id')
                ->where('meta_value', $image->id)
                ->value('post_id');

            if (!empty($mapped)) {
                return (int) $mapped;
            }
        }

        // fallback: sideload the image from its source url
        if (!empty($image->src)) {
            if (!function_exists('media_sideload_image')) {
                require_once ABSPATH.'wp-admin/includes/media.php';
                require_once ABSPATH.'wp-admin/includes/file.php';
                require_once ABSPATH.'wp-admin/includes/image.php';
            }

            $id = media_sideload_image($image->src, 0, $image->alt ?? null, 'id');

            if (!is_wp_error($id)) {
                // record the mapping so re-runs and other products reuse it
                if (!empty($image->id)) {
                    update_post_meta($id, 'wp_api_prev_featured_media_id', $image->id);
                }

                return (int) $id;
            }

            $this->app->log->info('Error sideloading product image : '.$id->get_error_message());
        }

        return 0;
    }

    /**
     * Create a WooCommerce customer (a WP user with the customer role) from a
     * wc/v3 customer object.
     *
     * Passwords are NOT migrated (the REST API does not expose the hash), so
     * each account is created with a random password. Users must reset their
     * password to log in. No notification emails are sent - the caller is
     * responsible for suppressing mail during the migration run.
     *
     * @param object $customer
     *
     * @return void
     */
    public function createCustomer($customer)
    {
        try {
            // skip customers that were already imported
            if (!empty($this->mappedCustomerId($customer->id))) {
                $this->app->log->info('Customer already exists : '.$customer->email);
                return;
            }

            $login = !empty($customer->username) ? $customer->username : sanitize_user(current(explode('@', $customer->email)), true);

            // an account with this email or login may already exist locally
            // (e.g. an admin account or a previous partial run); map it and move on
            $existing = get_user_by('email', $customer->email);

            if (!$existing && !empty($login)) {
                $existing = get_user_by('login', $login);
            }

            if ($existing) {
                update_user_meta($existing->ID, 'wp_api_prev_customer_id', $customer->id);
                $this->app->log->info('User already exists, mapped only : '.$customer->email);
                return;
            }

            $userdata = [
                'user_login' => $login,
                'user_email' => $customer->email,
                'user_pass' => wp_generate_password(24, true, true),
                'first_name' => $customer->first_name ?? '',
                'last_name' => $customer->last_name ?? '',
                'display_name' => trim(($customer->first_name ?? '').' '.($customer->last_name ?? '')) ?: $login,
                'role' => !empty($customer->role) ? $customer->role : 'customer',
            ];

            if (!empty($customer->date_created_gmt)) {
                $userdata['user_registered'] = str_replace('T', ' ', $customer->date_created_gmt);
            }

            $user_id = wp_insert_user($userdata);

            if (is_wp_error($user_id)) {
                $this->app->log->info('Error creating WP customer : '.$user_id->get_error_message());
                return;
            }

            // billing / shipping address meta
            $this->importCustomerAddress($user_id, 'billing', $customer->billing ?? null);
            $this->importCustomerAddress($user_id, 'shipping', $customer->shipping ?? null);

            update_user_meta($user_id, 'wp_api_prev_customer_id', $customer->id);
        } catch (\Exception $e) {
            $this->app->log->info('Error creating WP customer : '.$e->getMessage());
        }
    }

    /**
     * Store a WooCommerce customer address (billing or shipping) as user meta.
     *
     * @param int         $user_id
     * @param string      $type    Either 'billing' or 'shipping'.
     * @param object|null $address
     *
     * @return void
     */
    protected function importCustomerAddress($user_id, $type, $address)
    {
        if (empty($address)) {
            return;
        }

        $fields = ['first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country'];

        // email and phone only exist on the billing address
        if ($type === 'billing') {
            $fields[] = 'email';
            $fields[] = 'phone';
        }

        foreach ($fields as $field) {
            if (isset($address->$field) && $address->$field !== '') {
                update_user_meta($user_id, $type.'_'.$field, $address->$field);
            }
        }
    }

    /**
     * Get the local user id for a given source customer id.
     *
     * @param int $source_id
     *
     * @return int|null
     */
    protected function mappedCustomerId($source_id)
    {
        return $this->app->db->table('usermeta')
            ->where('meta_key', 'wp_api_prev_customer_id')
            ->where('meta_value', $source_id)
            ->value('user_id');
    }

    /**
     * Whether any customers have been imported yet. Used to warn before an
     * order migration that would otherwise map every order to a guest.
     *
     * @return bool
     */
    public function hasImportedCustomers()
    {
        return $this->app->db->table('usermeta')
            ->where('meta_key', 'wp_api_prev_customer_id')
            ->exists();
    }

    /**
     * Create a WooCommerce order from a wc/v3 order object.
     *
     * Orders are historical financial records, so every total is copied
     * verbatim from the source and totals are NEVER recalculated (that would
     * re-price the order against current products/tax rates). All line types
     * (products, shipping, fees, coupons, taxes) are recreated. Referenced
     * customer and product/variation ids are remapped to their local
     * equivalents; unmapped references keep the order's snapshot data.
     *
     * Side effects (emails, stock reduction) must be suppressed by the caller
     * for the duration of the migration run.
     *
     * @param object $order
     *
     * @return void
     */
    public function createOrder($order)
    {
        if (!class_exists('WC_Order')) {
            $this->app->log->info('WooCommerce is not active - skipping order : '.($order->id ?? ''));
            return;
        }

        try {
            // skip orders that were already imported
            if (!empty($this->mappedOrderId($order->id))) {
                $this->app->log->info('Order already exists : '.$order->id);
                return;
            }

            $obj = new \WC_Order();

            // WC statuses arrive without the wc- prefix from the REST API
            if (!empty($order->status)) {
                $obj->set_status($order->status);
            }

            if (!empty($order->currency)) {
                $obj->set_currency($order->currency);
            }

            $obj->set_prices_include_tax((bool) ($order->prices_include_tax ?? false));

            // remap the source customer id to the local user (0 = guest / unmapped)
            $local_customer = !empty($order->customer_id) ? $this->mappedCustomerId($order->customer_id) : 0;
            $obj->set_customer_id((int) ($local_customer ?: 0));

            // addresses (snapshot on the order, independent of the customer record)
            $obj->set_address($this->orderAddress($order->billing ?? null, true), 'billing');
            $obj->set_address($this->orderAddress($order->shipping ?? null, false), 'shipping');

            // payment / misc
            if (isset($order->payment_method)) {
                $obj->set_payment_method($order->payment_method);
            }

            if (isset($order->payment_method_title)) {
                $obj->set_payment_method_title($order->payment_method_title);
            }

            if (!empty($order->transaction_id)) {
                $obj->set_transaction_id($order->transaction_id);
            }

            if (isset($order->customer_note)) {
                $obj->set_customer_note($order->customer_note);
            }

            if (!empty($order->created_via)) {
                $obj->set_created_via($order->created_via);
            }

            if (!empty($order->customer_ip_address)) {
                $obj->set_customer_ip_address($order->customer_ip_address);
            }

            // dates: use the GMT values (parsed as UTC) to stay timezone-safe
            if (!empty($order->date_created_gmt)) {
                $obj->set_date_created($this->gmtToTimestamp($order->date_created_gmt));
            }

            if (!empty($order->date_paid_gmt)) {
                $obj->set_date_paid($this->gmtToTimestamp($order->date_paid_gmt));
            }

            if (!empty($order->date_completed_gmt)) {
                $obj->set_date_completed($this->gmtToTimestamp($order->date_completed_gmt));
            }

            // product / variation line items
            foreach ($order->line_items ?? [] as $line) {
                $item = new \WC_Order_Item_Product();
                $item->set_name($line->name ?? '');
                $item->set_quantity($line->quantity ?? 1);

                if (!empty($line->product_id)) {
                    $pid = $this->mappedProductId($line->product_id);

                    if (!empty($pid)) {
                        $item->set_product_id((int) $pid);
                    }
                }

                if (!empty($line->variation_id)) {
                    $vid = $this->mappedVariationId($line->variation_id);

                    if (!empty($vid)) {
                        $item->set_variation_id((int) $vid);
                    }
                }

                $item->set_subtotal((string) ($line->subtotal ?? '0'));
                $item->set_total((string) ($line->total ?? '0'));
                $item->set_subtotal_tax((string) ($line->subtotal_tax ?? '0'));
                $item->set_total_tax((string) ($line->total_tax ?? '0'));
                $item->set_taxes($this->orderItemTaxes($line->taxes ?? [], true));

                // preserve custom line meta (e.g. displayed variation attributes)
                foreach ($line->meta_data ?? [] as $meta) {
                    if (isset($meta->key) && strpos($meta->key, '_') !== 0) {
                        $item->add_meta_data($meta->key, $meta->value, true);
                    }
                }

                $obj->add_item($item);
            }

            // shipping lines
            foreach ($order->shipping_lines ?? [] as $line) {
                $item = new \WC_Order_Item_Shipping();
                $item->set_method_title($line->method_title ?? '');

                if (!empty($line->method_id)) {
                    $item->set_method_id($line->method_id);
                }

                if (isset($line->instance_id) && $line->instance_id !== '') {
                    $item->set_instance_id($line->instance_id);
                }

                $item->set_total((string) ($line->total ?? '0'));
                $item->set_taxes($this->orderItemTaxes($line->taxes ?? [], false));
                $obj->add_item($item);
            }

            // fee lines
            foreach ($order->fee_lines ?? [] as $line) {
                $item = new \WC_Order_Item_Fee();
                $item->set_name($line->name ?? '');

                if (isset($line->tax_status)) {
                    $item->set_tax_status($line->tax_status);
                }

                if (isset($line->tax_class)) {
                    $item->set_tax_class($line->tax_class);
                }

                if (isset($line->amount)) {
                    $item->set_amount((string) $line->amount);
                }

                $item->set_total((string) ($line->total ?? '0'));
                $item->set_total_tax((string) ($line->total_tax ?? '0'));
                $item->set_taxes($this->orderItemTaxes($line->taxes ?? [], false));
                $obj->add_item($item);
            }

            // coupon lines
            foreach ($order->coupon_lines ?? [] as $line) {
                $item = new \WC_Order_Item_Coupon();
                $item->set_code($line->code ?? '');
                $item->set_discount((string) ($line->discount ?? '0'));
                $item->set_discount_tax((string) ($line->discount_tax ?? '0'));
                $obj->add_item($item);
            }

            // tax lines
            foreach ($order->tax_lines ?? [] as $line) {
                $item = new \WC_Order_Item_Tax();

                if (isset($line->rate_id)) {
                    $item->set_rate_id($line->rate_id);
                }

                $item->set_rate_code($line->rate_code ?? '');
                $item->set_label($line->label ?? '');
                $item->set_compound((bool) ($line->compound ?? false));
                $item->set_tax_total((string) ($line->tax_total ?? '0'));
                $item->set_shipping_tax_total((string) ($line->shipping_tax_total ?? '0'));

                if (isset($line->rate_percent)) {
                    $item->set_rate_percent((float) $line->rate_percent);
                }

                $obj->add_item($item);
            }

            // order-level totals - copied verbatim, never recalculated.
            // total_tax is derived by WC from cart_tax + shipping_tax.
            $obj->set_discount_total((string) ($order->discount_total ?? '0'));
            $obj->set_discount_tax((string) ($order->discount_tax ?? '0'));
            $obj->set_shipping_total((string) ($order->shipping_total ?? '0'));
            $obj->set_shipping_tax((string) ($order->shipping_tax ?? '0'));
            $obj->set_cart_tax((string) ($order->cart_tax ?? '0'));
            $obj->set_total((string) ($order->total ?? '0'));

            // preserve custom order meta (skip WC-managed underscore keys)
            foreach ($order->meta_data ?? [] as $meta) {
                if (isset($meta->key) && strpos($meta->key, '_') !== 0) {
                    $obj->update_meta_data($meta->key, $meta->value);
                }
            }

            // id mapping stored via CRUD so it lands in the right table under HPOS
            $obj->update_meta_data('wp_api_prev_order_id', $order->id);

            $obj->save();
        } catch (\Exception $e) {
            $this->app->log->info('Error creating WP order : '.$e->getMessage());
        }
    }

    /**
     * Build a WooCommerce address array (billing or shipping) from a wc/v3
     * order address object.
     *
     * @param object|null $address
     * @param bool        $isBilling Whether to include email/phone (billing only).
     *
     * @return array
     */
    protected function orderAddress($address, $isBilling)
    {
        if (empty($address)) {
            return [];
        }

        $fields = ['first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country'];

        if ($isBilling) {
            $fields[] = 'email';
            $fields[] = 'phone';
        }

        $result = [];

        foreach ($fields as $field) {
            if (isset($address->$field)) {
                $result[$field] = $address->$field;
            }
        }

        return $result;
    }

    /**
     * Convert a wc/v3 order line/shipping/fee tax array into the structure
     * expected by WC_Order_Item::set_taxes().
     *
     * @param array $taxes
     * @param bool  $withSubtotal Whether the item tracks a subtotal (line items do).
     *
     * @return array
     */
    protected function orderItemTaxes($taxes, $withSubtotal)
    {
        $total = [];
        $subtotal = [];

        foreach ((array) $taxes as $tax) {
            if (!isset($tax->id)) {
                continue;
            }

            $total[$tax->id] = $tax->total ?? 0;

            if ($withSubtotal) {
                $subtotal[$tax->id] = $tax->subtotal ?? ($tax->total ?? 0);
            }
        }

        $result = ['total' => $total];

        if ($withSubtotal) {
            $result['subtotal'] = $subtotal;
        }

        return $result;
    }

    /**
     * Parse a GMT datetime string (as returned by the REST API, without a
     * timezone) into a unix timestamp.
     *
     * @param string $value
     *
     * @return int|null
     */
    protected function gmtToTimestamp($value)
    {
        if (empty($value)) {
            return null;
        }

        $timestamp = strtotime($value.' UTC');

        return $timestamp ?: null;
    }

    /**
     * Get the local variation id for a given source variation id.
     *
     * @param int $source_id
     *
     * @return int|null
     */
    protected function mappedVariationId($source_id)
    {
        return $this->app->db->table('postmeta')
            ->where('meta_key', 'wp_api_prev_variation_id')
            ->where('meta_value', $source_id)
            ->value('post_id');
    }

    /**
     * Whether WooCommerce is using the High-Performance Order Storage (HPOS)
     * custom tables as the authoritative order datastore.
     *
     * @return bool
     */
    protected function ordersUseHpos()
    {
        return class_exists(\Automattic\WooCommerce\Utilities\OrderUtil::class)
            && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
    }

    /**
     * Get the local order id for a given source order id.
     *
     * Queries the order meta table directly rather than wc_get_orders(), whose
     * meta_query argument is rejected by the HPOS datastore ("... is not
     * supported on the current order datastore"). Order meta lives in
     * wc_orders_meta under HPOS and in postmeta under the legacy CPT store.
     *
     * @param int $source_id
     *
     * @return int|null
     */
    protected function mappedOrderId($source_id)
    {
        if ($this->ordersUseHpos()) {
            return $this->app->db->table('wc_orders_meta')
                ->where('meta_key', 'wp_api_prev_order_id')
                ->where('meta_value', $source_id)
                ->value('order_id');
        }

        return $this->app->db->table('postmeta')
            ->where('meta_key', 'wp_api_prev_order_id')
            ->where('meta_value', $source_id)
            ->value('post_id');
    }
}