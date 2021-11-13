<?php
/**
 * nekoimi  2021/10/30 0:54
 */

namespace Lawoole\Server;


use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Str;
use Lawoole\Server\Facades\Server;
use Lawoole\Task\SwooleTaskJob;
use Lawoole\Transformers\Request;
use Lawoole\Transformers\Response;
use Lawoole\Utils\OsUtils;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
use Illuminate\Http\Request as IlluminateRequest;
use Illuminate\Http\Response as IlluminateResponse;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\ErrorHandler\Error\FatalError;
use Throwable;

class Manager
{

    /**
     * @var Container
     */
    protected $container;

    /**
     * New Laravel/Lumen App
     *
     * 在 workerStart 的时候会生成该实例
     * @var Container
     */
    protected $app;

    /**
     * @var string
     */
    protected $framework;

    /**
     * @var string
     */
    protected $basePath;

    /**
     * Server events
     * @link https://wiki.swoole.com/#/server/events
     * @var string[]
     */
    protected $events = [
        'start',
        'shutdown',
        'workerStart',
        'workerStop',
        'workerError',
        'task',
        'finish',
        'managerStart',
        'managerStop',
        'request',
    ];

    /**
     * Manager constructor.
     * @param Container $container
     * @param string|null $framework
     * @param string|null $basePath
     */
    public function __construct(Container $container, string $framework = null, string $basePath = null)
    {
        $this->container = $container;
        $this->framework = $framework;
        $this->basePath = $basePath;
        $this->initialize();
    }

    /**
     * @return Container
     */
    public function getContainer(): Container
    {
        return $this->container;
    }

    /**
     * @return Container
     */
    public function getApp(): Container
    {
        return $this->app;
    }

    /**
     * @return string
     */
    public function getFramework(): string
    {
        return $this->framework;
    }

    /**
     * @return string
     */
    public function getBasePath(): string
    {
        return $this->basePath;
    }

    /**
     * Initialize.
     */
    protected function initialize()
    {
        /** @var \Swoole\Http\Server $server */
        $server = $this->getContainer()->make(Server::class);
        if (!$server->taskworker) {
            $this->setSwooleServerListeners();
        }
    }

    /**
     * Set server listeners
     */
    protected function setSwooleServerListeners()
    {
        /** @var \Swoole\Http\Server $server */
        $server = $this->getContainer()->make(Server::class);
        foreach ($this->events as $event) {
            $listener = Str::camel("on_$event");
            $this->getContainer()->make('log')->debug("Set server listener $event: $listener");
            $callback = method_exists($this, $listener) ? [$this, $listener] : function () use ($event) {
                $this->getContainer()->make('events')->dispatch(Str::snake("swoole.$event", '.'), func_get_args());
            };
            $server->on($event, $callback);
        }
    }

    /**
     * =================================================================================================
     * Server Listeners
     * =================================================================================================
     */

    /**
     * @param \Swoole\Http\Server $server
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     * @link  https://wiki.swoole.com/#/server/events?id=onstart
     */
    public function onStart(\Swoole\Http\Server $server): void
    {
        $this->getContainer()->make('log')->debug("[onStart] master-$server->master_pid");
        $this->setProcessName('master-process');
        $this->getContainer()->make(PidManager::class)->write($server->master_pid, $server->manager_pid ?? 0);
        $this->getContainer()->make('events')->dispatch('swoole.start', func_get_args());
    }

    /**
     * @param \Swoole\Http\Server $server
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     */
    public function onShutdown(\Swoole\Http\Server $server): void
    {
        $this->getContainer()->make('log')->debug("[onShutdown] master-$server->master_pid");
        $this->getContainer()->make(PidManager::class)->delete();
    }

    /**
     * @param \Swoole\Http\Server $server
     * @param int $workerId
     * @link https://wiki.swoole.com/#/server/events?id=onworkerstart
     */
    public function onWorkerStart(\Swoole\Http\Server $server, int $workerId): void
    {
        $this->clearCache();

        $this->getContainer()->make('log')->debug("[onWorkerStart] master-$server->master_pid worker-$workerId");

        $this->container->make('events')->dispatch('swoole.worker.start', func_get_args());

        // clear events instance in case of repeated listeners in worker process
        Facade::clearResolvedInstance('events');

        // load application
        $this->loadApplication();

        // bind application
        $this->bindApplication();
    }

    /**
     * @param \Swoole\Http\Server $server
     * @param int $workerId
     * @link https://wiki.swoole.com/#/server/events?id=onworkerstop
     */
    public function onWorkerStop(\Swoole\Http\Server $server, int $workerId): void
    {
        $this->getContainer()->make('log')->debug("[onWorkerStop] master-$server->master_pid worker-$workerId");
    }

    /**
     * @param \Swoole\Http\Server $server
     * @param int|\Swoole\Server\Task $task
     * @param int|null $srcWorkerId
     * @param null $data
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     */
    public function onTask(\Swoole\Http\Server $server, $task, int $srcWorkerId = null, $data = null): void
    {
        if ($task instanceof \Swoole\Server\Task) {
            $data = $task->data;
            $srcWorkerId = $task->worker_id;
            $taskId = $task->id;
        } else {
            $taskId = $task;
        }

        $this->getContainer()->make('log')->debug("[onTask] master-$server->master_pid task-$taskId");

        $this->getContainer()->make('events')->dispatch('swoole.task', func_get_args());

        try {
            if ($this->isAsyncTaskPayload($data)) {
                (new SwooleTaskJob($this->getContainer(), $server, $data, $taskId, $srcWorkerId))->fire();
            }
        } catch (Throwable $e) {
            try {
                $this->logServerError($e);
            } catch (Throwable $e) {
            }
        }
    }

    /**
     * @param \Swoole\Http\Server $server
     * @param int $taskId
     * @param $data
     * @link https://wiki.swoole.com/#/server/events?id=onfinish
     */
    public function onFinish(\Swoole\Http\Server $server, int $taskId, $data): void
    {
        $this->getContainer()->make('log')->debug("[onFinish] master-$server->master_pid task-$taskId");
        $this->getContainer()->make('events')->dispatch('swoole.finish', func_get_args());
    }

    /**
     * @param \Swoole\Http\Server $server
     * @link https://wiki.swoole.com/#/server/events?id=onmanagerstart
     */
    public function onManagerStart(\Swoole\Http\Server $server): void
    {
        $this->getContainer()->make('log')->debug("[onManagerStart] master-$server->master_pid");
        $this->setProcessName('manager-process');
        $this->container->make('events')->dispatch('swoole.manager.start', func_get_args());
    }

    /**
     * @param \Swoole\Http\Server $server
     * @link https://wiki.swoole.com/#/server/events?id=onmanagerstop
     */
    public function onManagerStop(\Swoole\Http\Server $server): void
    {
        $this->getContainer()->make('log')->debug("[onManagerStop] master-$server->master_pid");
    }

    /**
     * @param SwooleRequest $swooleRequest
     * @param SwooleResponse $swooleResponse
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     * @link https://wiki.swoole.com/#/http_server?id=on
     */
    public function onRequest(SwooleRequest $swooleRequest, SwooleResponse $swooleResponse): void
    {
        $this->getApp()->make('log')->debug("[onRequest] client-$swooleRequest->fd");
        $this->getApp()->make('events')->dispatch('swoole.request');

        $sandBox = $this->getApp()->make(SandBox::class);

        // transform swoole request to illuminate request
        $illuminateRequest = Request::make($swooleRequest)->toIlluminate();

        try {
            $sandBox->begin();

            /** @var IlluminateResponse $illuminateResponse */
            $illuminateResponse = $sandBox->run($illuminateRequest);

            // send response
            Response::make($swooleRequest, $swooleResponse, $illuminateResponse)->send();
        } catch (Throwable $e) {
            try {
                $exceptionHandler = $this->getApp()->make(ExceptionHandlerContract::class);
                $exceptionResponse = $exceptionHandler->render($illuminateRequest, $this->normalizeException($e));
                Response::make($swooleRequest, $swooleResponse, $exceptionResponse)->send();
            } catch (Throwable $e) {
                try {
                    $this->logServerError($e);
                } catch (Throwable $e) {
                }
            }
        } finally {
            $sandBox->end();
        }
    }

    /**
     * Log server error.
     *
     * @param \Throwable|\Exception $e
     * @throws Throwable
     */
    public function logServerError(Throwable $e)
    {
        $exception = $this->normalizeException($e);
        $this->getContainer()->make(ConsoleOutput::class)->writeln(sprintf("<error>%s</error>", $exception));
        $this->getContainer()->make(ExceptionHandlerContract::class)->report($exception);
    }

    /**
     * Normalize a throwable/exception to exception.
     *
     * @param \Throwable|\Exception $e
     */
    protected function normalizeException(Throwable $e)
    {
        if (!$e instanceof \Exception) {
            if ($e instanceof \ParseError) {
                $severity = E_PARSE;
            } elseif ($e instanceof \TypeError) {
                $severity = E_RECOVERABLE_ERROR;
            } else {
                $severity = E_ERROR;
            }

            $error = [
                'type'    => $severity,
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
            ];

            $e = new FatalError($e->getMessage(), $e->getCode(), $error, null, true, $e->getTrace());
        }

        return $e;
    }

    /**
     * =================================================================================================
     * End Server Listeners
     * =================================================================================================
     */


    /**
     * =================================================================================================
     * Start
     * =================================================================================================
     */

    /**
     * Start.
     *
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     */
    public function run(): void
    {
        $this->getContainer()->make('log')->debug("======= Start =======");
        /** @var \Swoole\Http\Server $server */
        $server = $this->getContainer()->make(Server::class);
        $server->start();
    }

    /**
     * =================================================================================================
     * End Start
     * =================================================================================================
     */

    /**
     * @param string $processName
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     */
    protected function setProcessName(string $processName): void
    {
        if (OsUtils::isMac()) return;

        $serverName = 'swoole_http_server';
        $appName = $this->getContainer()->make('config')->get('app.name', 'Laravel');
        $name = sprintf('%s: %s for %s', $serverName, $processName, $appName);
        swoole_set_process_name($name);
    }

    /**
     * Clear APC or OPCache.
     */
    protected function clearCache(): void
    {
        if (extension_loaded('apc')) {
            apc_clear_cache();
        }

        if (extension_loaded('Zend OPcache')) {
            opcache_reset();
        }
    }

    /**
     * Load application.
     */
    protected function loadApplication(): void
    {
        if (!$this->app instanceof Container) {
            $this->app = require "{$this->basePath}/bootstrap/app.php";;
            $this->bootstrap();
        }
    }

    /**
     * Bootstrap framework.
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     * @throws \ReflectionException
     */
    protected function bootstrap(): void
    {
        if ($this->container instanceof Container) {
            if ($this->framework === 'laravel') {
                $bootstrappers = $this->getBootstrappers();
                $this->app->bootstrapWith($bootstrappers);
            } else {
                // for Lumen 5.7
                // https://github.com/laravel/lumen-framework/commit/42cbc998375718b1a8a11883e033617024e57260#diff-c9248b3167fc44af085b81db2e292837
                if (method_exists($this->container, 'boot')) {
                    $this->container->boot();
                }
                $this->container->withFacades();
            }

            $this->preResolveInstances();
        }
    }

    /**
     * Get bootstrappers.
     *
     * @return array
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     * @throws \ReflectionException
     */
    protected function getBootstrappers(): array
    {
        $kernel = $this->getApp()->make(Kernel::class);

        $reflection = new \ReflectionObject($kernel);
        $bootStrappersMethod = $reflection->getMethod('bootstrappers');
        $bootStrappersMethod->setAccessible(true);
        $bootStrappers = $bootStrappersMethod->invoke($kernel);

        array_splice($bootStrappers, -2, 0, ['Illuminate\Foundation\Bootstrap\SetRequestForConsole']);

        return $bootStrappers;
    }

    /**
     * Reslove some instances before request.
     *
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     */
    protected function preResolveInstances()
    {
        $resolves = $this->container->make('config')->get('swoole_http.pre_resolved', []);

        foreach ($resolves as $abstract) {
            if ($this->getApp()->offsetExists($abstract)) {
                $this->getApp()->make($abstract);
            }
        }
    }

    /**
     * Bind application
     */
    protected function bindApplication(): void
    {
        $this->bindSandbox();
    }

    /**
     * Bind sandbox to Laravel app container.
     */
    protected function bindSandbox(): void
    {
        $this->app->singleton(Sandbox::class, function ($app) {
            return new Sandbox($app, $this->framework);
        });

        $this->app->alias(Sandbox::class, 'swoole.sandbox');
    }

    /**
     * Indicates if the payload is async task.
     *
     * @param mixed $payload
     *
     * @return boolean
     */
    protected function isAsyncTaskPayload($payload): bool
    {
        $data = json_decode($payload, true);

        if (JSON_ERROR_NONE !== json_last_error()) {
            return false;
        }

        return isset($data['job']);
    }
}
