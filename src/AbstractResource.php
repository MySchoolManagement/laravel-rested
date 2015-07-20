<?php
namespace Rested\Laravel;

use App\Http\Requests\Request;
use Illuminate\Auth\AuthManager;
use Illuminate\Routing\Controller;
use Illuminate\Routing\Router;
use Rested\FactoryInterface;
use Rested\RestedResource;
use Rested\RestedResourceInterface;
use Rested\UrlGeneratorInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

abstract class AbstractResource extends Controller implements RestedResourceInterface
{

    use RestedResource;

    protected $authManager;

    private $factory;

    /**
     * @var RequestStack
     */
    private $requestStack;

    public function __construct(FactoryInterface $factory, UrlGeneratorInterface $urlGenerator,
        AuthorizationCheckerInterface $authorizationChecker = null, AuthManager $authManager = null, RequestStack $requestStack = null)
    {
        $this->authManager = $authManager;
        $this->authorizationChecker = $authorizationChecker;
        $this->factory = $factory;
        $this->urlGenerator = $urlGenerator;
        $this->requestStack = $requestStack;
    }

    /**
     * @return null|\Symfony\Component\HttpFoundation\Request
     */
    public function getCurrentRequest()
    {
        return $this->requestStack ? $this->requestStack->getCurrentRequest() : null;
    }

    /**
     * @return \Rested\FactoryInterface
     */
    public function getFactory()
    {
        return $this->factory;
    }

    /**
     * {@inheritdoc}
     */
    public function getUser()
    {
        return $this->authManager->user();
    }

    public function preHandle()
    {
        $action = $this->getRouter()->getCurrentRoute()->getAction();
        $attributes = $this->getCurrentRequest()->attributes;

        $attributes->set('_rested_controller', $action['_rested_controller']);
        $attributes->set('_rested_action', $action['_rested_action']);
        $attributes->set('_rested_route_name', $action['_rested_route_name']);

        return call_user_func_array([$this, 'handle'], func_get_args());
    }
}
