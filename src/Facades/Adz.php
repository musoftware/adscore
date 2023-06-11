<?php

namespace adz\core\Facades;

use Illuminate\Support\Facades\Facade;

class Adz extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return 'Adz';
    }
}
