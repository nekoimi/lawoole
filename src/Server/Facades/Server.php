<?php
/**
 * nekoimi  2021/10/29 17:03
 */

namespace Lawoole\Server\Facades;

use Illuminate\Support\Facades\Facade;

class Server extends Facade
{

    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return 'swoole.http.server';
    }
}
