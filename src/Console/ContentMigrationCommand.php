<?php

namespace istogram\WpApiContentMigration\Console;

use istogram\WpApiContentMigration\Facades\ClearContent;
use istogram\WpApiContentMigration\Facades\ContentMigration;
use Roots\Acorn\Console\Commands\Command;

class ContentMigrationCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'migrate:content {domain?} {--clear-all} {--migrate-all}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Migrate WP content using the WP REST API';

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle()
    {
        // if argument is missing, ask for domain
        if (!$this->argument('domain')) {
            $this->argument('domain', $this->ask('What is the domain of the WP site?'));
        }

        switch ($this->option('clear-all')) {
            case true:
                if ($this->confirm('Do you want to clear all content?')) {
                    $this->info('Clearing all content');
                    $this->clearTaxonomies();
                    $this->clearMedia();
                    $this->clearPosts();
                    $this->clearPages();
                    $this->clearComments();

                    if (config('content-migration.woocommerce.enabled')) {
                        $this->clearOrders();
                        $this->clearProductTaxonomies();
                        $this->clearProducts();
                        $this->clearCustomers();
                    }
                }
                break;
            case false:
                $this->info('Clearing content');
                $this->confirmClear('taxonomies') ? $this->clearTaxonomies() : null;
                $this->confirmClear('media') ? $this->clearMedia() : null;
                $this->confirmClear('posts') ? $this->clearPosts() : null;
                $this->confirmClear('pages') ? $this->clearPages() : null;
                $this->confirmClear('comments') ? $this->clearComments() : null;

                if (config('content-migration.woocommerce.enabled')) {
                    $this->confirmClear('orders') ? $this->clearOrders() : null;
                    $this->confirmClear('product taxonomies') ? $this->clearProductTaxonomies() : null;
                    $this->confirmClear('products') ? $this->clearProducts() : null;
                    $this->confirmClear('customers') ? $this->clearCustomers() : null;
                }
                break;
        }

        switch ($this->option('migrate-all')) {
            case true:
                $this->info('Migrating all content');
                $this->migrateCategories();
                $this->migrateTags();
                $this->migrateMedia();
                $this->migratePosts();
                $this->migratePages();
                $this->migrateComments();

                if (config('content-migration.woocommerce.enabled')) {
                    $this->migrateProductCategories();
                    $this->migrateProductTags();
                    $this->migrateProducts();
                    $this->migrateCustomers();
                    $this->migrateOrders();
                }

                $this->clearImportedMeta();
                break;
            case false:
                $this->info('Migrating content');
                $this->confirmMigrate('categories') ? $this->migrateCategories() : null;
                $this->confirmMigrate('tags') ? $this->migrateTags() : null;
                $this->confirmMigrate('media') ? $this->migrateMedia() : null;
                $this->confirmMigrate('posts') ? $this->migratePosts() : null;
                $this->confirmMigrate('pages') ? $this->migratePages() : null;
                $this->confirmMigrate('comments') ? $this->migrateComments() : null;

                if (config('content-migration.woocommerce.enabled')) {
                    $this->confirmMigrate('product categories') ? $this->migrateProductCategories() : null;
                    $this->confirmMigrate('product tags') ? $this->migrateProductTags() : null;
                    $this->confirmMigrate('products') ? $this->migrateProducts() : null;
                    $this->confirmMigrate('customers') ? $this->migrateCustomers() : null;
                    $this->confirmMigrate('orders') ? $this->migrateOrders() : null;
                }
                break;
        }
    }

    /**
     * Clear taxonomies (categories and tags). This will delete all categories and tags.
     *
     * @return void
     */
    public function clearTaxonomies()
    {
        $response = ClearContent::clearTaxonomies();

        return $this->info($response);
    }

    /**
     * Clear media (attachments). This will delete all media files and their metadata.
     *
     * @return void
     */
    public function clearMedia()
    {
        $response = ClearContent::clearMedia();

        $this->info($response);
    }

    /**
     * Clear posts (articles). This will delete all posts and their metadata.
     *
     * @return void
     */
    public function clearPosts()
    {
        $response = ClearContent::clearPosts();

        $this->info($response);
    }

    /**
     * Clear pages (static pages). This will delete all pages and their metadata.
     *
     * @return void
     */
    public function clearPages()
    {
        $response = ClearContent::clearPages();

        $this->info($response);
    }

    /**
     * Clear comments. This will delete all comments and their metadata.
     *
     * @return void
     */
    public function clearComments()
    {
        $response = ClearContent::clearComments();

        $this->info($response);
    }

    /**
     * Clear WooCommerce products and variations.
     *
     * @return void
     */
    public function clearProducts()
    {
        $response = ClearContent::clearProducts();

        $this->info($response);
    }

    /**
     * Clear WooCommerce product taxonomies (categories and tags).
     *
     * @return void
     */
    public function clearProductTaxonomies()
    {
        $response = ClearContent::clearProductTaxonomies();

        $this->info($response);
    }

    /**
     * Clear WooCommerce customers (imported users).
     *
     * @return void
     */
    public function clearCustomers()
    {
        $response = ClearContent::clearCustomers();

        $this->info($response);
    }

    /**
     * Clear WooCommerce orders (imported orders).
     *
     * @return void
     */
    public function clearOrders()
    {
        $response = ClearContent::clearOrders();

        $this->info($response);
    }

    /**
     * Clear imported meta data.
     *
     * @return void
     */
    public function clearImportedMeta()
    {
        $types = ['category', 'tag', 'featured_media', 'post', 'page', 'comment', 'product', 'product_variation', 'product_cat', 'product_tag', 'customer', 'order'];

        foreach ($types as $type) {
            ClearContent::clearImportedMeta($type);
        }

        $this->info('Cleared imported meta');
    }

    /**
     * Confirm clear data.
     *
     * @param string $type
     *
     * @return void
     */
    public function confirmClear($type)
    {
        // check if user wants to clear data
        $confirm = $this->confirm("Are you sure you want to clear all $type?");

        if ($confirm) {
            return true;
        }
    }

    /**
     * Confirm migrate data.
     *
     * @param string $type
     *
     * @return void
     */
    public function confirmMigrate($type)
    {
        // check if user wants to migrate data
        $confirm = $this->confirm("Are you sure you want to migrate all $type?");

        if ($confirm) {
            return true;
        }
    }

    /**
     * Fetch data from WP API. This method is used to fetch data from WP API.
     *
     * @param string $endpoint
     *
     * @return void
     */
    public function fetchData($endpoint, $args = [])
    {
        $response = wp_remote_get($endpoint, array_merge([
            'timeout' => 30,
        ], $args));

        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $this->error("Error: $error_message");

            return [];
        }

        return json_decode(wp_remote_retrieve_body($response)) ?? [];
    }

    /**
     * Fetch total pages from WP API. This method is used to fetch the total number of pages from WP API.
     *
     * @param string $endpoint
     *
     * @return int
     */
    public function fetchTotalPages($endpoint, $args = [])
    {
        $response = wp_remote_get($endpoint, array_merge([
            'timeout' => 30,
        ], $args));

        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $this->error("Error: $error_message");

            return 0;
        }

        return (int) wp_remote_retrieve_header($response, 'X-WP-TotalPages');
    }

    /**
     * Fetch total items count from WP API.
     *
     * @param string $endpoint
     *
     * @return int
     */
    public function fetchTotalItems($endpoint, $args = [])
    {
        $response = wp_remote_get($endpoint, array_merge([
            'timeout' => 30,
        ], $args));

        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $this->error("Error: $error_message");

            return 0;
        }

        return (int) wp_remote_retrieve_header($response, 'X-WP-Total');
    }

    /**
     * Fetch page data from WP API. This method is used to fetch data from a specific page.
     *
     * @param string $endpoint
     * @param int    $page
     *
     * @return void
     */
    public function fetchPageData($endpoint, $page, $args = [])
    {
        $response = wp_remote_get(add_query_arg('page', $page, $endpoint), array_merge([
            'timeout' => 30,
        ], $args));

        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $this->error("Error: $error_message");

            return [];
        }

        return json_decode(wp_remote_retrieve_body($response)) ?? [];
    }

    /**
     * Migrate categories. This method is used to migrate categories from WP API.
     *
     * @return void
     */
    public function migrateCategories()
    {
        $this->info('Migrating WP categories');
        $this->line('');

        // set categories endpoint
        $categories_endpoint = $this->argument('domain').'/wp-json/wp/v2/categories';

        // get total pages and items
        $total_pages = $this->fetchTotalPages($categories_endpoint);
        $total_items = $this->fetchTotalItems($categories_endpoint);

        // create progress bar based on total items
        $progressBar = $this->output->createProgressBar($total_items);

        // set progress bar format
        $progressBar->setFormat(config('content-migration.progress_bar_format'));

        // loop through all pages
        for ($page = 1; $page <= $total_pages; ++$page) {
            // get categories
            $categories = $this->fetchPageData($categories_endpoint, $page);

            // filter parent categories
            $parent_categories = array_filter($categories, function ($category) {
                return $category->parent === 0;
            });

            // create parent categories
            foreach ($parent_categories as $category) {
                ContentMigration::createCategory($category);
                $progressBar->advance();
            }

            // filter child categories
            $child_categories = array_filter($categories, function ($category) {
                return $category->parent !== 0;
            });

            // create child categories
            foreach ($child_categories as $category) {
                ContentMigration::createCategory($category);
                $progressBar->advance();
            }
        }

        $progressBar->finish();
        $this->printFormattedEndMessage('Migrated categories');
    }

    /**
     * Migrate tags. This method is used to migrate tags from WP API.
     *
     * @return void
     */
    public function migrateTags()
    {
        $this->info('Migrating tags');
        $this->line('');

        // get tags endpoint
        $tags_endpoint = $this->argument('domain').'/wp-json/wp/v2/tags';

        // get total pages and items
        $total_pages = $this->fetchTotalPages($tags_endpoint);
        $total_items = $this->fetchTotalItems($tags_endpoint);

        // create progress bar based on total items
        $progressBar = $this->output->createProgressBar($total_items);

        // set progress bar format
        $progressBar->setFormat(config('content-migration.progress_bar_format'));

        // loop through all pages
        for ($page = 1; $page <= $total_pages; ++$page) {
            $tags = $this->fetchPageData($tags_endpoint, $page);

            // create tags
            foreach ($tags as $tag) {
                ContentMigration::createTag($tag);
                $progressBar->advance();
            }
        }

        $progressBar->finish();
        $this->printFormattedEndMessage('Migrated tags');
    }

    /**
     * Migrate media. This method is used to migrate media from WP API.
     *
     * @return void
     */
    public function migrateMedia()
    {
        $this->info('Migrating media');
        $this->line('');

        // set media endpoint
        $media_endpoint = $this->argument('domain').'/wp-json/wp/v2/media';

        // get total pages and items
        $total_pages = $this->fetchTotalPages($media_endpoint);
        $total_items = $this->fetchTotalItems($media_endpoint);

        // create progress bar based on total items
        $progressBar = $this->output->createProgressBar($total_items);

        // set progress bar format
        $progressBar->setFormat(config('content-migration.progress_bar_format'));

        // loop through all pages
        for ($page = 1; $page <= $total_pages; ++$page) {
            $media = $this->fetchPageData($media_endpoint, $page);

            // create media
            foreach ($media as $medium) {
                ContentMigration::createMedia($medium);
                $progressBar->advance();
            }
        }

        $progressBar->finish();
        $this->printFormattedEndMessage('Migrated media');
    }

    /**
     * Migrate posts. This method is used to migrate posts from WP API.
     *
     * @return void
     */
    public function migratePosts()
    {
        $this->info('Migrating posts');
        $this->line('');

        // set posts endpoint
        $posts_endpoint = $this->argument('domain').'/wp-json/wp/v2/posts';

        // get total pages and items
        $total_pages = $this->fetchTotalPages($posts_endpoint);
        $total_items = $this->fetchTotalItems($posts_endpoint);

        // create progress bar based on total items
        $progressBar = $this->output->createProgressBar($total_items);

        // set progress bar format
        $progressBar->setFormat(config('content-migration.progress_bar_format'));

        // loop through all pages
        for ($page = 1; $page <= $total_pages; ++$page) {
            $posts = $this->fetchPageData($posts_endpoint, $page);

            // create posts
            foreach ($posts as $post) {
                ContentMigration::createPost($post);
                $progressBar->advance();
            }
        }

        $progressBar->finish();
        $this->printFormattedEndMessage('Migrated posts');
    }

    /**
     * Migrate pages. This method is used to migrate pages from WP API.
     *
     * @return void
     */
    public function migratePages()
    {
        $this->info('Migrating pages');
        $this->line('');

        // set endpoint
        $pages_endpoint = $this->argument('domain').'/wp-json/wp/v2/pages';

        // get total pages and items
        $total_pages = $this->fetchTotalPages($pages_endpoint);
        $total_items = $this->fetchTotalItems($pages_endpoint);

        // create progress bar based on total items
        $progressBar = $this->output->createProgressBar($total_items);

        // set progress bar format
        $progressBar->setFormat(config('content-migration.progress_bar_format'));

        // loop through all pages
        for ($page = 1; $page <= $total_pages; ++$page) {
            $pages = $this->fetchPageData($pages_endpoint, $page);

            // filter parent pages
            $parent_pages = array_filter($pages, function ($page) {
                return $page->parent === 0;
            });

            // create parent pages
            foreach ($parent_pages as $pageToMigrate) {
                ContentMigration::createPage($pageToMigrate);
                $progressBar->advance();
            }

            // filter child pages
            $child_pages = array_filter($pages, function ($page) {
                return $page->parent !== 0;
            });

            // create child pages
            foreach ($child_pages as $pageToMigrate) {
                ContentMigration::createPage($pageToMigrate);
                $progressBar->advance();
            }
        }

        $progressBar->finish();
        $this->printFormattedEndMessage('Migrated pages');
    }

    /**
     * Migrate comments. This method is used to migrate approved comments from WP API.
     *
     * @return void
     */
    public function migrateComments()
    {
        $this->info('Migrating comments');
        $this->line('');

        // set comments endpoint (only approved comments are returned by default for unauthenticated requests)
        $comments_endpoint = $this->argument('domain').'/wp-json/wp/v2/comments';

        // get total pages and items
        $total_pages = $this->fetchTotalPages($comments_endpoint);
        $total_items = $this->fetchTotalItems($comments_endpoint);

        // create progress bar based on total items
        $progressBar = $this->output->createProgressBar($total_items);

        // set progress bar format
        $progressBar->setFormat(config('content-migration.progress_bar_format'));

        // loop through all pages
        for ($page = 1; $page <= $total_pages; ++$page) {
            $comments = $this->fetchPageData($comments_endpoint, $page);

            // filter root comments (no parent)
            $root_comments = array_filter($comments, function ($comment) {
                return $comment->parent === 0;
            });

            // create root comments first
            foreach ($root_comments as $comment) {
                ContentMigration::createComment($comment);
                $progressBar->advance();
            }

            // filter child comments (have parent)
            $child_comments = array_filter($comments, function ($comment) {
                return $comment->parent !== 0;
            });

            // create child comments
            foreach ($child_comments as $comment) {
                ContentMigration::createComment($comment);
                $progressBar->advance();
            }
        }

        $progressBar->finish();
        $this->printFormattedEndMessage('Migrated comments');
    }

    /**
     * Determine whether WooCommerce product migration is enabled and the
     * source REST API credentials are configured.
     *
     * @return bool
     */
    public function wooEnabled()
    {
        if (!config('content-migration.woocommerce.enabled')) {
            return false;
        }

        $key = config('content-migration.woocommerce.consumer_key');
        $secret = config('content-migration.woocommerce.consumer_secret');

        if (empty($key) || empty($secret)) {
            $this->warn('WooCommerce migration skipped: consumer key/secret not configured.');

            return false;
        }

        return true;
    }

    /**
     * Build a WooCommerce REST API (wc/v3) endpoint url, including the per_page
     * parameter and, when configured, query-string authentication.
     *
     * @param string $path
     *
     * @return string
     */
    public function wooEndpoint($path)
    {
        $per_page = (int) config('content-migration.woocommerce.per_page', 100);

        $endpoint = $this->argument('domain').'/wp-json/wc/v3/'.ltrim($path, '/');
        $endpoint = add_query_arg('per_page', $per_page, $endpoint);

        // fall back to query-string auth when the server strips Authorization headers
        if (config('content-migration.woocommerce.query_string_auth')) {
            $endpoint = add_query_arg([
                'consumer_key' => config('content-migration.woocommerce.consumer_key'),
                'consumer_secret' => config('content-migration.woocommerce.consumer_secret'),
            ], $endpoint);
        }

        return $endpoint;
    }

    /**
     * Build the request args (HTTP Basic Auth header) for WooCommerce API calls.
     *
     * @return array
     */
    public function wooArgs()
    {
        // when using query-string auth, credentials travel in the url instead
        if (config('content-migration.woocommerce.query_string_auth')) {
            return [];
        }

        $key = config('content-migration.woocommerce.consumer_key');
        $secret = config('content-migration.woocommerce.consumer_secret');

        return [
            'headers' => [
                'Authorization' => 'Basic '.base64_encode($key.':'.$secret),
            ],
        ];
    }

    /**
     * Verify the WooCommerce REST API is reachable and the credentials are
     * accepted before attempting a migration step.
     *
     * A bad key/secret returns an authentication error object (rather than a
     * transport-level WP_Error), which would otherwise leave the total counts
     * at 0 and the step would appear to "succeed" while importing nothing. This
     * makes that failure explicit.
     *
     * @param string $endpoint
     * @param array  $args
     *
     * @return bool
     */
    public function wooReachable($endpoint, $args = [])
    {
        $response = wp_remote_get($endpoint, array_merge([
            'timeout' => 30,
        ], $args));

        if (is_wp_error($response)) {
            $this->error('WooCommerce migration skipped: '.$response->get_error_message());

            return false;
        }

        $code = (int) wp_remote_retrieve_response_code($response);

        if ($code < 200 || $code >= 300) {
            $body = json_decode(wp_remote_retrieve_body($response));
            $message = $body->message ?? wp_remote_retrieve_response_message($response);

            $this->error('WooCommerce migration skipped (HTTP '.$code.'): '.$message);

            return false;
        }

        return true;
    }

    /**
     * Migrate WooCommerce product categories from the WC REST API.
     *
     * @return void
     */
    public function migrateProductCategories()
    {
        if (!$this->wooEnabled()) {
            return;
        }

        $endpoint = $this->wooEndpoint('products/categories');
        $args = $this->wooArgs();

        if (!$this->wooReachable($endpoint, $args)) {
            return;
        }

        $this->info('Migrating product categories');
        $this->line('');

        $total_pages = $this->fetchTotalPages($endpoint, $args);
        $total_items = $this->fetchTotalItems($endpoint, $args);

        $progressBar = $this->output->createProgressBar($total_items);
        $progressBar->setFormat(config('content-migration.progress_bar_format'));

        // NOTE: parent resolution here is best-effort and only handles a single
        // level of nesting within a single page. Top-level categories are created
        // before children so a child can resolve its parent from the imported meta.
        // This does NOT correctly handle deeper hierarchies (a grandchild may be
        // processed before its child-parent) or categories whose parent lives on a
        // later API page (>100 categories). A fully correct implementation would
        // fetch every page first and topologically sort by depth before inserting.
        for ($page = 1; $page <= $total_pages; ++$page) {
            $categories = $this->fetchPageData($endpoint, $page, $args);

            // create parent categories first so children can resolve their parent
            $parents = array_filter($categories, function ($category) {
                return ($category->parent ?? 0) === 0;
            });

            foreach ($parents as $category) {
                ContentMigration::createProductCategory($category);
                $progressBar->advance();
            }

            $children = array_filter($categories, function ($category) {
                return ($category->parent ?? 0) !== 0;
            });

            foreach ($children as $category) {
                ContentMigration::createProductCategory($category);
                $progressBar->advance();
            }
        }

        $progressBar->finish();
        $this->printFormattedEndMessage('Migrated product categories');
    }

    /**
     * Migrate WooCommerce product tags from the WC REST API.
     *
     * @return void
     */
    public function migrateProductTags()
    {
        if (!$this->wooEnabled()) {
            return;
        }

        $endpoint = $this->wooEndpoint('products/tags');
        $args = $this->wooArgs();

        if (!$this->wooReachable($endpoint, $args)) {
            return;
        }

        $this->info('Migrating product tags');
        $this->line('');

        $total_pages = $this->fetchTotalPages($endpoint, $args);
        $total_items = $this->fetchTotalItems($endpoint, $args);

        $progressBar = $this->output->createProgressBar($total_items);
        $progressBar->setFormat(config('content-migration.progress_bar_format'));

        for ($page = 1; $page <= $total_pages; ++$page) {
            $tags = $this->fetchPageData($endpoint, $page, $args);

            foreach ($tags as $tag) {
                ContentMigration::createProductTag($tag);
                $progressBar->advance();
            }
        }

        $progressBar->finish();
        $this->printFormattedEndMessage('Migrated product tags');
    }

    /**
     * Migrate WooCommerce products (and their variations) from the WC REST API.
     * Product relationships are resolved after every product has been created.
     *
     * @return void
     */
    public function migrateProducts()
    {
        if (!$this->wooEnabled()) {
            return;
        }

        $endpoint = $this->wooEndpoint('products');
        $args = $this->wooArgs();

        if (!$this->wooReachable($endpoint, $args)) {
            return;
        }

        $this->info('Migrating products');
        $this->line('');

        $total_pages = $this->fetchTotalPages($endpoint, $args);
        $total_items = $this->fetchTotalItems($endpoint, $args);

        $progressBar = $this->output->createProgressBar($total_items);
        $progressBar->setFormat(config('content-migration.progress_bar_format'));

        for ($page = 1; $page <= $total_pages; ++$page) {
            $products = $this->fetchPageData($endpoint, $page, $args);

            foreach ($products as $product) {
                ContentMigration::createProduct($product);

                // import variations for variable products
                if (($product->type ?? '') === 'variable') {
                    $this->migrateProductVariations($product->id);
                }

                $progressBar->advance();
            }
        }

        $progressBar->finish();

        // resolve up-sells, cross-sells and grouped children now that all products exist
        ContentMigration::linkProductRelationships();

        $this->printFormattedEndMessage('Migrated products');
    }

    /**
     * Migrate the variations of a single variable product.
     *
     * @param int $parent_source_id The source id of the parent product.
     *
     * @return void
     */
    public function migrateProductVariations($parent_source_id)
    {
        $endpoint = $this->wooEndpoint('products/'.$parent_source_id.'/variations');
        $args = $this->wooArgs();

        $total_pages = $this->fetchTotalPages($endpoint, $args);

        for ($page = 1; $page <= max($total_pages, 1); ++$page) {
            $variations = $this->fetchPageData($endpoint, $page, $args);

            foreach ($variations as $variation) {
                ContentMigration::createProductVariation($variation, $parent_source_id);
            }
        }
    }

    /**
     * Migrate WooCommerce customers from the WC REST API.
     *
     * Passwords are not migrated (the REST API does not expose them), so each
     * account is created with a random password and users must reset it to log
     * in. All outgoing mail is suppressed for the duration of this step so that
     * no account/notification emails reach real customers.
     *
     * @return void
     */
    public function migrateCustomers()
    {
        if (!$this->wooEnabled()) {
            return;
        }

        $endpoint = $this->wooEndpoint('customers');
        $args = $this->wooArgs();

        if (!$this->wooReachable($endpoint, $args)) {
            return;
        }

        $this->info('Migrating customers');
        $this->line('');

        $total_pages = $this->fetchTotalPages($endpoint, $args);
        $total_items = $this->fetchTotalItems($endpoint, $args);

        $progressBar = $this->output->createProgressBar($total_items);
        $progressBar->setFormat(config('content-migration.progress_bar_format'));

        // hard-stop every outgoing email while creating users, so no account or
        // notification mail is ever sent to real customers on a dev/staging box
        add_filter('pre_wp_mail', '__return_false', PHP_INT_MAX);

        try {
            for ($page = 1; $page <= $total_pages; ++$page) {
                $customers = $this->fetchPageData($endpoint, $page, $args);

                foreach ($customers as $customer) {
                    ContentMigration::createCustomer($customer);
                    $progressBar->advance();
                }
            }
        } finally {
            remove_filter('pre_wp_mail', '__return_false', PHP_INT_MAX);
        }

        $progressBar->finish();
        $this->printFormattedEndMessage('Migrated customers');
    }

    /**
     * Migrate WooCommerce orders from the WC REST API.
     *
     * Must run after products and customers so line items and the order's
     * customer can be remapped to their local ids. Totals are preserved
     * verbatim by createOrder(). For the duration of this step all outgoing
     * mail is suppressed and WooCommerce stock mutation is disabled, so that
     * recreating historical orders never emails customers or alters product
     * stock levels.
     *
     * @return void
     */
    public function migrateOrders()
    {
        if (!$this->wooEnabled()) {
            return;
        }

        $endpoint = $this->wooEndpoint('orders');
        $args = $this->wooArgs();

        if (!$this->wooReachable($endpoint, $args)) {
            return;
        }

        // orders map to local customers via the customer migration; without it
        // every order is attached to a guest instead of the real account
        if (!ContentMigration::hasImportedCustomers()) {
            $this->warn('No imported customers found - orders will be migrated as guest orders. Migrate customers first to link orders to their accounts.');
        }

        $this->info('Migrating orders');
        $this->line('');

        $total_pages = $this->fetchTotalPages($endpoint, $args);
        $total_items = $this->fetchTotalItems($endpoint, $args);

        $progressBar = $this->output->createProgressBar($total_items);
        $progressBar->setFormat(config('content-migration.progress_bar_format'));

        // suppress all mail and prevent stock changes while recreating orders
        add_filter('pre_wp_mail', '__return_false', PHP_INT_MAX);
        add_filter('woocommerce_can_reduce_order_stock', '__return_false', PHP_INT_MAX);
        add_filter('woocommerce_can_restore_order_stock', '__return_false', PHP_INT_MAX);

        try {
            for ($page = 1; $page <= $total_pages; ++$page) {
                $orders = $this->fetchPageData($endpoint, $page, $args);

                foreach ($orders as $order) {
                    ContentMigration::createOrder($order);
                    $progressBar->advance();
                }
            }
        } finally {
            remove_filter('pre_wp_mail', '__return_false', PHP_INT_MAX);
            remove_filter('woocommerce_can_reduce_order_stock', '__return_false', PHP_INT_MAX);
            remove_filter('woocommerce_can_restore_order_stock', '__return_false', PHP_INT_MAX);
        }

        $progressBar->finish();
        $this->printFormattedEndMessage('Migrated orders');
    }

    /**
     * Print formatted end message.
     *
     * @param string $message
     *
     * @return void
     */
    public function printFormattedEndMessage($message)
    {
        $this->line('');
        $this->line('');
        $this->info($message);
        $this->line('');
    }
}