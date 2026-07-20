<?php declare(strict_types=1);

namespace EasyAdmin\Job;

use Laminas\I18n\Translator\TranslatorAwareInterface;
use Laminas\Log\Logger;
use Omeka\Job\AbstractJob;
use Omeka\Mvc\Controller\Plugin\Messenger;

class ManageAddons extends AbstractJob
{
    /**
     * @var \Laminas\Log\Logger
     */
    protected $logger;

    public function perform(): void
    {
        /**
         * @var \EasyAdmin\Mvc\Controller\Plugin\Addons $addons
         * @var \Omeka\Mvc\Controller\Plugin\Messenger $messenger
         */

        $services = $this->getServiceLocator();

        $referenceIdProcessor = new \Laminas\Log\Processor\ReferenceId();
        $referenceIdProcessor->setReferenceId(
            'easy-admin/addons/job_' . $this->job->getId()
        );

        $this->logger = $services->get('Omeka\Logger');
        $this->logger->addProcessor($referenceIdProcessor);

        $plugins = $services->get('ControllerPluginManager');
        $addons = $plugins->get('easyAdminAddons');
        $messenger = $plugins->get('messenger');

        $operation = $this->getArg('operation', 'install');
        $options = $this->getArg('options', []);

        switch ($operation) {
            case 'install':
                $this->performInstall($addons, $messenger);
                break;
            case 'update':
                $this->performUpdate($addons, $messenger, $options);
                break;
            case 'upgrade':
                $this->performUpgrade($addons, $messenger);
                break;
            case 'remove':
                $this->performRemove($addons, $messenger);
                break;
            default:
                // Legacy: selection-based install.
                $this->performInstall($addons, $messenger);
                break;
        }
    }

    protected function performInstall($addons, $messenger): void
    {
        // Support legacy selection mode.
        $selection = $this->getArg('selection');
        $addonList = $this->getArg('addons', []);

        if ($selection) {
            $selections = $addons->getSelections();
            $addonList = $selections[$selection] ?? [];
        }

        // Install the dependencies before the modules that require them.
        $addonList = $addons->sortByDependencies($addonList);

        $unknowns = [];
        $existings = [];
        $errors = [];
        $installeds = [];
        foreach ($addonList as $addonName) {
            $addon = $addons->dataFromNamespace($addonName);
            if (!$addon) {
                $unknowns[] = $addonName;
            } elseif ($addons->dirExists($addon)) {
                $existings[] = $addonName;
            } else {
                $result = $addons->installAddon($addon);
                if ($result) {
                    $installeds[] = $addonName;
                } else {
                    $errors[] = $addonName;
                }
                // Log after each addon: the messages would be lost when the
                // job is stopped or fails in the middle of the process.
                $this->flushMessages($messenger);
            }
        }

        $this->flushMessages($messenger);
        $this->logSummary(
            'install',
            $unknowns,
            $existings,
            $errors,
            $installeds
        );
    }

    protected function performUpdate(
        $addons,
        $messenger,
        array $options
    ): void {
        $type = $this->getArg('type', 'module');
        $addonList = $this->getArg('addons', []);
        // Update the dependencies before the modules that require them. Only
        // modules have dependencies.
        if ($type === 'module') {
            $addonList = $addons->sortByDependencies($addonList);
        }
        $autoUpgrade = !empty($options['auto_upgrade']);

        $errors = [];
        $updated = [];
        foreach ($addonList as $addonName) {
            $addon = $addons->dataFromNamespace($addonName, $type)
                ?: $addons->dataFromNamespace($addonName);
            if (!$addon) {
                $this->logger->warn(
                    'Unknown addon for update: {name}.', // @translate
                    ['name' => $addonName]
                );
                continue;
            }
            $result = $addons->updateAddon($addon);
            if ($result) {
                $updated[] = $addonName;
                if ($autoUpgrade) {
                    $this->tryUpgradeDb($addonName);
                }
            } else {
                $errors[] = $addonName;
            }
            // Log after each addon: the messages would be lost when the job is
            // stopped or fails in the middle of the process.
            $this->flushMessages($messenger);
        }

        $this->flushMessages($messenger);

        if ($errors) {
            $this->job->setStatus(
                \Omeka\Entity\Job::STATUS_ERROR
            );
            $this->logger->err(
                'Failed to update: {addons}.', // @translate
                ['addons' => implode(', ', $errors)]
            );
        }
        if ($updated) {
            $this->logger->notice(
                'Updated: {addons}.', // @translate
                ['addons' => implode(', ', $updated)]
            );
        }
    }

    /**
     * Upgrade the database of modules whose files are already updated.
     *
     * This is not the download of the new files, that is done by the operation
     * "update".
     */
    protected function performUpgrade($addons, $messenger): void
    {
        // Upgrade the dependencies before the modules that require them.
        $addonList = $addons->sortByDependencies(
            $this->getArg('addons', [])
        );

        foreach ($addonList as $addonName) {
            $this->tryUpgradeDb($addonName);
            $this->flushMessages($messenger);
        }

        $this->flushMessages($messenger);
    }

    protected function performRemove($addons, $messenger): void
    {
        $type = $this->getArg('type', 'module');
        $addonList = $this->getArg('addons', []);
        // Remove the modules that require a dependency before the dependency.
        // Only modules have dependencies.
        if ($type === 'module') {
            $addonList = $addons->sortByDependencies($addonList, true);
        }

        $errors = [];
        $removed = [];
        foreach ($addonList as $addonName) {
            $addon = $addons->dataFromNamespace($addonName, $type)
                ?: $addons->dataFromNamespace($addonName);
            if (!$addon) {
                $addon = [
                    'type' => $type,
                    'name' => $addonName,
                    'dir' => $addonName,
                    'basename' => $addonName,
                    'url' => '',
                    'zip' => '',
                    'version' => '',
                    'dependencies' => [],
                ];
            }
            $result = $addons->removeAddon($addon);
            if ($result) {
                $removed[] = $addonName;
            } else {
                $errors[] = $addonName;
            }
            // Log after each addon: the messages would be lost when the job is
            // stopped or fails in the middle of the process.
            $this->flushMessages($messenger);
        }

        $this->flushMessages($messenger);

        if ($errors) {
            $this->job->setStatus(
                \Omeka\Entity\Job::STATUS_ERROR
            );
            $this->logger->err(
                'Failed to remove: {addons}.', // @translate
                ['addons' => implode(', ', $errors)]
            );
        }
        if ($removed) {
            $this->logger->notice(
                'Removed: {addons}.', // @translate
                ['addons' => implode(', ', $removed)]
            );
        }
    }

    protected function tryUpgradeDb(string $moduleId): void
    {
        $services = $this->getServiceLocator();
        $moduleManager = $services->get('Omeka\ModuleManager');
        $module = $moduleManager->getModule($moduleId);
        if ($module
            && $module->getState()
                === \Omeka\Module\Manager::STATE_NEEDS_UPGRADE
        ) {
            try {
                $moduleManager->upgrade($module);
                $this->logger->notice(
                    'Upgrade of module "{name}" finalized.', // @translate
                    ['name' => $moduleId]
                );
            } catch (\Throwable $e) {
                $this->logger->err(
                    'Upgrade of module "{name}" could not be finalized: {error}', // @translate
                    [
                        'name' => $moduleId,
                        'error' => $e->getMessage(),
                    ]
                );
            }
        }
    }

    /**
     * Convert messenger messages into logger entries.
     */
    protected function flushMessages($messenger): void
    {
        $typesToLogPriorities = [
            Messenger::ERROR => Logger::ERR,
            Messenger::SUCCESS => Logger::NOTICE,
            Messenger::WARNING => Logger::WARN,
            Messenger::NOTICE => Logger::INFO,
        ];
        foreach ($messenger->get() as $type => $messages) {
            foreach ($messages as $message) {
                $priority = $typesToLogPriorities[$type]
                    ?? Logger::NOTICE;
                if ($message instanceof TranslatorAwareInterface) {
                    $this->logger->log(
                        $priority,
                        $message->getMessage(),
                        $message->getContext()
                    );
                } else {
                    $this->logger->log(
                        $priority,
                        (string) $message
                    );
                }
            }
        }

        // Messenger::get() does not empty the stack, so clear it to avoid
        // logging the same messages again on the next flush.
        $messenger->clear();
    }

    protected function logSummary(
        string $operation,
        array $unknowns,
        array $existings,
        array $errors,
        array $success
    ): void {
        if ($unknowns) {
            $this->logger->notice(
                'The following addons are unknown: {addons}.', // @translate
                ['addons' => implode(', ', $unknowns)]
            );
        }
        if ($existings) {
            $this->logger->notice(
                'The following addons are already installed: {addons}.', // @translate
                ['addons' => implode(', ', $existings)]
            );
        }
        if ($errors) {
            $this->job->setStatus(
                \Omeka\Entity\Job::STATUS_ERROR
            );
            $this->logger->err(
                'The following addons failed to {operation}: {addons}.', // @translate
                [
                    'operation' => $operation,
                    'addons' => implode(', ', $errors),
                ]
            );
        }
        if ($success) {
            $this->logger->notice(
                'The following addons were successfully with {operation}: {addons}.', // @translate
                [
                    'operation' => $operation,
                    'addons' => implode(', ', $success),
                ]
            );
        }
    }
}
