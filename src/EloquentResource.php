<?php
namespace Rested\Laravel;

use Illuminate\Auth\AuthManager;
use Illuminate\Database\DatabaseManager;
use Illuminate\Routing\Router;
use Rested\Definition\ActionDefinition;
use Rested\FactoryInterface;
use Rested\RestedResource;
use Rested\Response;
use Rested\Security\AccessVoter;
use Rested\UrlGeneratorInterface;
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
        FactoryInterface $factory,
        UrlGeneratorInterface $urlGenerator,
        AuthorizationCheckerInterface $authorizationChecker = null,
        AuthManager $authManager = null,
        DatabaseManager $databaseManager = null,
        RequestStack $requestStack = null)
    {
        parent::__construct($factory, $urlGenerator, $authorizationChecker, $authManager, $requestStack);

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

        if ($applyLimits == true) {
            $queryBuilder = $queryBuilder
                ->take($context->getLimit())
                ->offset($context->getOffset())
            ;
        }

        $model = $this->getCurrentModel();

        foreach ($model->getFilters() as $filter) {
            if ($this->getAuthorizationChecker()->isGranted(AccessVoter::ATTRIB_FILTER, $filter) === false) {
                continue;
            }

            if (($value = $context->getFilter($filter->getName())) !== null) {
                if ($value == 'null') {
                    $value = null;
                }

                $callable = $filter->getCallable();

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
        if ($this->getCurrentAction()->checkAffordance() === false) {
            $this->abort(HttpResponse::HTTP_FORBIDDEN);
        }

        $items = [];

        // build data
        $builder = $this->createQueryBuilder(true);

        foreach ($builder->get() as $item) {
            $items[] = $this->export($item);
        }

        // build total
        $total = $this->createQueryBuilder(true, false)->count();
        $item = $this->getFactory()->createCollectionResponse($this, $items, $total);

        return $this->done($item);
    }

    public function create()
    {
        // FIXME: move out in to RestedResource
        if ($this->getCurrentAction()->checkAffordance() === false) {
            $this->abort(HttpResponse::HTTP_FORBIDDEN);
        }

        $request = $this->getRouter()->getCurrentRequest();
        $data = $this->extractDataFromRequest($request);

        // check for a duplicate record
        if ($this->hasDuplicate($request, $data) == true) {
            return $this->abort(HttpResponse::HTTP_CONFLICT, ['An item already exists']);
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

    public function delete()
    {
        $instance = $this->findInstance($id);

        // FIXME: move out in to RestedResource
        if ($this->getCurrentAction()->checkAffordance($instance) === false) {
            $this->abort(HttpResponse::HTTP_FORBIDDEN);
        }

        if ($instance === null) {
            $this->abort(HttpResponse::HTTP_NOT_FOUND);
        }

        $instance->delete();

        return $this->done(null, HttpResponse::HTTP_NO_CONTENT);
    }

    protected function extractDataFromRequest(Request $request)
    {
        if (in_array($request->getContentType(), ['json', 'application/json']) === true) {
            return (array) json_decode($request->getContent(), true);
        } else {
            return $request->request->all();
        }
    }

    public function instance($id)
    {
        $instance = $this->findInstance($id);

        // FIXME: move out in to RestedResource
        if ($this->getCurrentAction()->checkAffordance($instance) === false) {
            $this->abort(HttpResponse::HTTP_FORBIDDEN);
        }

        if ($instance === null) {
            $this->abort(HttpResponse::HTTP_NOT_FOUND);
        }

        $item = $this->export($instance);

        return $this->done($item);
    }

    public function update($id, $callback = null)
    {
        $request = $this->getRouter()->getCurrentRequest();
        $instance = $this->findInstance($id);



        if ($instance === null) {
            $this->abort(HttpResponse::HTTP_NOT_FOUND);
        }

        $data = $this->extractDataFromRequest($request);

        $closure = function() use ($data, $instance, $callback) {
            $this->updateInstance($instance, $data);
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
        $model = $this->getDefinition()->findAction(ActionDefinition::TYPE_INSTANCE)->getModel();
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
