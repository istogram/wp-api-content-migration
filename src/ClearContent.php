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
     * Clear WP comments. This will delete all comments and their metadata.
     *
     * @return void
     */
    public function clearComments()
    {
        try {
            // delete all comments
            $comments = get_comments([
                'number' => 0,
            ]);

            foreach ($comments as $comment) {
                wp_delete_comment($comment->comment_ID, true);
            }

            // delete all comment metadata
            $this->clearImportedMeta('comment');

            return 'Comments cleared';
        } catch (\Exception $e) {
            return $e->getMessage();
        }
    }

    /**
     * Clear WooCommerce products and variations, including their imported meta.
     *
     * @return void
     */
    public function clearProducts()
    {
        try {
            // delete all products and variations
            $products = get_posts([
                'post_type' => ['product', 'product_variation'],
                'numberposts' => -1,
                'post_status' => null,
            ]);

            foreach ($products as $product) {
                wp_delete_post($product->ID, true);
            }

            // delete all imported product metadata
            $this->clearImportedMeta('product');
            $this->clearImportedMeta('product_variation');

            return 'Products cleared';
        } catch (\Exception $e) {
            return $e->getMessage();
        }
    }

    /**
     * Clear WooCommerce product taxonomies (product categories and tags),
     * including their imported meta.
     *
     * @return void
     */
    public function clearProductTaxonomies()
    {
        try {
            foreach (['product_cat', 'product_tag'] as $taxonomy) {
                $terms = get_terms([
                    'taxonomy' => $taxonomy,
                    'hide_empty' => false,
                ]);

                if (is_wp_error($terms)) {
                    continue;
                }

                foreach ($terms as $term) {
                    wp_delete_term($term->term_id, $taxonomy);
                }
            }

            // delete all imported product taxonomy metadata
            $this->clearImportedMeta('product_cat');
            $this->clearImportedMeta('product_tag');

            return 'Product taxonomies cleared';
        } catch (\Exception $e) {
            return $e->getMessage();
        }
    }

    /**
     * Clear WooCommerce customers (users imported by the migration), including
     * their imported meta. Only users carrying the wp_api_prev_customer_id meta
     * are removed, so pre-existing local accounts (e.g. admins) are left intact.
     *
     * @return void
     */
    public function clearCustomers()
    {
        try {
            if (!function_exists('wp_delete_user')) {
                require_once ABSPATH.'wp-admin/includes/user.php';
            }

            $user_ids = $this->app->db->table('usermeta')
                ->where('meta_key', 'wp_api_prev_customer_id')
                ->pluck('user_id');

            foreach ($user_ids as $user_id) {
                wp_delete_user($user_id);
            }

            // delete all imported customer metadata
            $this->clearImportedMeta('customer');

            return 'Customers cleared';
        } catch (\Exception $e) {
            return $e->getMessage();
        }
    }

    /**
     * Clear WooCommerce orders that were imported by the migration.
     *
     * Only orders carrying the wp_api_prev_order_id meta are removed, so any
     * orders created locally are left intact. Uses wc_get_orders()/delete() so
     * it works with both post-based and HPOS order storage.
     *
     * @return void
     */
    public function clearOrders()
    {
        try {
            if (!function_exists('wc_get_orders')) {
                return 'WooCommerce not active - orders not cleared';
            }

            // Find imported orders by their mapping meta directly: wc_get_orders'
            // meta_query argument is rejected by the HPOS datastore. Order meta
            // lives in wc_orders_meta under HPOS and in postmeta under the CPT store.
            if ($this->ordersUseHpos()) {
                $order_ids = $this->app->db->table('wc_orders_meta')
                    ->where('meta_key', 'wp_api_prev_order_id')
                    ->pluck('order_id');
            } else {
                $order_ids = $this->app->db->table('postmeta')
                    ->where('meta_key', 'wp_api_prev_order_id')
                    ->pluck('post_id');
            }

            foreach ($order_ids as $order_id) {
                $order = wc_get_order($order_id);

                if ($order) {
                    $order->delete(true);
                }
            }

            // remove any legacy postmeta mapping (HPOS meta is dropped with the order)
            $this->clearImportedMeta('order');

            return 'Orders cleared';
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
            case 'comment':
                $this->app->db->table('commentmeta')->where('meta_key', 'wp_api_prev_comment_id')->delete();
                break;
            case 'product':
                $this->app->db->table('postmeta')->where('meta_key', 'wp_api_prev_product_id')->delete();
                $this->app->db->table('postmeta')->where('meta_key', 'wp_api_prev_upsell_ids')->delete();
                $this->app->db->table('postmeta')->where('meta_key', 'wp_api_prev_cross_sell_ids')->delete();
                $this->app->db->table('postmeta')->where('meta_key', 'wp_api_prev_grouped_ids')->delete();
                break;
            case 'product_variation':
                $this->app->db->table('postmeta')->where('meta_key', 'wp_api_prev_variation_id')->delete();
                break;
            case 'product_cat':
                $this->app->db->table('termmeta')->where('meta_key', 'wp_api_prev_product_cat_id')->delete();
                break;
            case 'product_tag':
                $this->app->db->table('termmeta')->where('meta_key', 'wp_api_prev_product_tag_id')->delete();
                break;
            case 'customer':
                $this->app->db->table('usermeta')->where('meta_key', 'wp_api_prev_customer_id')->delete();
                break;
            case 'order':
                // Order meta lives in wc_orders_meta under HPOS, in postmeta under the CPT store.
                if ($this->ordersUseHpos()) {
                    $this->app->db->table('wc_orders_meta')->where('meta_key', 'wp_api_prev_order_id')->delete();
                } else {
                    $this->app->db->table('postmeta')->where('meta_key', 'wp_api_prev_order_id')->delete();
                }
                break;
        }
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
}