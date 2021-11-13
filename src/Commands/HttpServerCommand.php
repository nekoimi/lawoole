<?php
/**
 * nekoimi  2021/10/29 17:10
 */

namespace Lawoole\Commands;

use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Arr;
use Lawoole\Middleware\AccessLog;
use Lawoole\Server\AccessOutput;
use Lawoole\Server\Manager;
use Lawoole\Server\PidManager;
use Lawoole\Utils\OsUtils;
use Swoole\Process;
use Symfony\Component\Console\Output\ConsoleOutput;

class HttpServerCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'swoole:http {action : start|stop|restart|reload|infos}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Swoole HTTP Server controller.';

    /**
     * The console command action. start|stop|restart|reload|infos
     *
     * @var string
     */
    protected $action;

    /**
     * The pid.
     *
     * @var int
     */
    protected $currentPid;

    /**
     * The configs for this package.
     *
     * @var array
     */
    protected $config;

    /**
     * handle
     */
    public function handle()
    {
        $this->checkEnvironment();
        $this->loadConfigs();
        $this->initAction();
        $this->hookAction();
        $this->runAction();
    }

    /**
     * Hook action
     */
    protected function hookAction()
    {
        // custom hook task before starting server
    }

    /**
     * Run action.
     */
    protected function runAction()
    {
        $this->{$this->action}();
    }

    /**
     * Load configs.
     */
    protected function loadConfigs()
    {
        $this->config = $this->laravel->make('config')->get('swoole_http');
    }

    /**
     * Check running enironment.
     */
    protected function checkEnvironment()
    {
        if (OsUtils::isWin()) {
            $this->error('Swoole extension doesn\'t support Windows OS.');

            exit(1);
        }

        if (!extension_loaded('swoole')) {
            $this->error('Can\'t detect Swoole extension installed.');

            exit(1);
        }

        if (!version_compare(swoole_version(), '4.3.1', 'ge')) {
            $this->error('Your Swoole version must be higher than `4.3.1`.');

            exit(1);
        }
    }

    /**
     * Initialize command action.
     */
    protected function initAction()
    {
        $this->action = $this->argument('action');

        if (!in_array($this->action, ['start', 'stop', 'restart', 'reload', 'infos'], true)) {
            $this->error(
                "Invalid argument '{$this->action}'. Expected 'start', 'stop', 'restart', 'reload' or 'infos'."
            );

            return;
        }
    }

    /**
     * ================================================================================
     * Action
     * ================================================================================
     */

    /**
     * If Swoole process is running.
     *
     * @return bool
     */
    public function isRunning(): bool
    {
        $pid = $this->laravel->make(PidManager::class)->read();

        if ($pid->managerPid()) {
            // Swoole process mode
            return $pid->masterPid() && $pid->managerPid() && Process::kill($pid->managerPid(), 0);
        }

        // Swoole base mode, no manager process
        return $pid->masterPid() && Process::kill($pid->masterPid(), 0);
    }

    /**
     * Return daemonize config.
     */
    protected function isDaemon(): bool
    {
        return Arr::get($this->config, 'server.options.daemonize', false);
    }

    /**
     * Register access log services.
     */
    protected function registerAccessLog()
    {
        $this->laravel->singleton(OutputStyle::class, function () {
            return new OutputStyle($this->input, $this->output);
        });

        $this->laravel->singleton(AccessOutput::class, function () {
            return new AccessOutput(new ConsoleOutput);
        });

        $this->laravel->singleton(AccessLog::class, function (Container $container) {
            return new AccessLog($container->make(AccessOutput::class));
        });
    }

    /**
     * Kill process.
     *
     * @param int $sig
     * @param int $wait
     *
     * @return bool
     */
    protected function killProcess(int $sig, int $wait = 0): bool
    {
        Process::kill($this->laravel->make(PidManager::class)->read()->masterPid(), $sig);


        if ($wait) {
            $start = time();

            do {
                if (! $this->isRunning()) {
                    break;
                }

                usleep(100000);
            } while (time() < $start + $wait);
        }

        return $this->isRunning();
    }


    /**
     * ================================================================================
     */

    /**
     * Run swoole_http_server.
     */
    protected function start()
    {
        if ($this->isRunning()) {
            $this->error('Failed! swoole_http_server process is already running.');

            return;
        }

        $host = Arr::get($this->config, 'server.host');
        $port = Arr::get($this->config, 'server.port');
        $accessLogEnabled = Arr::get($this->config, 'server.access_log');

        $this->info('Starting swoole http server...');
        if ($this->isDaemon()) {
            $this->info(
                '> (You can run this command to ensure the ' .
                'swoole_http_server process is running: ps aux|grep "swoole")'
            );
        }

        /** @var Manager $manager */
        $manager = $this->laravel->make(Manager::class);

        if ($accessLogEnabled) {
            $this->registerAccessLog();
        }
        $this->info("Swoole http server started: http://{$host}:{$port}");
        $manager->run();
    }

    /**
     * Stop swoole_http_server.
     */
    protected function stop()
    {
        if (!$this->isRunning()) {
            $this->error("Failed! There is no swoole_http_server process running.");

            return;
        }

        $this->info('Stopping swoole http server...');

        $isRunning = $this->killProcess(SIGTERM, 15);

        if ($isRunning) {
            $this->error('Unable to stop the swoole_http_server process.');

            return;
        }

        // I don't known why Swoole didn't trigger "onShutdown" after sending SIGTERM.
        // So we should manually remove the pid file.
        $this->laravel->make(PidManager::class)->delete();

        $this->info('> success');
    }

    /**
     * Restart swoole http server.
     */
    protected function restart()
    {
        if ($this->isRunning()) {
            $this->stop();
        }

        $this->start();
    }

    /**
     * Reload.
     */
    protected function reload()
    {
        if (!$this->isRunning()) {
            $this->error("Failed! There is no swoole_http_server process running.");

            return;
        }

        $this->info('Reloading swoole_http_server...');

        if (!$this->killProcess(SIGUSR1)) {
            $this->error('> failure');

            return;
        }

        $this->info('> success');
    }

    /**
     * Display PHP and Swoole misc info.
     */
    protected function infos()
    {
        $this->showInfos();
    }

    /**
     * Display PHP and Swoole miscs infos.
     */
    protected function showInfos()
    {
        $isRunning = $this->isRunning();
        $host = Arr::get($this->config, 'server.host');
        $port = Arr::get($this->config, 'server.port');
        $reactorNum = Arr::get($this->config, 'server.options.reactor_num');
        $workerNum = Arr::get($this->config, 'server.options.worker_num');
        $taskWorkerNum = Arr::get($this->config, 'server.options.task_worker_num');
        $isWebsocket = Arr::get($this->config, 'websocket.enabled');

        $queueConfig = $this->laravel->make('config')->get('queue');

        // lookup for set swoole driver
        $isDefinedSwooleDriver = in_array(
                'swoole',
                array_column(
                    $queueConfig['connections'] ?? [],
                    'driver'
                ),
                true
            ) || ($queueConfig['default'] ?? null) === 'swoole';

        $hasTaskWorker = $isWebsocket || $isDefinedSwooleDriver;

        $logFile = Arr::get($this->config, 'server.options.log_file');
        $pid = $this->laravel->make(PidManager::class)->read();
        $masterPid = $pid->masterPid();
        $managerPid = $pid->managerPid();

        $table = [
            ['PHP Version', 'Version' => phpversion()],
            ['Swoole Version', 'Version' => swoole_version()],
            ['Laravel Version', $this->getApplication()->getVersion()],
            ['Listen IP', $host],
            ['Listen Port', $port],
            ['Server Status', $isRunning ? 'Online' : 'Offline'],
            ['Reactor Num', $reactorNum],
            ['Worker Num', $workerNum],
            ['Task Worker Num', $hasTaskWorker ? $taskWorkerNum : 0],
            ['Websocket Mode', $isWebsocket ? 'On' : 'Off'],
            ['Master PID', $isRunning ? $masterPid : 'None'],
            ['Manager PID', $isRunning && $managerPid ? $managerPid : 'None'],
            ['Log Path', $logFile],
        ];

        $this->table(['Name', 'Value'], $table);
    }
}
