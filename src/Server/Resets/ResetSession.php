<?php

namespace Lawoole\Server\Resets;

use Illuminate\Contracts\Container\Container;
use Lawoole\Contract\ResetContract;
use Lawoole\Server\SandBox;

class ResetSession implements ResetContract
{

    /**
     * @param Container $app
     * @param SandBox $sandBox
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     */
    public function reset(Container $app, SandBox $sandBox): void
    {
        if (isset($app['session'])) {
            $session = $app->make('session');
            $session->flush();
            $session->regenerate();
        }
    }
}
