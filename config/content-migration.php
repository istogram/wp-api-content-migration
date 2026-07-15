<?php

return [

    /*
    |--------------------------------------------------------------------------
    | WP API Content Migration
    |--------------------------------------------------------------------------
    | Configuration file for the WP API Content Migration package.
    |
    | @package WP API Content Migration
    | @version 1.2
    */

    /*
    |--------------------------------------------------------------------------
    | Progress Bar Style
    |--------------------------------------------------------------------------
    |
    | Set the style of the progress bar.
    */
    'progress_bar_format' => '<info>%current%/%max%</info> [<fg=green>%bar%</>] <info>%elapsed%</info>',

    /*
    |--------------------------------------------------------------------------
    | Allow SVG media
    |--------------------------------------------------------------------------
    |
    | Set to true to allow SVG media files to be imported.
    */
    'allow_svg_media' => false,

    /*
    |--------------------------------------------------------------------------
    | WooCommerce
    |--------------------------------------------------------------------------
    |
    | Configuration for migrating WooCommerce products, product categories,
    | product tags and variations via the WooCommerce REST API (wc/v3).
    |
    | The WooCommerce REST API requires authentication. Generate a read-only
    | key on the SOURCE site under WooCommerce > Settings > Advanced > REST API
    | and expose the credentials via the environment (recommended for Bedrock):
    |
    |   WC_MIGRATION_CONSUMER_KEY=ck_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
    |   WC_MIGRATION_CONSUMER_SECRET=cs_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
    |
    | Products are created on the local site using WooCommerce's own CRUD
    | classes (WC_Product_*), so WooCommerce must be active on the destination.
    */
    'woocommerce' => [

        // Master switch. When false, all product steps are skipped.
        'enabled' => env('WC_MIGRATION_ENABLED', true),

        // REST API credentials for the SOURCE site.
        'consumer_key' => env('WC_MIGRATION_CONSUMER_KEY', ''),
        'consumer_secret' => env('WC_MIGRATION_CONSUMER_SECRET', ''),

        /*
        | Authentication transport.
        |
        | By default credentials are sent as an HTTP Basic Auth header, which is
        | the recommended method over HTTPS. Some servers (e.g. certain FastCGI
        | setups) strip the Authorization header and report "Consumer key is
        | missing"; set this to true to fall back to query-string credentials.
        | Only use query-string auth over HTTPS.
        */
        'query_string_auth' => env('WC_MIGRATION_QUERY_STRING_AUTH', false),

        // Items requested per API page (WooCommerce max is 100).
        'per_page' => 100,
    ],
];