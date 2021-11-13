<?php
/**
 * nekoimi  2021/10/29 16:46
 */

namespace Lawoole;


use Illuminate\Config\Repository;
use Illuminate\Support\ServiceProvider;
use Swoole\Http\Server as HttpServer;
use Lawoole\Commands\HttpServerCommand;
use Lawoole\Server\Facades\Server;
use Lawoole\Server\PidManager;

/**
 * Class HttpServiceProvider
 * @package Lawoole
 */
abstract class HttpServiceProvider extends ServiceProvider
{
    /**
     * @var bool
     */
    protected $defer = false;

    /**
     * @var \Swoole\Http\Server
     */
    protected static $server;

    /**
     * @return Repository
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     */
    public function config(): Repository
    {
        return $this->app->make('config');
    }

    /**
     * @return Repository
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     */
    public function httpConfig(): Repository
    {
        return new Repository($this->app->make('config')->get('swoole_http', []));
    }

    /**
     * Boot service provider.
     */
    public function boot(): void
    {
        $this->publishFiles();
        $this->loadConfigs();
        $this->mergeConfigs();

        if ($this->httpConfig()->get('server.access_log')) {
            $this->pushAccessLogMiddleware();
        }
    }

    public function register()
    {
        $this->registerServer();
        $this->registerManager();
        $this->registerCommands();
        $this->registerPidManager();
    }

    /**
     * Register manager.
     *
     * @return void
     */
    abstract protected function registerManager();

    /**
     * Register access log middleware to container.
     *
     * @return void
     */
    abstract protected function pushAccessLogMiddleware();

    /**
     * Load configurations.
     */
    protected function loadConfigs()
    {
        // do nothing
    }

    /**
     * Merge configurations.
     */
    protected function mergeConfigs()
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/swoole_http.php', 'swoole_http');
    }

    /**
     * Publish files of this package.
     */
    protected function publishFiles()
    {
        $this->publishes([
            __DIR__ . '/../config/swoole_http.php' => base_path('config/swoole_http.php')
        ], 'Lawoole');
    }

    /**
     * Register server.
     *
     * @return void
     */
    protected function registerServer()
    {
        $this->app->singleton(Server::class, function () {
            if (is_null(static::$server)) {
                $this->createSwooleHttpServer();
                $this->configureSwooleServer();
            }

            return static::$server;
        });
        $this->app->alias(Server::class, 'swoole.http.server');
    }

    /**
     * Create swoole server.
     */
    protected function createSwooleHttpServer()
    {
        $server = HttpServer::class;
        $config = $this->httpConfig();
        $host = $config->get('server.host');
        $port = $config->get('server.port');
        $socketType = $config->get('server.socket_type', SWOOLE_SOCK_TCP);
        $processType = $config->get('server.process_type', SWOOLE_PROCESS);

        static::$server = new $server($host, $port, $processType, $socketType);
    }

    /**
     * Set swoole server configurations.
     */
    protected function configureSwooleServer()
    {
        $options = $this->httpConfig()->get('server.options');

        // lookup for set swoole driver
        $isDefinedSwooleDriver = in_array(
                'swoole',
                array_column(
                    $this->config()->get('queue.connections'),
                    'driver'
                ),
                true
            ) || $this->config()->get('queue.default') === 'swoole';

        // only enable task worker in websocket mode and for queue driver
        if (!$isDefinedSwooleDriver) {
            unset($options['task_worker_num']);
        }

        static::$server->set($options);
    }

    /**
     * Register commands.
     */
    protected function registerCommands()
    {
        $this->commands([
            HttpServerCommand::class,
        ]);
    }

    /**
     * Register pid manager.
     *
     * @return void
     */
    protected function registerPidManager(): void
    {
        $this->app->singleton(PidManager::class, function () {
            return new PidManager(
                $this->httpConfig()->get('server.options.pid_file')
            );
        });
    }
}
