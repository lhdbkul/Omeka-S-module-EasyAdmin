<?php declare(strict_types=1);

namespace EasyAdmin\Form;

use Laminas\Form\Element;
use Laminas\Form\Form;

/**
 * Simple form for module state change actions (activate,
 * deactivate, upgrade, update, remove, install, integrity).
 */
class ModuleStateForm extends Form
{
    public function init(): void
    {
        $this
            ->add([
                'name' => 'id',
                'type' => Element\Hidden::class,
            ])
        ;
    }
}
