<?php
namespace Rested\Laravel;

use Illuminate\Database\DatabaseManager;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Rested\FactoryInterface;
use Rested\RestedResource;
use Rested\Response;
use Rested\UrlGeneratorInterface;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
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

    public function __construct(FactoryInterface $factory, UrlGeneratorInterface $urlGenerator = null,
        AuthorizationCheckerInterface $authorizationChecker = null, Router $router = null,
        DatabaseManager $databaseManager = null)
    {
        parent::__construct($factory, $urlGenerator, $authorizationChecker, $router);

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
        if ($applyLimits == true) {

            $queryBuilder = $queryBuilder
                ->take($this->getLimit())
                ->offset($this->getOffset())
            ;
        }

        /*$mapping = $this->getExportMapping();

        foreach ($mapping->getFilters() as $filter) {
            // @todo: security
            /*if ($app['security']->isGranted($filter->getRequiredPermission()) == false) {
                continue;
            }*//*

            if (($value = $this->getFilter($filter->getName())) !== null) {
                if ($value == 'null') {
                    $value = null;
                }

                $callable = $filter->getCallable();

                if ($callable !== null) {
                    $queryBuilder->$callable($value);
                }
            }
        }*/

        return $queryBuilder;
    }

    public function collection(Request $request)
    {
        $items = [];

        // build data
        $builder = $this->createQueryBuilder(true);

        foreach ($builder->get() as $item) {
            $items[] = $this->export($item);
        }

        // build total
        $total = $this->createQueryBuilder(true, false)->count();
        $item = Response::createCollection($this, $items, $total);

        return $this->done($item);
    }

    public function create(Request $request)
    {
        $data = $request->json()->all();

        // check for a duplicate record
        if ($this->hasDuplicate($request, $data) == true) {
            return $this->abort(HttpResponse::HTTP_CONFLICT, ['An item with this name already exists']);
        }

        $instance = null;

        $closure = function() use ($data, &$instance) {
            $instance = $this->createInstance($data);

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


    /**
     * Creates a new instance of the type stored in the model.
     *
     * @param Form $form
     *
     * @return object|null
     */
    protected function createInstance(array $data)
    {
        $instance = $this->getCurrentModel()->apply('en', $data);
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
        return $this->createQueryBuilderFor($this->getCurrentModel()->getDefiningClass(), $applyFilters, $applyLimits);
    }

    protected function createQueryBuilderFor($class, $applyFilters = false, $applyLimits = true)
    {
        $queryBuilder = new $class();

        // apply current locale (not all models handled by this class have i18n enabled)
        /*if (method_exists($queryBuilder, 'joinWithI18n') == true) {
            $queryBuilder->joinWithI18n($this->getRequest()->getLocale());
        }*/

        if ($applyFilters == true) {
            $queryBuilder = $this->applyFilters($queryBuilder, $applyLimits);
        }

        return $queryBuilder;
    }

    public function delete(Request $request, $id)
    {
        $instance = $this->findInstance($id);

        if ($instance === null) {
            $this->abort(HttpResponse::HTTP_NOT_FOUND);
        }

        $instance->delete();

        return $this->done(null, HttpResponse::HTTP_NO_CONTENT);
    }

    public function instance(Request $request, $id)
    {
        $instance = $this->findInstance($id);

        if ($instance === null) {
            $this->abort(HttpResponse::HTTP_NOT_FOUND);
        }

        $item = $this->export($instance);

        return $this->done($item);
    }

    public function update(Request $request, $id)
    {
        $instance = $this->findInstance($id);

        if ($instance === null) {
            $this->abort(HttpResponse::HTTP_NOT_FOUND);
        }

        $data = $request->json()->all();

        $closure = function() use ($data, $instance) {
            $this->updateInstance($instance, $data);
            $this->onUpdated($instance);
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
        $model = $this->getCurrentModel();
        $field = $model->getPrimaryKeyField();

        if ($field !== null) {
            return $this
                ->createQueryBuilder()
                ->where($field->getGetter(), $id)
                ->first()
            ;
        }

        return null;
    }

    /**
     * With the given data, check to see if an existing item already exists.
     *
     * @param Request $request
     * @param array $data
     *
     * @return bool
     */
    protected function hasDuplicate(Request $request, array $data)
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
     * @param array $data
     *
     * @return object|null
     */
    protected function updateInstance($instance, array $data)
    {
        $instance = $this->getCurrentModel()->apply('en', $data, $instance);
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