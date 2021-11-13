<?php
/**
 * nekoimi  2021/11/13 17:24
 */

namespace Lawoole\Server\Resets;


use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Lawoole\Contract\ResetContract;
use Lawoole\Server\SandBox;

class BindRequest implements ResetContract
{

    /**
     * @param Container $app
     * @param SandBox $sandBox
     */
    public function reset(Container $app, SandBox $sandBox): void
    {
        $request = $sandBox->getRequest();

        if ($request instanceof Request) {
            $app->instance('request', $request);
        }
    }
}
