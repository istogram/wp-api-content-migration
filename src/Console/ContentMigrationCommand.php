<?php

namespace istogram\WpApiContentMigration\Console;

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

        // Ask for confirmation before clearing all content
        if ($this->option('clear-all')) {
            if ($this->confirm('Do you want to clear all content?')) {
                $this->info('Clearing all content');
                $this->clearTaxonomies();
                $this->clearMedia();
                $this->clearPosts();
                $this->clearPages();
            }
        }

        // Ask for confirmation before migrating all content
        if ($this->option('migrate-all')) {
            if ($this->confirm('Do you want to migrate all content?')) {
                $this->info('Migrating all content');
                $this->migrateCategories();
                $this->migrateTags();
                $this->migrateMedia();
                $this->migratePosts();
                $this->migratePages();
            }
        }

        // Ask for confirmation before deleting taxonomies
        if (!$this->option('clear-all') && $this->confirm('Do you want to clear taxonomies?')) {
            $this->info('Clearing taxonomies');
            $this->clearTaxonomies();
        }
        
        // Ask for confirmation before deleting media
        if (!$this->option('clear-all') && $this->confirm('Do you want to clear media?')) {
            $this->info('Clearing media');
            $this->clearMedia();
        }

        // Ask for confirmation before deleting posts
        if (!$this->option('clear-all') && $this->confirm('Do you want to clear posts?')) {
            $this->info('Clearing posts');
            $this->clearPosts();
        }

        // Ask for confirmation before deleting pages
        if (!$this->option('clear-all') && $this->confirm('Do you want to clear pages?')) {
            $this->info('Clearing pages');
            $this->clearPages();
        }

        // Ask for confirmation before migrating categories
        if (!$this->option('migrate-all') && $this->confirm('Do you want to migrate categories?')) {
            $this->migrateCategories();
        }

        // Ask for confirmation before migrating tags
        if (!$this->option('migrate-all') && $this->confirm('Do you want to migrate tags?')) {
            $this->migrateTags();
        }

        // Ask for confirmation before migrating media
        if (!$this->option('migrate-all') && $this->confirm('Do you want to migrate media?')) {
            $this->migrateMedia();
        }

        // Ask for confirmation before migrating posts
        if (!$this->option('migrate-all') && $this->confirm('Do you want to migrate posts?')) {
            $this->migratePosts();
        }

        // Ask for confirmation before migrating pages
        if (!$this->option('migrate-all') && $this->confirm('Do you want to migrate pages?')) {
            $this->migratePages();
        }
    }

    public function clearTaxonomies()
    {
        $response = ContentMigration::clearTaxonomies();

        return $this->info($response);
    }

    public function clearMedia()
    {
        $response = ContentMigration::clearMedia();

        $this->info($response);
    }

    public function clearPosts()
    {
        $response = ContentMigration::clearPosts();

        $this->info($response);
    }

    public function clearPages()
    {
        $response = ContentMigration::clearPages();

        $this->info($response);
    }

    public function fetchData($endpoint)
    {
        $response = wp_remote_get($endpoint);

        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $this->error("Error: $error_message");
            return;
        }

        return json_decode(wp_remote_retrieve_body($response));
    }

    public function fetchTotalPages($endpoint)
    {
        $response = wp_remote_get($endpoint);

        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $this->error("Error: $error_message");
            return;
        }

        return wp_remote_retrieve_header($response, 'X-WP-TotalPages');
    }

    public function fetchPageData($endpoint, $page)
    {
        $response = wp_remote_get($endpoint . '?page=' . $page);

        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $this->error("Error: $error_message");
            return;
        }

        return json_decode(wp_remote_retrieve_body($response));
    }
    
    /*
    * Migrate categories
    */
    public function migrateCategories()
    {
        $this->info('Migrating WP categories');
        $this->line('');

        // set categories endpoint
        $categories_endpoint = $this->argument('domain') . '/wp-json/wp/v2/categories';

        // get categories
        $categories = $this->fetchData($categories_endpoint);

        // get total pages
        $total_pages = $this->fetchTotalPages($categories_endpoint);

        // create progress bar
        $progressBar = $this->output->createProgressBar($total_pages);

        // set progress bar format
        $progressBar->setFormat(config('content-migration.progress_bar_format'));

        // loop through all pages
        for ($page = 1; $page <= $total_pages; $page++) {
            // get categories
            $categories = $this->fetchPageData($categories_endpoint, $page);
            
            // filter parent categories
            $parent_categories = array_filter($categories, function ($category) {
                return $category->parent === 0;
            });

            // create parent categories
            foreach ($parent_categories as $category) {
                ContentMigration::createCategory($category);
            }

            // filter child categories
            $child_categories = array_filter($categories, function ($category) {
                return $category->parent !== 0;
            });

            // create child categories
            foreach ($child_categories as $category) {
                ContentMigration::createCategory($category);
            }

            // output progress
            $progressBar->advance();

            // break if last page
            if ($page === $total_pages) {
                break;
            }
        }

        $progressBar->finish();
        $this->line('');
        $this->line('');
        $this->info('Migrated WP categories');
        $this->line('');
    }

    /*
    * Migrate tags
    */
    public function migrateTags()
    {
        $this->info('Migrating tags');
        $this->line('');

        // get tags
        $tags_endpoint = $this->argument('domain') . '/wp-json/wp/v2/tags';

        $tags = $this->fetchData($tags_endpoint);

        // get total pages
        $total_pages = $this->fetchTotalPages($tags_endpoint);

        // create progress bar
        $progressBar = $this->output->createProgressBar($total_pages);

        // set progress bar format
        $progressBar->setFormat(config('content-migration.progress_bar_format'));

        // loop through all pages
        for ($page = 1; $page <= $total_pages; $page++) {
            $tags = $this->fetchPageData($tags_endpoint, $page);

            // create tags
            foreach ($tags as $tag) {
                ContentMigration::createTag($tag);
            }

            // output progress
            $progressBar->advance();

            // break if last page
            if ($page === $total_pages) {
                break;
            }
        }

        $progressBar->finish();
        $this->line('');
        $this->line('');
        $this->info('Migrated WP tags');
        $this->line('');
    }

    public function migrateMedia()
    {
        $this->info('Migrating media');
        $this->line('');

        // set media endpoint
        $media_endpoint = $this->argument('domain') . '/wp-json/wp/v2/media';

        // get media
        $media = $this->fetchData($media_endpoint);

        // get total pages
        $total_pages = $this->fetchTotalPages($media_endpoint);

        // create progress bar
        $progressBar = $this->output->createProgressBar($total_pages);

        // set progress bar format
        $progressBar->setFormat(config('content-migration.progress_bar_format'));

        // loop through all pages
        for ($page = 1; $page <= $total_pages; $page++) {
            $media = $this->fetchPageData($media_endpoint, $page);
            
            // create media
            foreach ($media as $medium) {
                ContentMigration::createMedia($medium);
            }

            // output progress
            $progressBar->advance();

            // break if last page
            if ($page === $total_pages) {
                break;
            }
        }

        $progressBar->finish();
        $this->line('');
        $this->line('');
        $this->info('Migrated media');
        $this->line('');
    }

    public function migratePosts()
    {
        $this->info('Migrating posts');
        $this->line('');

        // set posts endpoint
        $posts_endpoint = $this->argument('domain') . '/wp-json/wp/v2/posts';

        // get posts
        $posts = $this->fetchData($posts_endpoint);

        // get total pages
        $total_pages = $this->fetchTotalPages($posts_endpoint);

        // create progress bar
        $progressBar = $this->output->createProgressBar($total_pages);

        // set progress bar format
        $progressBar->setFormat(config('content-migration.progress_bar_format'));

        // loop through all pages
        for ($page = 1; $page <= $total_pages; $page++) {
            $posts = $this->fetchPageData($posts_endpoint, $page);

            // create posts
            foreach ($posts as $post) {
                ContentMigration::createPost($post);
            }

            // output progress
            $progressBar->advance();

            // break if last page
            if ($page === $total_pages) {
                break;
            }
        }

        $progressBar->finish();
        $this->line('');
        $this->line('');
        $this->info('Migrated posts');
        $this->line('');
    }

    public function migratePages()
    {
        $this->info('Migrating pages');
        $this->line('');

        // set endpoint
        $pages_endpoint = $this->argument('domain') . '/wp-json/wp/v2/pages';

        // get pages
        $pages = $this->fetchData($pages_endpoint);

        // get total pages
        $total_pages = $this->fetchTotalPages($pages_endpoint);

        // create progress bar
        $progressBar = $this->output->createProgressBar($total_pages);

        // set progress bar format
        $progressBar->setFormat(config('content-migration.progress_bar_format'));

        // loop through all pages
        for ($page = 1; $page <= $total_pages; $page++) {
            $pages = $this->fetchPageData($pages_endpoint, $page);

            // filter parent pages
            $parent_pages = array_filter($pages, function ($page) {
                return $page->parent === 0;
            });

            // create parent pages
            foreach ($parent_pages as $pageToMigrate) {
                ContentMigration::createPage($pageToMigrate);
            }

            // filter child pages
            $child_pages = array_filter($pages, function ($page) {
                return $page->parent !== 0;
            });

            // create child pages
            foreach ($child_pages as $pageToMigrate) {
                ContentMigration::createPage($pageToMigrate);
            }

            // output progress
            $progressBar->advance();

            // break if last page
            if ($page === $total_pages) {
                break;
            }
        }

        $progressBar->finish();
        $this->line('');
        $this->line('');
        $this->info('Migrated pages');
        $this->line('');
    }
}
