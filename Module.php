<?php declare(strict_types=1);

/*
 * Copyright 2017-2022 Daniel Berthereau
 *
 * This software is governed by the CeCILL license under French law and abiding
 * by the rules of distribution of free software. You can use, modify and/or
 * redistribute the software under the terms of the CeCILL license as circulated
 * by CEA, CNRS and INRIA at the following URL "http://www.cecill.info".
 *
 * As a counterpart to the access to the source code and rights to copy, modify
 * and redistribute granted by the license, users are provided only with a
 * limited warranty and the software’s author, the holder of the economic
 * rights, and the successive licensors have only limited liability.
 *
 * In this respect, the user’s attention is drawn to the risks associated with
 * loading, using, modifying and/or developing or reproducing the software by
 * the user in light of its specific status of free software, that may mean that
 * it is complicated to manipulate, and that also therefore means that it is
 * reserved for developers and experienced professionals having in-depth
 * computer knowledge. Users are therefore encouraged to load and test the
 * software’s suitability as regards their requirements in conditions enabling
 * the security of their systems and/or data to be ensured and, more generally,
 * to use and operate it in the same conditions as regards security.
 *
 * The fact that you are presently reading this means that you have had
 * knowledge of the CeCILL license and that you accept its terms.
 */

namespace EasyAdmin;

if (!class_exists(\Generic\AbstractModule::class)) {
    require file_exists(dirname(__DIR__) . '/Generic/AbstractModule.php')
        ? dirname(__DIR__) . '/Generic/AbstractModule.php'
        : __DIR__ . '/src/Generic/AbstractModule.php';
}

use Generic\AbstractModule;
use Laminas\EventManager\Event;
use Laminas\EventManager\SharedEventManagerInterface;

/**
 * Easy Admin
 *
 * @copyright Daniel Berthereau, 2017-2022
 * @license http://www.cecill.info/licences/Licence_CeCILL_V2.1-en.txt
 */
class Module extends AbstractModule
{
    const NAMESPACE = __NAMESPACE__;

    protected $dependency = 'Log';

    protected function preInstall(): void
    {
        $this->installDir();
    }

    protected function installDir(): void
    {
        $config = $this->getServiceLocator()->get('Config');
        $basePath = $config['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');

        // Automatic upgrade from module Bulk Check.
        $result = null;
        $bulkCheckPath = $basePath . '/bulk_check';
        if (file_exists($bulkCheckPath) && is_dir($bulkCheckPath)) {
            $result = rename($bulkCheckPath, $basePath . '/check');
            if (!$result) {
                $message = new \Omeka\Stdlib\Message(
                    'Upgrading module BulkCheck: Unable to rename directory "files/bulk_check" into "files/check". Trying to create it.' // @translate
                );
                $messenger = new \Omeka\Mvc\Controller\Plugin\Messenger();
                $messenger->addWarning($message);
            }
        }

        if (!$result && !$this->checkDestinationDir($basePath . '/check')) {
            $message = new \Omeka\Stdlib\Message(
                'The directory "%s" is not writeable.', // @translate
                $basePath
            );
            throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $message);
        }

        $services = $this->getServiceLocator();
        /** @var \Omeka\Module\Manager $moduleManager */
        $moduleManager = $services->get('Omeka\ModuleManager');
        $module = $moduleManager->getModule('BulkCheck');
        if (!$module || in_array($module->getState(), [
            \Omeka\Module\Manager::STATE_NOT_FOUND,
            \Omeka\Module\Manager::STATE_NOT_INSTALLED,
        ])) {
            return;
        }

        // The module BulkCheck doesn't have any param, so it is uninstalled
        // directly.
        $sql = 'DELETE FROM `module` WHERE `id` = "BulkCheck";';
        $connection = $this->getServiceLocator()->get('Omeka\Connection');
        $connection->executeStatement($sql);
        $message = new \Omeka\Stdlib\Message(
            'The module "%s" was upgraded by module "%s" and uninstalled.', // @translate
            'Bulk Check', 'Easy Admin'
        );
        $messenger = new \Omeka\Mvc\Controller\Plugin\Messenger();
        $messenger->addWarning($message);
    }

    protected function preUninstall(): void
    {
        if (!empty($_POST['remove-dir-check'])) {
            $config = $this->getServiceLocator()->get('Config');
            $basePath = $config['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');
            $this->rmDir($basePath . '/check');
        }
    }

    public function warnUninstall(Event $event): void
    {
        $view = $event->getTarget();
        $module = $view->vars()->module;
        if ($module->getId() != __NAMESPACE__) {
            return;
        }

        $serviceLocator = $this->getServiceLocator();
        $t = $serviceLocator->get('MvcTranslator');
        $config = $this->getServiceLocator()->get('Config');
        $basePath = $config['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');

        $html = '<p>';
        $html .= '<strong>';
        $html .= $t->translate('WARNING:'); // @translate
        $html .= '</strong>';
        $html .= '</p>';

        $html .= '<p>';
        $html .= sprintf(
            $t->translate('All stored files from checks and fixes, if any, will be removed from folder "{folder}".'), // @translate
            $basePath . '/check'
        );
        $html .= '</p>';

        $html .= '<label><input name="remove-dir-check" type="checkbox" form="confirmform">';
        $html .= $t->translate('Remove directory "files/check"'); // @translate
        $html .= '</label>';

        echo $html;
    }

    public function attachListeners(SharedEventManagerInterface $sharedEventManager): void
    {
        // Display a warn before uninstalling.
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\Module',
            'view.details',
            [$this, 'warnUninstall']
        );
    }

    /**
     * Check or create the destination folder.
     *
     * @param string $dirPath Absolute path.
     * @return string|null
     */
    protected function checkDestinationDir($dirPath)
    {
        if (file_exists($dirPath)) {
            if (!is_dir($dirPath) || !is_readable($dirPath) || !is_writable($dirPath)) {
                $this->getServiceLocator()->get('Omeka\Logger')->err(
                    'The directory "{path}" is not writeable.', // @translate
                    ['path' => $dirPath]
                );
                return null;
            }
            return $dirPath;
        }

        $result = @mkdir($dirPath, 0775, true);
        if (!$result) {
            $this->getServiceLocator()->get('Omeka\Logger')->err(
                'The directory "{path}" is not writeable: {error}.', // @translate
                ['path' => $dirPath, 'error' => error_get_last()['message']]
            );
            return null;
        }
        return $dirPath;
    }

    /**
     * Remove a dir from filesystem.
     *
     * @param string $dirpath Absolute path.
     */
    private function rmDir(string $dirPath): bool
    {
        if (!file_exists($dirPath)) {
            return true;
        }
        if (strpos($dirPath, '/..') !== false || substr($dirPath, 0, 1) !== '/') {
            return false;
        }
        $files = array_diff(scandir($dirPath), ['.', '..']);
        foreach ($files as $file) {
            $path = $dirPath . '/' . $file;
            if (is_dir($path)) {
                $this->rmDir($path);
            } else {
                unlink($path);
            }
        }
        return rmdir($dirPath);
    }
}
