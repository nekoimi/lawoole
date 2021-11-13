<?php
/**
 * nekoimi  2021/11/12 10:42
 */

namespace Lawoole\Traits;


use Illuminate\Contracts\Config\Repository as ConfigContract;
use Illuminate\Contracts\Container\Container;
use Lawoole\Contract\ResetContract;

trait ResetApplication
{
    /**
     * @var ConfigContract
     */
    protected $config;

    /**
     * @var array
     */
    protected $providers = [];

    /**
     * @var ResetContract[]
     */
    protected $resets = [];

    /**
     * @var string[]
     */
    protected $defaultResetClass = [
        \Lawoole\Server\Resets\BindRequest::class,
        \Lawoole\Server\Resets\ClearInstances::class,
        \Lawoole\Server\Resets\RebindKernelContainer::class,
        \Lawoole\Server\Resets\RebindRouterContainer::class,
        \Lawoole\Server\Resets\RebindViewContainer::class,
        \Lawoole\Server\Resets\ResetConfig::class,
        \Lawoole\Server\Resets\ResetCookie::class,
        \Lawoole\Server\Resets\ResetProviders::class,
        \Lawoole\Server\Resets\ResetSession::class,
    ];

    /**
     * Init config
     */
    protected function initialConfig(): void
    {
        $this->config = clone $this->getApp()->make(ConfigContract::class);
    }

    /**
     * @return ConfigContract
     */
    public function getConfig(): ConfigContract
    {
        return $this->config;
    }

    /**
     * Init providers
     */
    protected function initialProviders(): void
    {
        $app = $this->getApp();
        $providers = $this->getConfig()->get('swoole_http.providers', []);

        foreach ($providers as $provider) {
            if (class_exists($provider) && !in_array($provider, $this->providers)) {
                $providerClass = new $provider($app);
                $this->providers[$provider] = $providerClass;
            }
        }
    }

    /**
     * @return array
     */
    public function getProviders(): array
    {
        return $this->providers;
    }

    /**
     * Init default resets
     */
    protected function initialDefaultResets(): void
    {
        $app = $this->getApp();

        foreach ($this->defaultResetClass as $resetClass) {
            $resetInstance = $app->make($resetClass);
            if ($resetInstance instanceof ResetContract) {
                $this->resets[$resetClass] = $resetInstance;
            }
        }
    }

    /**
     * Init resets
     */
    protected function initialResets(): void
    {
        $this->initialDefaultResets();

        $app = $this->getApp();
        $resets = $this->config->get('swoole_http.resets', []);

        foreach ($resets as $resetClass) {
            $resetInstance = $app->make($resetClass);
            if ($resetInstance instanceof ResetContract) {
                $this->resets[$resetClass] = $resetInstance;
            }
        }
    }

    /**
     * @return ResetContract[]
     */
    public function getResets(): array
    {
        return $this->resets;
    }

    /**
     * App Reset
     * @param Container $app
     */
    public function reset(Container $app): void
    {
        foreach ($this->resets as $reset) {
            $reset->reset($app, $this->self);
        }
    }
}
