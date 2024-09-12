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
                }
                break;
            case false:
                $this->info('Clearing content');
                $this->confirmClear('taxonomies') ? $this->clearTaxonomies() : null;
                $this->confirmClear('media') ? $this->clearMedia() : null;
                $this->confirmClear('posts') ? $this->clearPosts() : null;
                $this->confirmClear('pages') ? $this->clearPages() : null;
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
                $this->clearImportedMeta();
                break;
            case false:
                $this->info('Migrating content');
                $this->confirmMigrate('categories') ? $this->migrateCategories() : null;
                $this->confirmMigrate('tags') ? $this->migrateTags() : null;
                $this->confirmMigrate('media') ? $this->migrateMedia() : null;
                $this->confirmMigrate('posts') ? $this->migratePosts() : null;
                $this->confirmMigrate('pages') ? $this->migratePages() : null;
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
     * Clear imported meta data.
     *
     * @return void
     */
    public function clearImportedMeta()
    {
        $types = ['category', 'tag', 'featured_media', 'post', 'page'];

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

    /**
     * Fetch total pages from WP API. This method is used to fetch the total number of pages from WP API.
     *
     * @param string $endpoint
     *
     * @return void
     */
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

    /**
     * Fetch page data from WP API. This method is used to fetch data from a specific page.
     *
     * @param string $endpoint
     * @param int    $page
     *
     * @return void
     */
    public function fetchPageData($endpoint, $page)
    {
        $response = wp_remote_get($endpoint.'?page='.$page);

        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $this->error("Error: $error_message");

            return;
        }

        return json_decode(wp_remote_retrieve_body($response));
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

        // get categories
        $categories = $this->fetchData($categories_endpoint);

        // get total pages
        $total_pages = $this->fetchTotalPages($categories_endpoint);

        // create progress bar
        $progressBar = $this->output->createProgressBar($total_pages);

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

        // get tags
        $tags_endpoint = $this->argument('domain').'/wp-json/wp/v2/tags';

        $tags = $this->fetchData($tags_endpoint);

        // get total pages
        $total_pages = $this->fetchTotalPages($tags_endpoint);

        // create progress bar
        $progressBar = $this->output->createProgressBar($total_pages);

        // set progress bar format
        $progressBar->setFormat(config('content-migration.progress_bar_format'));

        // loop through all pages
        for ($page = 1; $page <= $total_pages; ++$page) {
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

        // get media
        $media = $this->fetchData($media_endpoint);

        // get total pages
        $total_pages = $this->fetchTotalPages($media_endpoint);

        // create progress bar
        $progressBar = $this->output->createProgressBar($total_pages);

        // set progress bar format
        $progressBar->setFormat(config('content-migration.progress_bar_format'));

        // loop through all pages
        for ($page = 1; $page <= $total_pages; ++$page) {
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

        // get posts
        $posts = $this->fetchData($posts_endpoint);

        // get total pages
        $total_pages = $this->fetchTotalPages($posts_endpoint);

        // create progress bar
        $progressBar = $this->output->createProgressBar($total_pages);

        // set progress bar format
        $progressBar->setFormat(config('content-migration.progress_bar_format'));

        // loop through all pages
        for ($page = 1; $page <= $total_pages; ++$page) {
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

        // get pages
        $pages = $this->fetchData($pages_endpoint);

        // get total pages
        $total_pages = $this->fetchTotalPages($pages_endpoint);

        // create progress bar
        $progressBar = $this->output->createProgressBar($total_pages);

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
        $this->printFormattedEndMessage('Migrated pages');
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
