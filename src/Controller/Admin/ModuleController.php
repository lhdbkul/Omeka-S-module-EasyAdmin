<?php declare(strict_types=1);

namespace EasyAdmin\Controller\Admin;

use Common\Stdlib\PsrMessage;
use EasyAdmin\Form\AddonManageForm;
use EasyAdmin\Form\AddonsForm;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
use Omeka\Module\Manager as OmekaModuleManager;

class ModuleController extends AbstractActionController
{
    /**
     * @var \Omeka\Module\Manager
     */
    protected $moduleManager;

    /**
     * @var string
     */
    protected $basePath;

    public function __construct(
        OmekaModuleManager $moduleManager,
        string $basePath
    ) {
        $this->moduleManager = $moduleManager;
        $this->basePath = $basePath;
    }

    public function browseAction()
    {
        /** @var \EasyAdmin\Mvc\Controller\Plugin\Addons $addons */
        $addons = $this->easyAdminAddons();

        // Get installed modules from ModuleManager.
        $installedModules = $this->moduleManager->getModules();
        uasort($installedModules, function ($a, $b) {
            return strcasecmp($a->getName(), $b->getName());
        });

        // Get available modules from catalogue.
        $catalogueAddons = $addons->getAddons();
        $addons->enrichWithLocalState($catalogueAddons);

        // Merge module types.
        $allModules = array_merge(
            $catalogueAddons['omekamodule'] ?? [],
            $catalogueAddons['module'] ?? []
        );

        // Build the manage form for installed modules.
        $manageForm = $this->getForm(AddonManageForm::class);

        // Build the install form.
        $installForm = $this->getForm(AddonsForm::class);

        $request = $this->getRequest();
        if ($request->isPost()) {
            return $this->handlePost($addons, $manageForm);
        }

        // Filter by state if requested.
        $state = $this->params()->fromQuery('state');

        $view = new ViewModel([
            'installedModules' => $installedModules,
            'catalogueModules' => $allModules,
            'manageForm' => $manageForm,
            'installForm' => $installForm,
            'filterState' => $state,
            'moduleManager' => $this->moduleManager,
        ]);
        $view->setTemplate('easy-admin/admin/module/browse');
        return $view;
    }

    public function updateConfirmAction()
    {
        $id = $this->params()->fromQuery('id');
        if (!$id) {
            return $this->redirect()->toRoute(
                'admin/easy-admin/default',
                ['controller' => 'module']
            );
        }

        /** @var \EasyAdmin\Mvc\Controller\Plugin\Addons $addons */
        $addons = $this->easyAdminAddons();
        $addon = $addons->dataFromNamespace($id, 'module')
            ?: $addons->dataFromNamespace($id);
        if (!$addon) {
            $this->messenger()->addError(new PsrMessage(
                'Unknown module "{name}".', // @translate
                ['name' => $id]
            ));
            return $this->redirect()->toRoute(
                'admin/easy-admin/default',
                ['controller' => 'module']
            );
        }

        $integrity = $addons->checkIntegrity($addon);

        $view = new ViewModel([
            'addon' => $addon,
            'integrity' => $integrity,
        ]);
        $view->setTerminal(true);
        $view->setTemplate(
            'easy-admin/admin/module/update-confirm'
        );
        return $view;
    }

    public function removeConfirmAction()
    {
        $id = $this->params()->fromQuery('id');
        if (!$id) {
            return $this->redirect()->toRoute(
                'admin/easy-admin/default',
                ['controller' => 'module']
            );
        }

        /** @var \EasyAdmin\Mvc\Controller\Plugin\Addons $addons */
        $addons = $this->easyAdminAddons();
        $addon = $addons->dataFromNamespace($id, 'module')
            ?: $addons->dataFromNamespace($id);

        $integrity = $addon
            ? $addons->checkIntegrity($addon)
            : ['status' => 'unknown'];

        $view = new ViewModel([
            'addon' => $addon,
            'addonDir' => $id,
            'integrity' => $integrity,
        ]);
        $view->setTerminal(true);
        $view->setTemplate(
            'easy-admin/admin/module/remove-confirm'
        );
        return $view;
    }

    public function updateAction()
    {
        if (!$this->getRequest()->isPost()) {
            return $this->redirect()->toRoute(
                'admin/easy-admin/default',
                ['controller' => 'module']
            );
        }

        $id = $this->params()->fromPost('id')
            ?: $this->params()->fromQuery('id');
        if (!$id) {
            $this->messenger()->addError(
                'No module specified.' // @translate
            );
            return $this->redirect()->toRoute(
                'admin/easy-admin/default',
                ['controller' => 'module']
            );
        }

        /** @var \EasyAdmin\Mvc\Controller\Plugin\Addons $addons */
        $addons = $this->easyAdminAddons();
        $addon = $addons->dataFromNamespace($id, 'module')
            ?: $addons->dataFromNamespace($id);
        if (!$addon) {
            $this->messenger()->addError(new PsrMessage(
                'Unknown module "{name}".', // @translate
                ['name' => $id]
            ));
            return $this->redirect()->toRoute(
                'admin/easy-admin/default',
                ['controller' => 'module']
            );
        }

        $addons->updateAddon($addon);

        // Auto-upgrade if requested.
        $autoUpgrade = (bool) $this->params()->fromPost(
            'auto_upgrade'
        );
        if ($autoUpgrade) {
            $module = $this->moduleManager->getModule(
                $addon['dir']
            );
            if ($module
                && $module->getState()
                    === OmekaModuleManager::STATE_NEEDS_UPGRADE
            ) {
                try {
                    $this->moduleManager->upgrade($module);
                    $this->messenger()->addSuccess(new PsrMessage(
                        'The module "{name}" database was'
                            . ' upgraded.', // @translate
                        ['name' => $addon['name']]
                    ));
                } catch (\Exception $e) {
                    $this->messenger()->addError(new PsrMessage(
                        'Error upgrading database for'
                            . ' "{name}": {error}', // @translate
                        [
                            'name' => $addon['name'],
                            'error' => $e->getMessage(),
                        ]
                    ));
                }
            }
        }

        return $this->redirect()->toRoute(
            'admin/easy-admin/default',
            ['controller' => 'module']
        );
    }

    public function removeAction()
    {
        if (!$this->getRequest()->isPost()) {
            return $this->redirect()->toRoute(
                'admin/easy-admin/default',
                ['controller' => 'module']
            );
        }

        $id = $this->params()->fromPost('id')
            ?: $this->params()->fromQuery('id');
        if (!$id) {
            $this->messenger()->addError(
                'No module specified.' // @translate
            );
            return $this->redirect()->toRoute(
                'admin/easy-admin/default',
                ['controller' => 'module']
            );
        }

        /** @var \EasyAdmin\Mvc\Controller\Plugin\Addons $addons */
        $addons = $this->easyAdminAddons();
        $addon = $addons->dataFromNamespace($id, 'module')
            ?: $addons->dataFromNamespace($id);
        if (!$addon) {
            // Module not in catalogue: build minimal addon data
            // for removal.
            $addon = [
                'type' => 'module',
                'name' => $id,
                'dir' => $id,
                'basename' => $id,
                'url' => '',
                'zip' => '',
                'version' => '',
                'dependencies' => [],
            ];
        }

        $addons->removeAddon($addon);

        return $this->redirect()->toRoute(
            'admin/easy-admin/default',
            ['controller' => 'module']
        );
    }

    public function activateAction()
    {
        if (!$this->getRequest()->isPost()) {
            return $this->redirect()->toRoute(
                'admin/easy-admin/default',
                ['controller' => 'module']
            );
        }

        $id = $this->params()->fromPost('id')
            ?: $this->params()->fromQuery('id');
        $module = $this->moduleManager->getModule($id);
        if (!$module) {
            $this->messenger()->addError(new PsrMessage(
                'Module "{name}" not found.', // @translate
                ['name' => $id]
            ));
            return $this->redirect()->toRoute(
                'admin/easy-admin/default',
                ['controller' => 'module']
            );
        }

        try {
            $this->moduleManager->activate($module);
            $this->messenger()->addSuccess(new PsrMessage(
                'Module "{name}" activated.', // @translate
                ['name' => $id]
            ));
        } catch (\Exception $e) {
            $this->messenger()->addError(new PsrMessage(
                'Error activating "{name}": {error}', // @translate
                ['name' => $id, 'error' => $e->getMessage()]
            ));
        }

        return $this->redirect()->toRoute(
            'admin/easy-admin/default',
            ['controller' => 'module']
        );
    }

    public function deactivateAction()
    {
        if (!$this->getRequest()->isPost()) {
            return $this->redirect()->toRoute(
                'admin/easy-admin/default',
                ['controller' => 'module']
            );
        }

        $id = $this->params()->fromPost('id')
            ?: $this->params()->fromQuery('id');
        $module = $this->moduleManager->getModule($id);
        if (!$module) {
            $this->messenger()->addError(new PsrMessage(
                'Module "{name}" not found.', // @translate
                ['name' => $id]
            ));
            return $this->redirect()->toRoute(
                'admin/easy-admin/default',
                ['controller' => 'module']
            );
        }

        try {
            $this->moduleManager->deactivate($module);
            $this->messenger()->addSuccess(new PsrMessage(
                'Module "{name}" deactivated.', // @translate
                ['name' => $id]
            ));
        } catch (\Exception $e) {
            $this->messenger()->addError(new PsrMessage(
                'Error deactivating "{name}":'
                    . ' {error}', // @translate
                ['name' => $id, 'error' => $e->getMessage()]
            ));
        }

        return $this->redirect()->toRoute(
            'admin/easy-admin/default',
            ['controller' => 'module']
        );
    }

    public function upgradeAction()
    {
        if (!$this->getRequest()->isPost()) {
            return $this->redirect()->toRoute(
                'admin/easy-admin/default',
                ['controller' => 'module']
            );
        }

        $id = $this->params()->fromPost('id')
            ?: $this->params()->fromQuery('id');
        $module = $this->moduleManager->getModule($id);
        if (!$module) {
            $this->messenger()->addError(new PsrMessage(
                'Module "{name}" not found.', // @translate
                ['name' => $id]
            ));
            return $this->redirect()->toRoute(
                'admin/easy-admin/default',
                ['controller' => 'module']
            );
        }

        try {
            $this->moduleManager->upgrade($module);
            $this->messenger()->addSuccess(new PsrMessage(
                'Module "{name}" upgraded.', // @translate
                ['name' => $id]
            ));
        } catch (\Exception $e) {
            $this->messenger()->addError(new PsrMessage(
                'Error upgrading "{name}": {error}', // @translate
                ['name' => $id, 'error' => $e->getMessage()]
            ));
        }

        return $this->redirect()->toRoute(
            'admin/easy-admin/default',
            ['controller' => 'module']
        );
    }

    public function installAction()
    {
        if (!$this->getRequest()->isPost()) {
            return $this->redirect()->toRoute(
                'admin/easy-admin/default',
                ['controller' => 'module']
            );
        }

        /** @var \EasyAdmin\Mvc\Controller\Plugin\Addons $addons */
        $addons = $this->easyAdminAddons();
        $url = $this->params()->fromPost('module_url');
        if (!$url) {
            $this->messenger()->addError(
                'No module selected.' // @translate
            );
            return $this->redirect()->toRoute(
                'admin/easy-admin/default',
                ['controller' => 'module']
            );
        }

        // Try both types.
        $addon = $addons->dataFromUrl($url, 'module')
            ?: $addons->dataFromUrl($url, 'omekamodule');
        if (!$addon) {
            $this->messenger()->addError(new PsrMessage(
                'The module at "{url}" is not'
                    . ' available.', // @translate
                ['url' => $url]
            ));
            return $this->redirect()->toRoute(
                'admin/easy-admin/default',
                ['controller' => 'module']
            );
        }

        if ($addons->dirExists($addon)) {
            $this->messenger()->addError(new PsrMessage(
                'The module "{name}" is already'
                    . ' downloaded.', // @translate
                ['name' => $addon['name']]
            ));
            return $this->redirect()->toRoute(
                'admin/easy-admin/default',
                ['controller' => 'module']
            );
        }

        $addons->installAddon($addon);

        return $this->redirect()->toRoute(
            'admin/easy-admin/default',
            ['controller' => 'module']
        );
    }

    public function integrityAction()
    {
        if (!$this->getRequest()->isPost()) {
            return $this->redirect()->toRoute(
                'admin/easy-admin/default',
                ['controller' => 'module']
            );
        }

        $id = $this->params()->fromPost('id')
            ?: $this->params()->fromQuery('id');

        /** @var \EasyAdmin\Mvc\Controller\Plugin\Addons $addons */
        $addons = $this->easyAdminAddons();
        $fromSource = (bool) $this->params()->fromPost(
            'from_source'
        );

        if ($id) {
            // Single module.
            $addon = $addons->dataFromNamespace($id, 'module')
                ?: $addons->dataFromNamespace($id);
            if (!$addon) {
                $addon = [
                    'type' => 'module',
                    'name' => $id,
                    'dir' => $id,
                    'basename' => $id,
                    'url' => '',
                    'zip' => '',
                    'version' => '',
                    'dependencies' => [],
                ];
            }
            $report = [$id => $addons->checkIntegrity(
                $addon,
                $fromSource
            )];
        } else {
            // All installed modules.
            $report = [];
            $modules = $this->moduleManager->getModules();
            foreach ($modules as $moduleId => $module) {
                $addon = $addons->dataFromNamespace(
                    $moduleId,
                    'module'
                ) ?: $addons->dataFromNamespace($moduleId);
                if (!$addon) {
                    $addon = [
                        'type' => 'module',
                        'name' => $moduleId,
                        'dir' => $moduleId,
                        'basename' => $moduleId,
                        'url' => '',
                        'zip' => '',
                        'version' => '',
                        'dependencies' => [],
                    ];
                }
                $report[$moduleId] = $addons->checkIntegrity(
                    $addon,
                    $fromSource
                );
            }
        }

        return $this->redirect()->toRoute(
            'admin/easy-admin/default',
            ['controller' => 'module', 'action' => 'integrity-report'],
            ['query' => ['report' => json_encode($report)]]
        );
    }

    public function integrityReportAction()
    {
        $reportJson = $this->params()->fromQuery('report', '{}');
        $report = json_decode($reportJson, true) ?: [];

        $view = new ViewModel([
            'report' => $report,
        ]);
        $view->setTemplate(
            'easy-admin/admin/module/integrity-report'
        );
        return $view;
    }

    protected function handlePost($addons, $manageForm): \Laminas\Http\Response
    {
        $post = $this->params()->fromPost();

        // Bulk operations.
        $action = $post['bulk_action'] ?? '';
        $selected = $post['modules'] ?? [];

        if ($action && $selected) {
            // For large selections, dispatch as job.
            if (count($selected) > 3
                && in_array($action, ['update', 'remove'])
            ) {
                $dispatcher = $this->jobDispatcher();
                $args = [
                    'operation' => $action,
                    'addons' => $selected,
                    'options' => [
                        'auto_upgrade' => !empty(
                            $post['auto_upgrade']
                        ),
                    ],
                ];
                $job = $dispatcher->dispatch(
                    \EasyAdmin\Job\ManageAddons::class,
                    $args
                );
                $this->messenger()->addSuccess(new PsrMessage(
                    'Processing {action} in background'
                        . ' (job #{job_id}).', // @translate
                    [
                        'action' => $action,
                        'job_id' => $job->getId(),
                    ]
                ));
                return $this->redirect()->toRoute(
                    'admin/easy-admin/default',
                    ['controller' => 'module']
                );
            }

            foreach ($selected as $moduleId) {
                switch ($action) {
                    case 'activate':
                        $module = $this->moduleManager->getModule(
                            $moduleId
                        );
                        if ($module) {
                            try {
                                $this->moduleManager->activate(
                                    $module
                                );
                            } catch (\Exception $e) {
                                $this->messenger()->addError(
                                    $e->getMessage()
                                );
                            }
                        }
                        break;

                    case 'deactivate':
                        $module = $this->moduleManager->getModule(
                            $moduleId
                        );
                        if ($module) {
                            try {
                                $this->moduleManager->deactivate(
                                    $module
                                );
                            } catch (\Exception $e) {
                                $this->messenger()->addError(
                                    $e->getMessage()
                                );
                            }
                        }
                        break;

                    case 'update':
                        $addon = $addons->dataFromNamespace(
                            $moduleId,
                            'module'
                        ) ?: $addons->dataFromNamespace($moduleId);
                        if ($addon) {
                            $addons->updateAddon($addon);
                        }
                        break;

                    case 'remove':
                        $addon = $addons->dataFromNamespace(
                            $moduleId,
                            'module'
                        ) ?: $addons->dataFromNamespace($moduleId);
                        if (!$addon) {
                            $addon = [
                                'type' => 'module',
                                'name' => $moduleId,
                                'dir' => $moduleId,
                                'basename' => $moduleId,
                                'url' => '',
                                'zip' => '',
                                'version' => '',
                                'dependencies' => [],
                            ];
                        }
                        $addons->removeAddon($addon);
                        break;
                }
            }
        }

        return $this->redirect()->toRoute(
            'admin/easy-admin/default',
            ['controller' => 'module']
        );
    }
}
