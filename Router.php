<?php

namespace Sphere\Api;

use Laravel\Lumen\Routing\Router as LumenRouter;
use Sphere\Api\Controllers\AuthController;
use Sphere\Api\Controllers\RestController;

/**
 * @method void get($url, $controller)
 * @method void post($url, $controller)
 * @method void patch($url, $controller)
 * @method void destroy($url, $controller)
 * @method void index($url, $options)
 * @method void create($url, $options)
 * @method void read($url, $options)
 * @method void update($url, $options)
 * @method void delete($url, $options)
 */
class Router
{
    /**
     * Обношение http методов к функциям обработчиков
     */
    const HTTP_VERB_TO_METHOD_MAP = [
        'get'     => 'read',
        'post'    => 'create',
        'patch'   => 'update',
        'destroy' => 'delete',
    ];

    protected $router;
    protected $resources = [];
    protected $currentScope;
    protected $defaultOptions = [];

    protected $restMethods = [
        'index' => ['method' => 'get'],
        'create' => ['method' => 'post'],
        'read' => ['method' => 'get', 'route' => '/{id}'],
        'update' => ['method' => 'patch', 'route' => '/{id}'],
        'delete' => ['method' => 'delete', 'route' => '/{id}'],
    ];

    public function __construct(LumenRouter $router)
    {
        $this->router = $router;

        if ($defaultOptions = config("api.defaultOptions")) {
            $this->setDefaultOptions($defaultOptions);
        }
    }

    public function setDefaultOptions(array $defaultOptions)
    {
        $this->defaultOptions = $defaultOptions;
    }

    public function setCurrentScope($currentScope)
    {
        $this->currentScope = $currentScope;
    }

    public function getCurrentResourceOptions()
    {
        return $this->resources[$this->currentScope] ?? [];
    }

    public function auth(array $options = [])
    {
        if (empty($options['controller'])) {
            $options['controller'] = AuthController::class;
            $options['namespace'] = '';
        }
        if (empty($options['basename'])) {
            $options['basename'] = 'User';
        }
        $options['auth'] = false;
        $options['only'] = []; // Disable REST methods

        $this->resource('auth', (array) $options, function ($router, $options) {
            $router->post('login', ['uses' => $options->controller . '@login']);
            $router->get('user', ['uses' => $options->controller . '@user', 'middleware' => 'auth']);
            $router->get('logout', ['uses' => $options->controller . '@logout']);

            //@todo данный метод связан с авторизацией по jwt - нужно его убрать из пакета
            $router->get('refresh', ['uses' => $options->controller . '@refresh']);

            //@deprecated Используем только get и post
            $router->patch('refresh', ['uses' => $options->controller . '@refresh']);
            $router->delete('logout', ['uses' => $options->controller . '@logout']);
        });
    }

    public function group(array $attributes, \Closure $callback)
    {
        $this->router->group($attributes, function () use ($callback) {
            call_user_func($callback, $this);
        });
    }

    public function resource($name, $options, $custom = null)
    {
        $options = $this->transformOptions($options);

        $this->resources[$name] = $options;

        $middleware = [];
        if ($options->auth) {
            $middleware[] = "auth";
        }
        $middleware[] = "api-scope:{$name}";

        $this->router->group([
            'middleware' => $middleware,
            'prefix' => $name,
            'namespace' => $options->namespace ?? '',
        ], function ($router) use ($options, $custom) {
            // Custom methods
            if ($custom) {
                /** @var \Closure $custom */
                $custom($router, $options);
            }
            // REST methods
            $methods = $this->restMethods;

            if (isset($options->only)) {
                $methods = array_intersect_key($methods, array_flip($options->only));
            }

            foreach ($methods as $action => $opt) {
                $httpMethod = $opt['method'];

                $route = '';

                if (isset($options->route)) {
                    $route = $options->route;
                } elseif (isset($opt['route'])) {
                    $route = $opt['route'];
                }

                $router->$httpMethod($route, [
                    'uses' => $options->controller . '@' . $action
                ]);
            }
        });
    }

    protected function transformOptions($options)
    {
        if (is_string($options)) {
            $options = ['controller' => $options];
        }

        $options = array_merge($this->defaultOptions, $options);

        $options = (object) $options;

        if (!isset($options->auth)) {
            $options->auth = true;
        }

        if (empty($options->controller)) {
            $options->controller = RestController::class;
        } elseif (!isset($options->namespace)) {
            $options->namespace = $this->getNamespace('controller');
        }

        $targets = ['model', 'resource'];

        $basename = $options->basename ?? null;
        foreach ($targets as $targetName) {
            $className = $options->$targetName ?? $basename;
            if ($className) {
                $options->$targetName = $this->getNamespace($targetName, $className);
            }
        }

        return $options;
    }

    protected function getNamespace($namespace, $className = '')
    {
        $namespace = config("api.namespace.{$namespace}");
        return $className ? $namespace . ($namespace ? '\\' :  '') . $className : $namespace;
    }

    /**
     * @param string $method
     * @param array $args
     * @return mixed
     */
    public function __call($method, $args)
    {
        if (array_key_exists($method, $this->restMethods)) {
            [$name, $options] = $args;

            if (is_string($options)) {
                $options = ['controller' => $options];
            }

            $options['only'] = [$method];

            $this->resource($name, $options);

            return null;
        }

        if (array_key_exists($method, static::HTTP_VERB_TO_METHOD_MAP)) {
            [$url, $controller] = $args;

            $this->resource($url, [
                'only'       => [static::HTTP_VERB_TO_METHOD_MAP[$method]],
                'controller' => $controller,
                'route'      => '',
            ]);

            return null;
        }

        return call_user_func_array([$this->router, $method], $args);
    }
}
