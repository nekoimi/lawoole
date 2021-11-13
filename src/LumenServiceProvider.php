<?php
/**
 * nekoimi  2021/10/30 0:42
 */

namespace Lawoole;


use Lawoole\Middleware\AccessLog;
use Lawoole\Server\Manager;

/**
 * Class LumenServiceProvider
 * @package Lawoole
 *
 * @property \Laravel\Lumen\Application $app
 */
class LumenServiceProvider extends HttpServiceProvider
{

    protected function loadConfigs()
    {
        $this->app->configure('swoole_http');
    }

    protected function registerManager()
    {
        $this->app->singleton(Manager::class, function ($app) {
            return new Manager($app, 'lumen', $app->basePath());
        });

        $this->app->alias(Manager::class, 'swoole.manager');
    }

    protected function pushAccessLogMiddleware()
    {
        $this->app->middleware(AccessLog::class);
    }
}
