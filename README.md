# Wordpress API Content Migration

This Acorn package provides Artisan commands to migrate a WP site's content using the WP REST API. 

## Installation

You can install this package with Composer:

```bash
composer require istogram/wp-api-content-migration
```

You can publish the config file with:

```shell
wp acorn vendor:publish --provider="istogram\WpApiContentMigration\Providers\ContentMigrationServiceProvider"
```

## Configuration

### Allow non-standard media uploads

If you want to allow non-standard media uploads you will need to set the config option:

```php
'allow_media' => [
  'svg' => 'image/svg+xml',
],
```

## Usage

To migrate WP content from a WP site, using the WP REST API, to the local site use this command replacing {domain} with the domain of the Live WP site :

```shell
wp acorn migrate:content {domain}
```

When no options are applied, the command will proceed step by step, asking for confirmation before each step is applied.

If you want to clear the current taxonomies, media, posts and pages of the local site you may use this option :

```shell
wp acorn migrate:content {domain} --clear-all
```

You may also use this option if you want to migrate all WP content without confirmations :

```shell
wp acorn migrate:content {domain} --clear-all --migrate-all
```

Please be aware that if you choose to clear any of the existing taxonomies, media, posts or pages this will delete entirely all the relevant content from the local site DB. This action is irreversible, so it's safer to have a DB backup first.

