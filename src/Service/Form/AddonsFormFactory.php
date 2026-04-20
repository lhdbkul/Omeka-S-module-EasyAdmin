<?php declare(strict_types=1);

namespace EasyAdmin\Service\Form;

use EasyAdmin\Form\AddonsForm;
use Psr\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class AddonsFormFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        $plugins = $services->get('ControllerPluginManager');
        $addons = $plugins->get('easyAdminAddons');

        $selections = array_keys($addons->getSelections());

        $form = new AddonsForm();
        return $form
            ->setAddons($addons)
            ->setSelections($selections)
        ;
    }
}
