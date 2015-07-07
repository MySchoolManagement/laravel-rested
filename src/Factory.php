<?php
namespace Rested\Laravel;

use Rested\Definition\Model;
use Rested\Definition\ResourceDefinition;
use Rested\FactoryInterface;
use Rested\Http\CollectionResponse;
use Rested\Http\InstanceResponse;
use Rested\RestedResourceInterface;
use Rested\RestedServiceInterface;
use Rested\UrlGeneratorInterface;

class Factory implements FactoryInterface
{

    private $restedService;

    private $urlGenerator;

    public function __construct(UrlGeneratorInterface $urlGenerator, RestedServiceInterface $restedService)
    {
        $this->restedService = $restedService;
        $this->urlGenerator = $urlGenerator;
    }

    /**
     * {@inheritdoc}
     */
    public function createBasicController($class)
    {
        return new $class($this);
    }

    /**
     * {@inheritdoc}
     */
    public function createCollectionResponse(RestedResourceInterface $resource, array $items = [], $total = 0)
    {
        return new CollectionResponse($this->urlGenerator, $resource, $items, $total);
    }

    /**
     * @return InstanceResponse
     */
    public function createInstanceResponse(RestedResourceInterface $resource, $href, $item)
    {
        return new InstanceResponse($this->urlGenerator, $resource, $href, $item);
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
}