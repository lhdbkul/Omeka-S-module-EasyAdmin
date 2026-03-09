<?php declare(strict_types=1);

namespace EasyAdmin\Controller\Admin;

use Common\Stdlib\PsrMessage;
use EasyAdmin\Form\ModuleStateForm;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;

class ThemeController extends AbstractActionController
{
    /**
     * @var string
     */
    protected $basePath;

    public function __construct(string $basePath)
    {
        $this->basePath = $basePath;
    }

    public function indexAction()
    {
        /** @var \EasyAdmin\Mvc\Controller\Plugin\Addons $addons */
        $addons = $this->easyAdminAddons();

        $catalogueAddons = $addons->getAddons();
        $addons->enrichWithLocalState($catalogueAddons);

        $allThemes = array_merge(
            $catalogueAddons['omekatheme'] ?? [],
            $catalogueAddons['theme'] ?? []
        );

        // Also scan local themes directory.
        $themesDir = OMEKA_PATH . '/themes';
        $localThemes = [];
        if (is_dir($themesDir)) {
            $dirs = array_diff(
                scandir($themesDir) ?: [],
                ['.', '..']
            );
            foreach ($dirs as $dir) {
                $iniFile = $themesDir . '/' . $dir
                    . '/config/theme.ini';
                if (file_exists($iniFile)) {
                    $ini = parse_ini_file($iniFile);
                    $localThemes[$dir] = [
                        'dir' => $dir,
                        'name' => $ini['name'] ?? $dir,
                        'version' => $ini['version'] ?? '',
                        'description' => $ini['description'] ?? '',
                        'author' => $ini['author'] ?? '',
                        'theme_link' => $ini['theme_link'] ?? '',
                        'omeka_version_constraint' => $ini['omeka_version_constraint'] ?? '',
                    ];
                }
            }
            uasort($localThemes, function ($a, $b) {
                return strcasecmp($a['name'], $b['name']);
            });
        }

        $state = $this->params()->fromQuery('state');

        $request = $this->getRequest();
        if ($request->isPost()) {
            return $this->handlePost($addons);
        }

        $manageForm = $this->getForm(
            \EasyAdmin\Form\AddonManageForm::class
        );

        $stateForm = function (string $action, string $themeId) {
            $form = $this->getForm(ModuleStateForm::class);
            $form->setAttribute('action', $this->url()->fromRoute(
                'admin/easy-admin/default',
                ['controller' => 'theme', 'action' => $action],
                ['query' => ['id' => $themeId]]
            ));
            $form->get('id')->setValue($themeId);
            return $form;
        };

        $view = new ViewModel([
            'catalogueThemes' => $allThemes,
            'localThemes' => $localThemes,
            'filterState' => $state,
            'manageForm' => $manageForm,
            'stateForm' => $stateForm,
        ]);
        $view->setTemplate('easy-admin/admin/theme/browse');
        return $view;
    }

    public function installAction()
    {
        if (!$this->getRequest()->isPost()) {
            return $this->redirect()->toRoute(
                'admin/easy-admin/default',
                ['controller' => 'theme']
            );
        }

        /** @var \EasyAdmin\Mvc\Controller\Plugin\Addons $addons */
        $addons = $this->easyAdminAddons();
        $url = $this->params()->fromPost('theme_url');
        if (!$url) {
            $this->messenger()->addError(
                'No theme selected.' // @translate
            );
            return $this->redirect()->toRoute(
                'admin/easy-admin/default',
                ['controller' => 'theme']
            );
        }

        $addon = $addons->dataFromUrl($url, 'theme')
            ?: $addons->dataFromUrl($url, 'omekatheme');
        if (!$addon) {
            $this->messenger()->addError(new PsrMessage(
                'The theme at "{url}" is not'
                    . ' available.', // @translate
                ['url' => $url]
            ));
            return $this->redirect()->toRoute(
                'admin/easy-admin/default',
                ['controller' => 'theme']
            );
        }

        if ($addons->dirExists($addon)) {
            $this->messenger()->addError(new PsrMessage(
                'The theme "{name}" is already'
                    . ' downloaded.', // @translate
                ['name' => $addon['name']]
            ));
            return $this->redirect()->toRoute(
                'admin/easy-admin/default',
                ['controller' => 'theme']
            );
        }

        $addons->installAddon($addon);

        return $this->redirect()->toRoute(
            'admin/easy-admin/default',
            ['controller' => 'theme']
        );
    }

    public function updateAction()
    {
        if (!$this->getRequest()->isPost()) {
            return $this->redirect()->toRoute(
                'admin/easy-admin/default',
                ['controller' => 'theme']
            );
        }

        $id = $this->params()->fromPost('id')
            ?: $this->params()->fromQuery('id');
        if (!$id) {
            $this->messenger()->addError(
                'No theme specified.' // @translate
            );
            return $this->redirect()->toRoute(
                'admin/easy-admin/default',
                ['controller' => 'theme']
            );
        }

        /** @var \EasyAdmin\Mvc\Controller\Plugin\Addons $addons */
        $addons = $this->easyAdminAddons();
        $addon = $addons->dataFromNamespace($id, 'theme')
            ?: $addons->dataFromNamespace($id);
        if (!$addon) {
            $this->messenger()->addError(new PsrMessage(
                'Unknown theme "{name}".', // @translate
                ['name' => $id]
            ));
            return $this->redirect()->toRoute(
                'admin/easy-admin/default',
                ['controller' => 'theme']
            );
        }

        $addons->updateAddon($addon);

        return $this->redirect()->toRoute(
            'admin/easy-admin/default',
            ['controller' => 'theme']
        );
    }

    public function showDetailsAction()
    {
        $id = $this->params()->fromQuery('id');

        /** @var \EasyAdmin\Mvc\Controller\Plugin\Addons $addons */
        $addons = $this->easyAdminAddons();
        $addon = $addons->dataFromNamespace($id, 'theme')
            ?: $addons->dataFromNamespace($id);

        $themesDir = OMEKA_PATH . '/themes';
        $iniFile = $themesDir . '/' . $id . '/config/theme.ini';
        $ini = file_exists($iniFile)
            ? (parse_ini_file($iniFile) ?: [])
            : [];

        $integrity = $addon
            ? $addons->checkIntegrity($addon)
            : null;

        $view = new ViewModel([
            'themeId' => $id,
            'ini' => $ini,
            'addon' => $addon,
            'integrity' => $integrity,
        ]);
        $view->setTerminal(true);
        $view->setTemplate(
            'easy-admin/admin/theme/show-details'
        );
        return $view;
    }

    public function removeConfirmAction()
    {
        $id = $this->params()->fromQuery('id');

        /** @var \EasyAdmin\Mvc\Controller\Plugin\Addons $addons */
        $addons = $this->easyAdminAddons();
        $addon = $addons->dataFromNamespace($id, 'theme')
            ?: $addons->dataFromNamespace($id);

        $integrity = $addon
            ? $addons->checkIntegrity($addon)
            : ['status' => 'unknown'];

        $form = $this->getForm(ModuleStateForm::class);
        $form->setAttribute('action', $this->url()->fromRoute(
            'admin/easy-admin/default',
            ['controller' => 'theme', 'action' => 'remove']
        ));
        $form->get('id')->setValue($id);

        $view = new ViewModel([
            'addon' => $addon,
            'addonDir' => $id,
            'integrity' => $integrity,
            'form' => $form,
        ]);
        $view->setTerminal(true);
        $view->setTemplate(
            'easy-admin/admin/theme/remove-confirm'
        );
        return $view;
    }

    public function removeAction()
    {
        if (!$this->getRequest()->isPost()) {
            return $this->redirect()->toRoute(
                'admin/easy-admin/default',
                ['controller' => 'theme']
            );
        }

        $id = $this->params()->fromPost('id')
            ?: $this->params()->fromQuery('id');
        if (!$id) {
            $this->messenger()->addError(
                'No theme specified.' // @translate
            );
            return $this->redirect()->toRoute(
                'admin/easy-admin/default',
                ['controller' => 'theme']
            );
        }

        /** @var \EasyAdmin\Mvc\Controller\Plugin\Addons $addons */
        $addons = $this->easyAdminAddons();
        $addon = $addons->dataFromNamespace($id, 'theme')
            ?: $addons->dataFromNamespace($id);
        if (!$addon) {
            $addon = [
                'type' => 'theme',
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
            ['controller' => 'theme']
        );
    }

    public function integrityAction()
    {
        if (!$this->getRequest()->isPost()) {
            return $this->redirect()->toRoute(
                'admin/easy-admin/default',
                ['controller' => 'theme']
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
            $addon = $addons->dataFromNamespace($id, 'theme')
                ?: $addons->dataFromNamespace($id);
            if (!$addon) {
                $addon = [
                    'type' => 'theme',
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
            $report = [];
            $themesDir = OMEKA_PATH . '/themes';
            $dirs = array_diff(
                scandir($themesDir) ?: [],
                ['.', '..']
            );
            foreach ($dirs as $dir) {
                if (!is_dir($themesDir . '/' . $dir)) {
                    continue;
                }
                $addon = $addons->dataFromNamespace($dir, 'theme')
                    ?: $addons->dataFromNamespace($dir);
                if (!$addon) {
                    $addon = [
                        'type' => 'theme',
                        'name' => $dir,
                        'dir' => $dir,
                        'basename' => $dir,
                        'url' => '',
                        'zip' => '',
                        'version' => '',
                        'dependencies' => [],
                    ];
                }
                $report[$dir] = $addons->checkIntegrity(
                    $addon,
                    $fromSource
                );
            }
        }

        return $this->redirect()->toRoute(
            'admin/easy-admin/default',
            ['controller' => 'theme', 'action' => 'integrity-report'],
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
            'easy-admin/admin/theme/integrity-report'
        );
        return $view;
    }

    protected function handlePost($addons): \Laminas\Http\Response
    {
        $post = $this->params()->fromPost();
        $action = $post['bulk_action'] ?? '';
        $selected = $post['themes'] ?? [];

        if ($action && $selected) {
            foreach ($selected as $themeId) {
                switch ($action) {
                    case 'update':
                        $addon = $addons->dataFromNamespace(
                            $themeId,
                            'theme'
                        ) ?: $addons->dataFromNamespace($themeId);
                        if ($addon) {
                            $addons->updateAddon($addon);
                        }
                        break;

                    case 'remove':
                        $addon = $addons->dataFromNamespace(
                            $themeId,
                            'theme'
                        ) ?: $addons->dataFromNamespace($themeId);
                        if (!$addon) {
                            $addon = [
                                'type' => 'theme',
                                'name' => $themeId,
                                'dir' => $themeId,
                                'basename' => $themeId,
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
            ['controller' => 'theme']
        );
    }
}
