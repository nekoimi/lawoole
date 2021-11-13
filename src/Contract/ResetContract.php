<?php
/**
 * nekoimi  2021/11/13 15:39
 */

namespace Lawoole\Contract;


use Illuminate\Contracts\Container\Container;
use Lawoole\Server\SandBox;

interface ResetContract
{
    /**
     * @param Container $app
     * @param SandBox $sandBox
     */
    public function reset(Container $app, SandBox $sandBox): void;
}
