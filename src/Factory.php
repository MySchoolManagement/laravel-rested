<?php
namespace Rested\Laravel;

use Illuminate\Routing\RouteCollection;
use Rested\Compiler\Compiler;
use Rested\Compiler\CompilerCache;
use Rested\Compiler\CompilerCacheInterface;
use Rested\Definition\Compiled\CompiledResourceDefinitionInterface;
use Rested\Definition\ResourceDefinition;
use Rested\Http\Context;
use Rested\FactoryInterface;
use Rested\Http\CollectionResponse;
use Rested\Http\ContextInterface;
use Rested\Http\InstanceResponse;
use Rested\Laravel\Transforms\LaravelTransform;
use Rested\NameGenerator;
use Rested\RestedServiceInterface;
use Rested\Transforms\DefaultTransformMapping;

class Factory implements FactoryInterface
{

    /**
     * @var \Rested\NameGenerator
     */
    private $nameGenerator;

    /**
     * @var RestedServiceInterface
     */
    private $restedService;

    /**
     * @var \Illuminate\Routing\RouteCollection
     */
    private $routes;

    /**
     * @var \Rested\Laravel\UrlGenerator
     */
    private $urlGenerator;

    public function __construct(
        RouteCollection $routes,
        UrlGenerator $urlGenerator,
        RestedServiceInterface $restedService,
        NameGenerator $nameGenerator,
        CompilerCacheInterface $compilerCache)
    {
        $this->compilerCache = $compilerCache;
        $this->nameGenerator = $nameGenerator;
        $this->routes = $routes;
        $this->restedService = $restedService;
        $this->urlGenerator = $urlGenerator;
    }

    /**
     * {@inheritdoc}
     */
    public function createCollectionResponse(CompiledResourceDefinitionInterface $resourceDefinition, ContextInterface $context, $href, array $items = [], $total = null)
    {
        return new CollectionResponse($this->restedService, $this->urlGenerator, $resourceDefinition, $context, $href, $items, $total);
    }

    /**
     * {@inheritdoc}
     */
    public function createContext(array $parameters, $actionType, $routeName, CompiledResourceDefinitionInterface $resourceDefinition)
    {
        return new Context(
            $parameters,
            $actionType,
            $routeName,
            $resourceDefinition
        );
    }

    /**
     * {@inheritdoc}
     */
    public function createResourceDefinition($name, $controllerClass, $modelClass)
    {
        return new ResourceDefinition($this, $name, $controllerClass, $this->createTransform(), $this->createTransformMapping($modelClass));
    }

    /**
     * {@inheritdoc}
     */
    public function createTransform()
    {
        $transform = new LaravelTransform();
        $transform->setServices($this, $this->compilerCache, $this->urlGenerator);

        return $transform;
    }

    /**
     * {@inheritdoc}
     */
    public function createTransformMapping($modelClass)
    {
        return new DefaultTransformMapping($modelClass);
    }

    /**
     * {@inheritdoc}
     */
    public function createInstanceResponse(
        CompiledResourceDefinitionInterface $resourceDefinition,
        ContextInterface $context,
        $href,
        array $data,
        $instance = null)
    {
        return new InstanceResponse($this->restedService, $this->urlGenerator, $resourceDefinition, $context, $href, $data, $instance);
    }
}
