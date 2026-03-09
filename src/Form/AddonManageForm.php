<?php declare(strict_types=1);

namespace EasyAdmin\Form;

use Laminas\Form\Element;
use Laminas\Form\Form;

class AddonManageForm extends Form
{
    public function init(): void
    {
        $this
            ->add([
                'name' => 'bulk_action',
                'type' => Element\Select::class,
                'options' => [
                    'label' => 'Bulk action', // @translate
                    'value_options' => [
                        '' => 'Select action…', // @translate
                        'activate' => 'Activate', // @translate
                        'deactivate' => 'Deactivate', // @translate
                        'update' => 'Update', // @translate
                        'remove' => 'Remove', // @translate
                        'integrity' => 'Check integrity', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'bulk_action',
                ],
            ])
            ->add([
                'name' => 'auto_upgrade',
                'type' => Element\Checkbox::class,
                'options' => [
                    'label' => 'Run database migrations automatically after update', // @translate
                ],
                'attributes' => [
                    'id' => 'auto_upgrade',
                ],
            ])
        ;
    }
}
