<?php

namespace Lawoole\Server\Resets;

use Illuminate\Contracts\Container\Container;
use Lawoole\Contract\ResetContract;
use Lawoole\Server\SandBox;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class RebindRouterContainer implements ResetContract
{
    /**
     * @var \Illuminate\Contracts\Container\Container
     */
    public $container;

    /**
     * @var mixed
     */
    public $routes;

    public function reset(Container $app, SandBox $sandBox): void
    {
        if ($sandBox->isLaravel()) {
            $router = $app->make('router');
            $request = $sandBox->getRequest();
            $closure = function () use ($app, $request) {
                $this->container = $app;
                if (is_null($request)) {
                    return;
                }
                try {
                    /** @var mixed $route */
                    $route = $this->routes->match($request);
                    // clear resolved controller
                    if (property_exists($route, 'container')) {
                        $route->controller = null;
                    }
                    // rebind matched route's container
                    $route->setContainer($app);
                } catch (NotFoundHttpException $e) {
                    // do nothing
                }
            };

            $resetRouter = $closure->bindTo($router, $router);
            $resetRouter();
        } else {
            // lumen router only exists after lumen 5.5
            if (property_exists($app, 'router')) {
                $app->router->app = $app;
            }
        }
    }
}
