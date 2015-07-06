<?php
namespace Rested\Laravel;

use Illuminate\Contracts\Routing\UrlGenerator as LaravelUrlGenerator;
use Rested\UrlGeneratorInterface;

class UrlGenerator implements UrlGeneratorInterface
{

    private $urlGenerator;

    public function __construct(LaravelUrlGenerator $urlGenerator)
    {
        $this->urlGenerator = $urlGenerator;
    }

    /**
     * {@inheritdoc}
     */
    public function generate($name, $parameters = [], $absolute = true)
    {
        return $this->urlGenerator->route($name, $parameters, $absolute);
    }
}