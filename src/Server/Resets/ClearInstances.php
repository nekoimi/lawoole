<?php
/**
 * nekoimi  2021/11/13 17:32
 */

namespace Lawoole\Server\Resets;


use Illuminate\Contracts\Container\Container;
use Lawoole\Contract\ResetContract;
use Lawoole\Server\SandBox;

class ClearInstances implements ResetContract
{

    /**
     * @param Container $app
     * @param SandBox $sandBox
     */
    public function reset(Container $app, SandBox $sandBox): void
    {
        $instances = $sandBox->getConfig()->get('swoole_http.instances', []);

        foreach ($instances as $instance) {
            $app->forgetInstance($instance);
        }
    }
}
