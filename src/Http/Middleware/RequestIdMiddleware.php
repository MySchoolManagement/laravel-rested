<?php
namespace Rested\Laravel\Http\Middleware;

use Closure;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpFoundation\RequestStack;

class RequestIdMiddleware
{

    const MASTER_HEADER = 'X-Request-ID';
    const SUB_HEADER = 'X-SubRequest-ID';

    /**
     * @var \Symfony\Component\HttpFoundation\RequestStack
     */
    private $requestStack;

    public function __construct(RequestStack $requestStack)
    {
        $this->requestStack = $requestStack;
    }

    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request $request
     * @param  \Closure $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        $masterHeaders = $this->requestStack->getMasterRequest()->headers;
        $currentHeaders = $request->headers;

        // if the master request doesn't have the header then this must be the top most request, not a sub
        if ($masterHeaders->has(self::MASTER_HEADER) === false) {
            $id = Uuid::uuid4()->toString();

            $masterHeaders->set(self::MASTER_HEADER, $id);
            $masterHeaders->set(self::SUB_HEADER, $id);
        } else {
            $currentHeaders->set(self::MASTER_HEADER, $masterHeaders->get(self::MASTER_HEADER));
            $currentHeaders->set(self::SUB_HEADER, Uuid::uuid4()->toString());
        }

        return $next($request);
    }
}
