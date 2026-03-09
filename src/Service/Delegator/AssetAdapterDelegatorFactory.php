<?php declare(strict_types=1);

namespace EasyAdmin\Service\Delegator;

use EasyAdmin\Delegator\AssetAdapterDelegator;
use Laminas\ServiceManager\Factory\DelegatorFactoryInterface;
use Psr\Container\ContainerInterface;

class AssetAdapterDelegatorFactory implements DelegatorFactoryInterface
{
    public function __invoke(ContainerInterface $container, $name, callable $callback, ?array $options = null)
    {
        // Call $callback() so the original adapter is fully built, then
        // return the delegator which extends AssetAdapter. The service
        // manager injects setServiceLocator() on the returned instance
        // automatically (AbstractEntityAdapter implements
        // ServiceLocatorAwareInterface).
        $callback();
        return new AssetAdapterDelegator();
    }
}
