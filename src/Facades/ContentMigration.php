<?php

namespace istogram\WpApiContentMigration\Facades;

use Illuminate\Support\Facades\Facade;

class ContentMigration extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return 'ContentMigration';
    }
}
