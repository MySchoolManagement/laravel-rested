<?php
namespace Rested\Laravel;

use Rested\Definition\Model;
use Rested\Definition\ResourceDefinition;
use Rested\FactoryInterface;
use Rested\RestedResourceInterface;
use Rested\RestedServiceInterface;

class Factory implements FactoryInterface
{

    private $restedService;

    public function __construct(RestedServiceInterface $restedService)
    {
        $this->restedService = $restedService;
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