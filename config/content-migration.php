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
    | Allow media
    |--------------------------------------------------------------------------
    |
    | Set to allow diffrent non-standard WordPress files to be imported.
    | allow_media => ['svg' => 'image/svg+xml']
    */
    'allow_media' => false,
];
