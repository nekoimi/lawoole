<?php

namespace Lawoole\Server\Resets;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Http\Kernel;
use Lawoole\Contract\ResetContract;
use Lawoole\Server\SandBox;

class RebindKernelContainer implements ResetContract
{
    /**
     * @var \Illuminate\Contracts\Container\Container
     */
    public $app;

    /**
     * @param Container $app
     * @param SandBox $sandBox
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     */
    public function reset(Container $app, SandBox $sandBox): void
    {
        if ($sandBox->isLaravel()) {
            $kernel = $app->make(Kernel::class);

            $closure = function () use ($app) {
                $this->app = $app;
            };

            $resetKernel = $closure->bindTo($kernel, $kernel);
            $resetKernel();
        }
    }
}
