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

### Allow SVG media uploads

If you want to allow SVG media uploads you will need to set the config option:

```php
'allow_svg_media' => true
```

### WooCommerce products

The package can migrate WooCommerce products, product categories, product tags and variations via the WooCommerce REST API (`wc/v3`). This requires a few things:

- **WooCommerce must be active on the destination (local) site.** Products are created through WooCommerce's own CRUD classes, so all internal data (postmeta, lookup tables, HPOS) is handled correctly.
- **REST API credentials for the source site.** On the live site, go to *WooCommerce > Settings > Advanced > REST API* and generate a key with **Read** permissions. Expose the credentials via the environment (recommended for Bedrock):

```shell
WC_MIGRATION_CONSUMER_KEY=ck_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
WC_MIGRATION_CONSUMER_SECRET=cs_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

The full set of supported variables is documented in [`config/content-migration.php`](config/content-migration.php). Provide them through your host application's environment — in a Bedrock project that is the project-root `.env`, which Acorn reads via the `env()` helper. Never commit real credentials.

Credentials are sent as an HTTP Basic Auth header over HTTPS. If your source server strips the `Authorization` header (you'll see a "Consumer key is missing" error), set `WC_MIGRATION_QUERY_STRING_AUTH=true` to send them as query-string parameters instead.

For best results, migrate **media before products** so product images are linked to already-imported attachments; any image that isn't found is sideloaded as a fallback.

Product migration can be disabled entirely with `WC_MIGRATION_ENABLED=false` (this also disables customer migration below).

### WooCommerce customers

Registered WooCommerce customers are migrated via the `wc/v3/customers` endpoint using the same credentials as products. For each customer a WordPress user (role `customer`) is created with their email, name, and billing/shipping addresses.

**Passwords are not migrated.** The REST API does not expose password hashes, so each imported account is given a random password and users must reset their password to log in — notify them out-of-band. To make this safe on a dev/staging box, **all outgoing email is suppressed while customers are created**, so no account or notification emails are ever sent.

Accounts are de-duplicated by email and login: if a matching user already exists locally (e.g. an admin, or a previous run) it is mapped and left untouched rather than duplicated.

> **Privacy note:** this copies real customer PII (emails, addresses) into your local database. Make sure that's acceptable for your environment.

### WooCommerce orders

Orders are migrated via `wc/v3/orders` and recreated through WooCommerce's `WC_Order` CRUD. Order migration runs **after** products and customers, because each order's line items and customer are remapped to the local product/variation/user ids (unmapped references keep the order's own snapshot data, so nothing is lost). Guest orders are preserved with `customer_id` 0.

Each order is reproduced in full — product, shipping, fee, coupon and tax lines — and **all totals are copied verbatim; they are never recalculated**, so historical orders keep their original amounts even if product prices or tax rates have since changed. Status, dates, payment method and transaction id are preserved.

While orders are being created, **all outgoing email is suppressed and stock mutation is disabled**, so recreating historical orders never emails customers or alters product stock levels.

Order clearing (and the id mapping) works with both classic post-based storage and **HPOS** (High-Performance Order Storage).

> **Privacy note:** orders are the heaviest payload — full purchase history, addresses and payment metadata. Only migrate them into environments where that data is acceptable.

**Not yet migrated:** order refunds and order notes are out of scope for now, as are coupons (as reusable coupon definitions — coupon *lines* on an order are preserved). Global attribute taxonomies (`pa_*`) are imported as custom, product-level attributes rather than recreated as global attributes.

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

Please be aware that if you choose to clear any of the existing taxonomies, media, posts, pages, WooCommerce products, customers or orders this will delete entirely all the relevant content from the local site DB. This action is irreversible, so it's safer to have a DB backup first. (Clearing customers and orders only removes records that were imported by this package — pre-existing accounts such as admins, and locally created orders, are left intact.)