<?php
namespace Rested\Laravel;

use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use Nocarrier\Hal;
use Rested\Compiler\Compiler;
use Rested\Compiler\CompilerCache;
use Rested\Compiler\CompilerCacheInterface;
use Rested\Compiler\CompilerInterface;
use Rested\Definition\Parameter;
use Rested\Definition\ResourceDefinition;
use Rested\Http\RequestParser;
use Rested\Laravel\Http\Middleware\RequestIdMiddleware;
use Rested\NameGenerator;
use Rested\ResourceInterface;
use Rested\RestedResourceInterface;
use Rested\RestedServiceInterface;
use Rested\Security\RoleVoter;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\HttpKernelInterface;

// TODO: refactor RestedServiceInterface into another service
class RestedServiceProvider extends ServiceProvider implements RestedServiceInterface
{

    const CACHE_FILE = 'rested/cache';

    /**
     * @var \Rested\RequestContext[]
     */
    private $contexts = [];

    /**
     * @var \Symfony\Component\HttpFoundation\RequestStack
     */
    private $requestStack;

    /**
     * @var string[]
     */
    private $resourcesFromServices = [];

    /**
     * Adds published files to the service descriptor.
     *
     * @returns void
     */
    private function addPublishedFiles()
    {
        $this->publishes([
            __DIR__ . '/../config/rested.php' => config_path('rested.php'),
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function boot()
    {
        $this->requestStack = new RequestStack();

        $this->addPublishedFiles();
        $this->loadTranslationsFrom(__DIR__ . '/../lang', 'Rested');

        $this->app['router']->middleware('request_id', RequestIdMiddleware::class);

        $this->registerLateServices();
        $this->registerRoutes();
        $this->handleCache();
    }

    /**
     * {@inheritdoc}
     */
    public function execute($url, $method = 'get', $data = [], &$statusCode = null)
    {
        $parentRequest = $this->app['router']->getCurrentRequest();

        if (mb_substr($url, 0, 4) === 'http') {
            $response = $this->performRemoteRequest($parentRequest, $url, $method, $data, $statusCode);
        } else {
            $response = $this->performLocalRequest($parentRequest, $url, $method, $data, $statusCode);
        }

        return Hal::fromJson($response, 3);
    }

    /**
     * {@inheritdoc}
     */
    public function findActionByRouteName($routeName)
    {
        $resourceDefinition = $this->app['rested.compiler_cache']->findResourceDefinition($routeName);

        if ($resourceDefinition !== null) {
            return $resourceDefinition->findActionByRouteName($routeName);
        }

        return null;
    }

    public function getPrefix()
    {
        return config('rested.prefix');
    }

    public function getResources()
    {
        return [];
    }

    private function performLocalRequest(Request $parentRequest = null, $url, $method, $data, &$statusCode = null)
    {
        $urlInfo = parse_url($url);

        if ((array_key_exists('query', $urlInfo) == true) && (mb_strlen($urlInfo['query']) > 0)) {
            mb_parse_str($urlInfo['query'], $_GET);
        }

        // create the request object
        $cookies = $parentRequest ? $parentRequest->cookies->all() : [];
        $server = $parentRequest ? $parentRequest->server->all() : [];
        $request = Request::createFromBase(SymfonyRequest::create($url, $method, [], $cookies, [], $server, json_encode($data)));
        $request->headers->set('Content-Type', 'application/json');

        if ($parentRequest !== null) {
            $locale = $parentRequest->getLocale();

            $request->setSession($parentRequest->getSession());
            $request->setLocale($locale);

            $request->headers->set('Accept-Language', [$locale]);
        }

        // execute the request
        // TODO: handle errors gracefully
        $kernel = $GLOBALS['kernel'];//$this->app->make(\Illuminate\Contracts\Http\Kernel::class);
        $response = $kernel->handle($request, HttpKernelInterface::SUB_REQUEST);

        $statusCode = $response->getStatusCode();
        $content = $response->getContent();

        return $content;
    }

    /**
     * {@inheritdoc}
     */
    public function register()
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/rested.php', 'rested');

        $app = $this->app;
        $app->instance('Rested\RestedServiceInterface', $this);

        $app->bindShared('Symfony\Component\HttpFoundation\RequestStack', function() {
            return new RequestStack();
        });
        $app->alias('Symfony\Component\HttpFoundation\RequestStack', 'request_stack');

        $app->bindShared('Rested\UrlGeneratorInterface', function($app) {
            return new UrlGenerator($app['url'], $this->getPrefix());
        });
        $app->alias('Rested\UrlGeneratorInterface', 'rested.url_generator');

        $app->bindShared('Rested\NameGenerator', function() {
            return new NameGenerator();
        });
        $app->alias('Rested\NameGenerator', 'rested.name_generator');

        $app->bindShared('Rested\Compiler\CompilerCacheInterface', function($app) {
            return new CompilerCache($app['rested.factory'], $app['rested.url_generator']);
        });
        $app->alias('Rested\Compiler\CompilerCacheInterface', 'rested.compiler_cache');

        $app->extend('security.voters', function(array $voters) use ($app) {
            return array_merge($voters, [
                new RoleVoter(
                    $app['Symfony\Component\Security\Core\Role\RoleHierarchyInterface'],
                    $app['rested.name_generator']
                )
            ]);
        });
    }

    private function registerLateServices()
    {
        // these depends on security services which in turn need the session
        $app = $this->app;
        $app->bindShared('Rested\FactoryInterface', function($app) {
            return new Factory(
                $app['routes'],
                $app['Rested\UrlGeneratorInterface'],
                $this,
                $app['rested.name_generator'],
                $app['rested.compiler_cache']
            );
        });
        $app->alias('Rested\FactoryInterface', 'rested.factory');

        $app->bindShared('Rested\Compiler\CompilerInterface', function($app) {
            return new Compiler(
                $app['rested.factory'],
                $app['rested.name_generator'],
                $app['rested.url_generator']
            );
        });
        $app->alias('Rested\Compiler\CompilerInterface', 'rested.compiler');
    }

    /**
     * Registers the routes created by this service.
     *
     * @returns void
     */
    private function registerRoutes()
    {
        if ($this->app->routesAreCached() === true) {
            return;
        }

        $this->processResources();
    }

    /**
     * {@inheritdoc}
     */
    public function resolveContextFromRequest(SymfonyRequest $request, ResourceInterface $resource)
    {
        $requestId = $request->headers->get(RequestIdMiddleware::SUB_HEADER);

        if (array_key_exists($requestId, $this->contexts) === true) {
            return $this->contexts[$requestId];
        }

        $spec = $request->get('_rested');

        $requestParser = new RequestParser();
        $requestParser->parse($request->getRequestUri(), $request->query->all());

        $cache = $this->app['rested.compiler_cache'];
        $cache->setAuthorizationChecker($this->app['security.authorization_checker']);

        $factory = $this->app['rested.factory'];
        $compiledResourceDefinition = $cache->findResourceDefinition($spec['route_name']);

        $context = $factory->createContext(
            $requestParser->getParameters(),
            $spec['action'],
            $spec['route_name'],
            $compiledResourceDefinition
        );

        return ($this->contexts[$requestId] = $context);
    }

    /**
     * {@inheritdoc}
     */
    public function provides()
    {
        return [
            'request_stack',
            'rested',
            'rested.compiler',
            'rested.compiler_cache',
            'rested.factory',
            'rested.name_generator',
            'rested.url_generator',
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function addResource($class)
    {
        $this->resourcesFromServices[] = $class;
    }

    public function processResources(CompilerCacheInterface $cache = null)
    {
        $app = $this->app;
        $resources = array_merge(config('rested.resources'), $this->resourcesFromServices);

        $router = $app['router'];
        $factory = $app['rested.factory'];
        $compiler = $app['rested.compiler'];
        $cache = $cache ?: $app['rested.compiler_cache'];

        $attributes = [
            'middleware' => 'request_id',
        ];

        $router->group($attributes, function() use ($compiler, $cache, $factory, $router, $resources) {
            foreach ($resources as $class) {
                $this->addRoutesFromResourceDefinition($compiler, $cache, $router, $class::createResourceDefinition($factory), $class);
            }
        });
    }

    private function addRoutesFromResourceDefinition(
        CompilerInterface $compiler,
        CompilerCacheInterface $cache,
        Router $router,
        ResourceDefinition $definition,
        $resourceClass)
    {
        $compiledDefinition = $compiler->compile($definition);

        foreach ($compiledDefinition->getActions() as $action) {
            $href = $action->getEndpointUrl(false);
            $routeName = $action->getRouteName();
            $controller = sprintf('%s@preHandle', $resourceClass);
            $route = $router->{$action->getHttpMethod()}($href, [
                'as' => $routeName,
                'uses' => $controller,
                '_rested' => [
                    'action' => $action->getType(),
                    'controller' => $action->getControllerName(),
                    'route_name' => $routeName,
                ],
            ]);

            // add constraints and validators to the cache
            foreach ($action->getTokens() as $token) {
                if ($token->acceptAnyValue() === false) {
                    $route->where($token->getName(), Parameter::getValidatorPattern($token->getDataType()));
                }
            }

            $cache->registerResourceDefinition($routeName, $compiledDefinition);
        }
    }

    private function handleCache()
    {
        $path = $this->app->basePath().'/bootstrap/cache/rested.compiler_cache.php';
        $cache = $this->app['rested.compiler_cache'];
        $cache->setServices($this->app['rested.factory'], $this->app['rested.url_generator']);

        if ($this->app->routesAreCached() === true) {
            $data = require $path;
            $cache->hydrate($data);
        } else {
            $data = base64_encode($cache->serialize());
            file_put_contents($path, '<?php return base64_decode("'.$data.'");');
        }
    }
}
