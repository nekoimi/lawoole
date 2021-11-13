<?php
/**
 * nekoimi  2021/10/30 0:44
 */

namespace Lawoole\Middleware;


use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Lawoole\Server\AccessOutput;

class AccessLog
{

    /**
     * @var \Lawoole\Server\AccessOutput
     */
    protected $output;

    /**
     * AccessLog constructor.
     * @param \Lawoole\Server\AccessOutput $output
     */
    public function __construct(AccessOutput $output)
    {
        $this->output = $output;
    }

    /**
     * Handle the incoming request.
     *
     * @param  \Illuminate\Http\Request $request
     * @param  \Closure $next
     *
     * @return mixed
     */
    public function handle($request, \Closure $next)
    {
        return $next($request);
    }

    /**
     * Handle the outgoing request and response.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Symfony\Component\HttpFoundation\Response $response
     */
    public function terminate(Request $request, Response $response)
    {
        $this->output->log($request, $response);
    }
}
