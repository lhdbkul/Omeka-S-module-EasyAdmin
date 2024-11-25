<?php declare(strict_types=1);

namespace EasyAdmin\Service\Form;

use EasyAdmin\Form\AddonsForm;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class AddonsFormFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $plugins = $services->get('ControllerPluginManager');

        $settings = $services->get('Omeka\Settings');
        $selections = $settings->get('easyadmin_selections_modules') ?: [];

        $form = new AddonsForm();
        return $form
            ->setAddons($plugins->get('easyAdminAddons'))
            ->setSelections($selections);
    }
}
