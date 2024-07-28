<?php

namespace istogram\WpApiContentMigration\Providers;

use Illuminate\Support\ServiceProvider;
use istogram\WpApiContentMigration\Console\ContentMigrationCommand;
use istogram\WpApiContentMigration\ContentMigration;

class ContentMigrationServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->singleton('ContentMigration', function () {
            return new ContentMigration($this->app);
        });

        $this->mergeConfigFrom(
            __DIR__.'/../../config/content-migration.php',
            'content-migration'
        );
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        $this->publishes([
            __DIR__.'/../../config/content-migration.php' => $this->app->configPath('content-migration.php'),
        ], 'config');

        $this->commands([
            ContentMigrationCommand::class,
        ]);

        $this->app->make('ContentMigration');
    }
}
