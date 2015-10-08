<?php
namespace Rested\Laravel;

use Illuminate\Auth\AuthManager;
use Illuminate\Database\DatabaseManager;
use Rested\Definition\ActionDefinition;
use Rested\FactoryInterface;
use Rested\RestedServiceInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;


/**
 * Provides extra helpers for dealing with endpoints that create content.
 */
abstract class EloquentResource extends AbstractResource
{

    /**
     * @var \Illuminate\Database\DatabaseManager;
     */
    protected $databaseManager;

    public function __construct(
        RestedServiceInterface $restedService,
        FactoryInterface $factory,
        AuthorizationCheckerInterface $authorizationChecker,
        AuthManager $authManager,
        DatabaseManager $databaseManager,
        RequestStack $requestStack)
    {
        parent::__construct($restedService, $factory, $authorizationChecker, $authManager, $requestStack);

        $this->databaseManager = $databaseManager;
    }

    /**
     * Applies filters from mapping data to the given query builder.
     *
     * @param \Illuminate\Database\Model $queryBuilder
     * @param bool $applyLimits Apply offset/limit?
     *
     * @return \Illuminate\Database\Model
     */
    public function applyFilters($queryBuilder, $applyLimits = true)
    {
        $context = $this->getCurrentContext();
        $transformMapping = $this->getCurrentAction()->getTransformMapping();

        if ($applyLimits == true) {
            $queryBuilder = $queryBuilder
                ->take($context->getLimit())
                ->offset($context->getOffset())
            ;
        }

        foreach ($transformMapping->getFilters() as $filter) {
            if (($value = $context->getFilterValue($filter->getName())) !== null) {
                if ($value === 'null') {
                    $value = null;
                }

                $callable = $filter->getCallback();

                if ($callable !== null) {
                    $queryBuilder->$callable($value);
                }
            }
        }

        return $queryBuilder;
    }

    public function collection()
    {
        // FIXME: move out in to RestedResource
        if ($this->getCurrentAction()->isAffordanceAvailable($this) === false) {
            $this->abort(HttpResponse::HTTP_FORBIDDEN);
        }

        $items = [];

        // build data
        $builder = $this->createQueryBuilder(true);

        foreach ($builder->get() as $item) {
            $items[] = $this->export($item);
        }

        // build total
        $builder = $this->createQueryBuilder(true, false);
        $model = $builder->getModel();
        $total = $builder->distinct()->count($model->getTable().'.'.$model->getKeyName());

        // create the response
        $factory = $this->getFactory();
        $resourceDefinition = $this->getCurrentContext()->getResourceDefinition();
        $response = $factory->createCollectionResponse(
            $resourceDefinition,
            $this,
            $this->getCurrentContext(),
            $this->getCurrentAction()->getEndpointUrl(),
            $items,
            $total);

        return $this->done($response);
    }

    public function create()
    {
        // FIXME: move out in to RestedResource
        if ($this->getCurrentAction()->isAffordanceAvailable($this) === false) {
            $this->abort(HttpResponse::HTTP_FORBIDDEN);
        }

        $request = $this->getCurrentRequest();
        $input = $this->extractDataFromRequest($request);

        // check for a duplicate record
        if ($this->hasDuplicate($request, $input) == true) {
            $this->abort(HttpResponse::HTTP_CONFLICT, ['An item already exists']);
        }

        $instance = null;

        $closure = function() use ($input, &$instance) {
            $instance = $this->createInstance($input);

            if ($instance !== null) {
                $this->onCreated($instance);
            }
        };

        if ($this->useTransaction() === true) {
            $this->databaseManager->transaction($closure);
        } else {
            $closure();
        }

        $item = $this->exportAll($instance);

        return $this->done($item, HttpResponse::HTTP_CREATED);
    }

    protected function createInstance(array $input)
    {
        $action = $this->getCurrentAction();
        $transform = $action->getTransform();
        $transformMapping = $action->getTransformMapping();

        $instance = $transform->apply($transformMapping, 'en', $input);
        $instance->save();

        return $instance;
    }

    /**
     * Creates a query builder from the bound model class.
     *
     * Optionally, we apply filters and limits.
     *
     * @param bool $applyFilters
     * @param bool $applyLimits
     *
     * @return \Illuminate\Database\Eloquent\Model
     */
    protected function createQueryBuilder($applyFilters = false, $applyLimits = true)
    {
        return $this->createQueryBuilderFor(
            $this->getCurrentAction()->getTransformMapping()->getModelClass(),
            $applyFilters,
            $applyLimits
        );
    }

    protected function createQueryBuilderFor($modelClass, $applyFilters = false, $applyLimits = true)
    {
        $model = new $modelClass;
        $queryBuilder = $model->newQuery()->distinct($model->getTable())->select($model->getTable().'.*');

        if ($applyFilters === true) {
            $queryBuilder = $this->applyFilters($queryBuilder, $applyLimits);
        }

        return $queryBuilder;
    }

    public function delete($id)
    {
        if (($instance = $this->findInstance($id)) === null) {
            $this->abort(HttpResponse::HTTP_NOT_FOUND);
        }

        // FIXME: move out in to RestedResource
        if ($this->getCurrentAction()->isAffordanceAvailable($this, $instance) === false) {
            $this->abort(HttpResponse::HTTP_FORBIDDEN);
        }

        $instance->delete();

        return $this->done(null, HttpResponse::HTTP_NO_CONTENT);
    }

    public function instance($id)
    {
        if (($instance = $this->findInstance($id)) === null) {
            $this->abort(HttpResponse::HTTP_NOT_FOUND);
        }

        // FIXME: move out in to RestedResource
        if ($this->getCurrentAction()->isAffordanceAvailable($this, $instance) === false) {
            $this->abort(HttpResponse::HTTP_FORBIDDEN);
        }

        $item = $this->export($instance);

        return $this->done($item);
    }

    public function update($id, $callback = null)
    {
        $request = $this->getCurrentRequest();

        if (($instance = $this->findInstance($id)) === null) {
            $this->abort(HttpResponse::HTTP_NOT_FOUND);
        }

        // FIXME: move out in to RestedResource
        if ($this->getCurrentAction()->isAffordanceAvailable($this, $instance) === false) {
            $this->abort(HttpResponse::HTTP_FORBIDDEN);
        }

        $input = $this->extractDataFromRequest($request);

        $closure = function() use ($input, $instance, $callback) {
            $this->updateInstance($instance, $input);
            $this->onUpdated($instance);

            if ($callback !== null) {
                $callback($instance);
            }
        };

        if ($this->useTransaction() === true) {
            $this->databaseManager->transaction($closure);
        } else {
            $closure();
        }

        $item = $this->exportAll($instance);

        return $this->done($item, HttpResponse::HTTP_OK);
    }

    /**
     * Find an instance from an ID.
     *
     * @param  mixed $id ID of the resource.
     *
     * @return mixed|null Content for the given ID or null.
     */
    protected function findInstance($id)
    {
        // this should always be looked up through the instance model
        $action = $this->getCurrentContext()->getResourceDefinition()->findFirstAction(ActionDefinition::TYPE_INSTANCE);
        $field = $action->getTransformMapping() ->findPrimaryKeyField();

        if ($field !== null) {
            $queryBuilder = $this->createQueryBuilder();

            return $queryBuilder
                ->where($queryBuilder->getModel()->getTable().'.'.$field->getCallback(), $id)
                ->first()
            ;
        }

        return null;
    }

    /**
     * With the given data, check to see if an existing item already exists.
     *
     * @param Request $request
     * @param array $input
     *
     * @return bool
     */
    protected function hasDuplicate(Request $request, array $input)
    {
        return false;
    }

    /**
     * Called when a new instance of the content type has been created.
     *
     * If you make changes to the model of the instance then you must call
     * save.
     *
     * @param object $instance Instance that was created.
     */
    protected function onCreated($instance)
    {

    }

    /**
     * Called when an instance of the content type has been updated.
     *
     * If you make changes to the model of the instance then you must call
     * save.
     *
     * @param object $instance Instance that was updated.
     */
    protected function onUpdated($instance)
    {

    }

    /**
     * Updates an existing instance of the content type.
     *
     * @param object $instance
     * @param array $input
     *
     * @return object|null
     */
    protected function updateInstance($instance, array $input)
    {
        $action = $this->getCurrentAction();
        $transform = $action->getTransform();
        $transformMapping = $action->getTransformMapping();

        $instance = $transform->apply($transformMapping, 'en', $input, $instance);
        $instance->save();

        return $instance;
    }

    /**
     * @return bool
     */
    public function useTransaction()
    {
        return true;
    }
}
