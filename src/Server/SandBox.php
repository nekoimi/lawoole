<?php
/**
 * nekoimi  2021/11/13 14:27
 */

namespace Lawoole\Server;


use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Facade;
use Lawoole\Coroutine\Context;
use Lawoole\Traits\ResetApplication;
use Illuminate\Contracts\Http\Kernel as KernelContract;
use Illuminate\Http\Request as IlluminateRequest;
use Illuminate\Http\Response as IlluminateResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;


/**
 * Class SandBox
 * @package Lawoole\Server
 *
 * 沙箱环境
 */
class SandBox
{
    use ResetApplication;

    /**
     * @var SandBox
     */
    protected $self;

    /**
     * @var Container
     */
    protected $app;

    /**
     * @var string
     */
    protected $framework = 'laravel';

    /**
     * SandBox constructor.
     * @param Container $app
     * @param string|null $framework
     */
    public function __construct(Container $app, string $framework = null)
    {
        $this->self = $this;
        $this->app = $app;
        $this->setFramework($framework ?: $this->framework);
        $this->initialize();
    }

    /**
     * @return Container
     */
    public function getApp(): Container
    {
        return $this->app;
    }

    /**
     * @return Container
     */
    public function getAppSnapshot(): Container
    {
        $appSnapshot = Context::getApp();
        if ($appSnapshot instanceof Container) {
            return $appSnapshot;
        }

        $appSnapshot = clone $this->getApp();

        Context::setApp($appSnapshot);

        return $appSnapshot;
    }

    /**
     * @param string $framework
     */
    public function setFramework(string $framework): void
    {
        $this->framework = $framework;
    }

    /**
     * @return string
     */
    public function getFramework(): string
    {
        return $this->framework;
    }

    /**
     * Initialize
     */
    public function initialize()
    {
        $this->initialConfig();
        $this->initialProviders();
        $this->initialResets();
    }

    /**
     * Begin
     */
    public function begin()
    {
        $appSnapshot = $this->getAppSnapshot();
        $this->setInstance($appSnapshot);
        $this->reset($appSnapshot);
    }

    /**
     * End, reset
     */
    public function end()
    {
        $this->setInstance($this->getApp());
        Context::clear();
    }

    /**
     * Replace app's self bindings.
     *
     * @param \Illuminate\Container\Container|Container $app
     */
    public function setInstance($app)
    {
        $app->instance('app', $app);
        $app->instance(\Illuminate\Container\Container::class, $app);

        if ($this->framework === 'lumen') {
            if (class_exists("Laravel\Lumen\Application::class")) {
                $app->instance("Laravel\Lumen\Application::class", $app);
            }
        }

        \Illuminate\Container\Container::setInstance($app);
        Context::setApp($app);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);
    }

    /**
     * @param IlluminateRequest $request
     */
    public function setRequest(IlluminateRequest $request): void
    {
        Context::set('_request', $request);
    }

    /**
     * @return IlluminateRequest
     */
    public function getRequest(): ?IlluminateRequest
    {
        return Context::get('_request');
    }

    /**
     * Run framework.
     *
     * @param IlluminateRequest $request
     * @return IlluminateResponse
     * @throws \ReflectionException
     */
    public function run(IlluminateRequest $request): IlluminateResponse
    {
        $this->setRequest($request);

        $shouldUseOb = $this->getConfig()->get('swoole_http.ob_output', true);

        if ($shouldUseOb) {
            return $this->prepareObResponse($request);
        }

        return $this->prepareResponse($request);
    }

    /**
     * Handle request for ob output.
     *
     * @param \Illuminate\Http\Request $request
     * @throws \ReflectionException
     */
    protected function prepareObResponse(IlluminateRequest $request)
    {
        ob_start();

        // handle request with laravel or lumen
        $response = $this->handleRequest($request);

        // prepare content for ob
        $content = '';
        $isFile = false;
        if ($isStream = $response instanceof StreamedResponse) {
            $response->sendContent();
        } elseif ($response instanceof SymfonyResponse) {
            $content = $response->getContent();
        } elseif (!$isFile = $response instanceof BinaryFileResponse) {
            $content = (string)$response;
        }

        // process terminating logics
        $this->terminate($request, $response);

        // append ob content to response
        if (!$isFile && ob_get_length() > 0) {
            if ($isStream) {
                $response->output = ob_get_contents();
            } else {
                $response->setContent(ob_get_contents() . $content);
            }
        }

        ob_end_clean();

        return $response;
    }

    /**
     * Handle request for non-ob case.
     *
     * @param \Illuminate\Http\Request $request
     * @throws \ReflectionException
     */
    protected function prepareResponse(IlluminateRequest $request)
    {
        // handle request with laravel or lumen
        $response = $this->handleRequest($request);

        // process terminating logics
        $this->terminate($request, $response);

        return $response;
    }

    /**
     * Handle request through Laravel or Lumen.
     */
    protected function handleRequest(IlluminateRequest $request)
    {
        if ($this->isLaravel()) {
            return $this->getKernel()->handle($request);
        }

        return $this->getAppSnapshot()->dispatch($request);
    }

    /**
     * Get Laravel kernel.
     */
    protected function getKernel()
    {
        return $this->getAppSnapshot()->make(KernelContract::class);
    }

    /**
     * Return if it's Laravel app.
     */
    public function isLaravel(): bool
    {
        return $this->framework === 'laravel';
    }

    /**
     * @param IlluminateRequest $request
     * @param IlluminateResponse $response
     * @throws \ReflectionException
     */
    public function terminate(IlluminateRequest $request, IlluminateResponse $response): void
    {
        if ($this->isLaravel()) {
            $this->getKernel()->terminate($request, $response);

            return;
        }

        $app = $this->getAppSnapshot();
        $reflection = new \ReflectionObject($app);

        $middleware = $reflection->getProperty('middleware');
        $middleware->setAccessible(true);

        $callTerminableMiddleware = $reflection->getMethod('callTerminableMiddleware');
        $callTerminableMiddleware->setAccessible(true);

        if (count($middleware->getValue($app)) > 0) {
            $callTerminableMiddleware->invoke($app, $response);
        }
    }
}
