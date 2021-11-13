<?php

namespace Lawoole\Server\Resets;

use Lawoole\Contract\ResetContract;
use Lawoole\Server\SandBox;
use Illuminate\Contracts\Container\Container;

class ResetConfig implements ResetContract
{

    /**
     * @param Container $app
     * @param SandBox $sandBox
     */
    public function reset(Container $app, SandBox $sandBox): void
    {
        $cloneConfig = clone $sandBox->getConfig();
        $app->instance('config', $cloneConfig);
    }
}
