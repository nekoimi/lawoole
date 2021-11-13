<?php

namespace Lawoole\Server\Resets;

use Illuminate\Contracts\Container\Container;
use Lawoole\Contract\ResetContract;
use Lawoole\Server\SandBox;

class RebindViewContainer implements ResetContract
{
    /**
     * @var Container
     */
    public $container;

    /**
     * @var array
     */
    public $shared;

    /**
     * @param Container $app
     * @param SandBox $sandBox
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     */
    public function reset(Container $app, SandBox $sandBox): void
    {
        $view = $app->make('view');

        $closure = function () use ($app) {
            $this->container = $app;
            $this->shared['app'] = $app;
        };

        $resetView = $closure->bindTo($view, $view);
        $resetView();
    }
}
