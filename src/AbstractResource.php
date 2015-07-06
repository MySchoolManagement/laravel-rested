<?php
namespace Rested\Laravel;

use App\Http\Requests\Request;
use Illuminate\Routing\Controller;
use Illuminate\Routing\Router;
use Rested\FactoryInterface;
use Rested\RestedResource;
use Rested\RestedResourceInterface;
use Rested\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

abstract class AbstractResource extends Controller implements RestedResourceInterface
{

    use RestedResource;

    private $factory;

    public function __construct(FactoryInterface $factory, UrlGeneratorInterface $urlGenerator = null,
        AuthorizationCheckerInterface $authorizationChecker = null, Router $router = null)
    {
        $this->factory = $factory;

        $currentActionType = null;
        $request = null;

        if (($router !== null) && (($route = $router->getCurrentRoute()) !== null)) {
            $action = $route->getAction();
            $request = $router->getCurrentRequest();
            $currentActionType = $action['rested_type'];
        }

        $this->initRestedResource($urlGenerator, $authorizationChecker, $request, $currentActionType);
    }

    /**
     * @return Rested\FactoryInterface
     */
    public function getFactory()
    {
        return $this->factory;
    }
}