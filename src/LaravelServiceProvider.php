<?php
/**
 * nekoimi  2021/10/30 0:43
 */

namespace Lawoole;


use Illuminate\Contracts\Http\Kernel;
use Lawoole\Middleware\AccessLog;
use Lawoole\Server\Manager;

/**
 * Class LaravelServiceProvider
 * @package Lawoole
 * @property \Illuminate\Contracts\Foundation\Application $app
 */
class LaravelServiceProvider extends HttpServiceProvider
{

    protected function registerManager()
    {
        $this->app->singleton(Manager::class, function ($app) {
            return new Manager($app, 'laravel', $app->basePath());
        });

        $this->app->alias(Manager::class, 'swoole.manager');
    }

    protected function pushAccessLogMiddleware()
    {
        $this->app->make(Kernel::class)->pushMiddleware(AccessLog::class);
    }
}
