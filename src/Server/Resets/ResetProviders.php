<?php

namespace Lawoole\Server\Resets;

use Illuminate\Contracts\Container\Container;
use Lawoole\Contract\ResetContract;
use Lawoole\Server\SandBox;

class ResetProviders implements ResetContract
{
    /**
     * @var \Illuminate\Contracts\Container\Container
     */
    protected $app;

    /**
     * @param Container $app
     * @param SandBox $sandBox
     */
    public function reset(Container $app, SandBox $sandBox): void
    {
        foreach ($sandBox->getProviders() as $provider) {
            $this->rebindProviderContainer($app, $provider);
            if (method_exists($provider, 'register')) {
                $provider->register();
            }
            if (method_exists($provider, 'boot')) {
                $app->call([$provider, 'boot']);
            }
        }
    }

    /**
     * Rebind service provider's container.
     *
     * @param $app
     * @param $provider
     */
    protected function rebindProviderContainer($app, $provider)
    {
        $closure = function () use ($app) {
            $this->app = $app;
        };

        $resetProvider = $closure->bindTo($provider, $provider);
        $resetProvider();
    }
}
