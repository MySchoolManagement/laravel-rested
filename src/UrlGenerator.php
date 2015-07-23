<?php
namespace Rested\Laravel;

use Illuminate\Contracts\Routing\UrlGenerator as LaravelUrlGenerator;
use Rested\UrlGeneratorInterface;

class UrlGenerator implements UrlGeneratorInterface
{

    /**
     * @var string
     */
    private $mountPrefix;

    /**
     * @var \Illuminate\Contracts\Routing\UrlGenerator
     */
    private $urlGenerator;

    public function __construct(LaravelUrlGenerator $urlGenerator, $mountPrefix)
    {
        $this->mountPrefix = $mountPrefix;
        $this->urlGenerator = $urlGenerator;
    }

    /**
     * {@inheritdoc}
     */
    public function getMountPath()
    {
        return $this->mountPrefix;
    }

    /**
     * {@inheritdoc}
     */
    public function route($routeName, array $parameters = [], $absolute = true)
    {
        return $this->urlGenerator->route($routeName, $parameters, $absolute);
    }

    /**
     * {@inheritdoc}
     */
    public function url($path, $absolute = true)
    {
        if ($absolute === false) {
            return $path;
        }

        return $this->urlGenerator->to($path);
    }
}
