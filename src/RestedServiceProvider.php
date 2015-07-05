<?php
namespace Rested\Laravel;

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\ServiceProvider;
use Ramsey\Uuid\Uuid;
use Rested\Definition\Parameter;
use Rested\Helper;
use Rested\Laravel\Http\Middleware\RoleCheckMiddleware;
use Rested\Security\AccessVoter;

class RestedServiceProvider extends ServiceProvider
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
        $this->registerRoutes();
    }

    public function getPrefix()
    {
        return config('rested.prefix');
    }

    /**
     * {@inheritdoc}
     */
    public function register()
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/rested.php', 'rested');

        $app = $this->app;
        $app['rested'] = $app->instance('Rested\RestedServiceProvider', $this);

        $app->extend('security.voters', function(array $voters) {
            return array_merge($voters, [new AccessVoter()]);
        });
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

        $this->processResources();
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

        $router->group([], function() use ($router, $resources) {
            foreach ($resources as $class) {
                $this->addRoutesFromResourceController($router, $class);
            }
        });
    }

    private function addRoutesFromResourceController(Router $router, $class)
    {
        $obj = new $class();
        $def = $obj->getDefinition();

        foreach ($def->getActions() as $action) {
            $href = $action->getUrl();
            $routeName = $action->getRouteName();
            $callable = sprintf('%s@%s', $class, $action->getCallable());
            $route = $router->{$action->getVerb()}($href, [
                'as' => $routeName,
                'rested_type' => $action->getType(),
                'uses' => $callable,
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