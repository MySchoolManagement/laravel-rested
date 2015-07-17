<?php
namespace Rested\Laravel;

use Illuminate\Routing\RouteCollection;
use Rested\Definition\Model;
use Rested\Definition\ResourceDefinition;
use Rested\FactoryInterface;
use Rested\Http\CollectionResponse;
use Rested\Http\InstanceResponse;
use Rested\RequestContext;
use Rested\RestedResourceInterface;
use Rested\RestedServiceInterface;
use Rested\UrlGeneratorInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

class Factory implements FactoryInterface
{

    /**
     * @var \Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface
     */
    private $authorizationChecker;

    /**
     * @var RequestContext[]
     */
    private $contexts = [];

    /**
     * @var RequestStack
     */
    private $requestStack;

    /**
     * @var RestedServiceInterface
     */
    private $restedService;

    /**
     * @var \Illuminate\Routing\RouteCollection
     */
    private $routes;

    private $urlGenerator;

    public function __construct(
        RouteCollection $routes,
        UrlGeneratorInterface $urlGenerator,
        RestedServiceInterface $restedService)
    {
        $this->routes = $routes;
        $this->restedService = $restedService;
        $this->urlGenerator = $urlGenerator;
    }

    /**
     * {@inheritdoc}
     */
    public function createBasicController($class)
    {
        return new $class($this, $this->urlGenerator);
    }

    /**
     * {@inheritdoc}
     */
    public function createBasicControllerFromRouteName($routeName)
    {
        if (($route = $this->routes->getByName($routeName)) === null) {
            return null;
        }

        $action = $route->getAction();
        $controller = $action['controller'];

        list($class, $method) = explode('@', $controller);

        return $this->createBasicController($class);
    }

    /**
     * {@inheritdoc}
     */
    public function createCollectionResponse(RestedResourceInterface $resource, array $items = [], $total = 0)
    {
        return new CollectionResponse($this, $this->urlGenerator, $resource, $items, $total);
    }

    /**
     * @return InstanceResponse
     */
    public function createInstanceResponse(RestedResourceInterface $resource, $href, $item, $instance = null)
    {
        return new InstanceResponse($this, $this->urlGenerator, $resource, $href, $item, $instance);
    }

    /**
     * {@inheritdoc}
     */
    public function createDefinition($name, RestedResourceInterface $resource, $class)
    {
        return new ResourceDefinition($name, $resource, $this->restedService, $class);
    }

    /**
     * {@inheritdoc}
     */
    public function createModel(ResourceDefinition $resourceDefinition, $class)
    {
        return new Model($resourceDefinition, $class);
    }

    /**
     * {@inheritdoc}
     */
    public function resolveContextForRequest(Request $request, RestedResourceInterface $resource)
    {
        foreach ($this->contexts as $item) {
            if ($item['request'] === $request) {
                return $item['context'];
            }
        }

        $item = [
            'context' => new RequestContext($request, $resource),
            'request' => $request,
        ];

        $this->contexts[] = $item;

        return $item['context'];
    }
}
