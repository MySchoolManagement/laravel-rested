<?php
namespace Rested\Laravel;

use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\ServiceProvider;
use Nocarrier\Hal;
use Ramsey\Uuid\Uuid;
use Rested\Definition\Parameter;
use Rested\Helper;
use Rested\Laravel\Factory;
use Rested\Laravel\UrlGenerator;
use Rested\Laravel\Http\Middleware\RoleCheckMiddleware;
use Rested\RestedResourceInterface;
use Rested\RestedServiceInterface;
use Rested\Security\AccessVoter;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Symfony\Component\HttpKernel\HttpKernelInterface;

class RestedServiceProvider extends ServiceProvider implements RestedServiceInterface
{

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
        $this->addPublishedFiles();
        $this->loadTranslationsFrom(__DIR__ . '/../lang', 'Rested');
        $this->registerLateServices();
        $this->registerRoutes();
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

        return Hal::fromJson($response, 1);
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
        $kernel = $this->app->make(\Illuminate\Contracts\Http\Kernel::class);

        $response = $kernel->handle(
            $request = $request, HttpKernelInterface::SUB_REQUEST
        );
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

        $self = $this;
        $app = $this->app;
        $app['rested'] = $app->instance('Rested\RestedServiceInterface', $this);

        $app->bindShared('Rested\UrlGeneratorInterface', function($app) {
            return new UrlGenerator($app['url']);
        });

        $app->extend('security.voters', function(array $voters) use ($app) {
            return array_merge($voters, [new AccessVoter($app['Symfony\Component\Security\Core\Role\RoleHierarchyInterface'])]);
        });
    }

    private function registerLateServices()
    {
        // this depends on security services which in turn need the session
        $app = $this->app;
        $app->bindShared('Rested\FactoryInterface', function($app) {
            return new Factory($app['routes'], $app['Rested\UrlGeneratorInterface'], $this, $app['security.authorization_checker']);
        });
        $app->alias('Rested\FactoryInterface', 'rested.factory');
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

        // add some core resources
        //$this->addResource('Rested\Resources\EntrypointResource');

        //$x = $this->app['security.authorization_checker'];
        //$this->processResources();
    }

    /**
     * {@inheritdoc}
     */
    public function provides()
    {
        return ['rested'];
    }

    public function addResource($class)
    {
        $this->resourcesFromServices[] = $class;
    }

    private function processResources()
    {
        $app = $this->app;
        $router = $app['router'];
        $resources = array_merge(config('rested.resources'), $this->resourcesFromServices);
        $prefix = $this->getPrefix();

        $router->group([], function() use ($app, $router, $resources) {
            foreach ($resources as $class) {
                $this->addRoutesFromResourceController($router, $app['rested.factory']->createBasicController($class));
            }
        });
    }

    private function addRoutesFromResourceController(Router $router, RestedResourceInterface $resource)
    {
        $def = $resource->getDefinition();
        $class = get_class($resource);

        foreach ($def->getActions() as $action) {
            $href = $action->getUrl();
            $routeName = $action->getRouteName();
            $callable = sprintf('%s@preHandle', $class);
            $route = $router->{$action->getMethod()}($href, [
                'as' => $routeName,
                'uses' => $callable,
                '_rested_action' => $action->getType(),
                '_rested_controller' => $action->getCallable(),
            ]);

             // add constraints and validators to the cache
             foreach ($action->getTokens() as $token) {
                 if ($token->acceptAnyValue() === false) {
                     $route->where($token->getName(), Parameter::getValidatorPattern($token->getType()));
                 }
             }
        }
    }
}
