<?php

namespace istogram\WpApiContentMigration\Facades;

use Illuminate\Support\Facades\Facade;

class ClearContent extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return 'ClearContent';
    }
}
