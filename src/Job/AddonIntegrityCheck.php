<?php declare(strict_types=1);

namespace EasyAdmin\Job;

use Omeka\Job\AbstractJob;

class AddonIntegrityCheck extends AbstractJob
{
    /**
     * @var \Laminas\Log\Logger
     */
    protected $logger;

    public function perform(): void
    {
        $services = $this->getServiceLocator();

        $referenceIdProcessor = new \Laminas\Log\Processor\ReferenceId();
        $referenceIdProcessor->setReferenceId(
            'easy-admin/integrity/job_' . $this->job->getId()
        );

        $this->logger = $services->get('Omeka\Logger');
        $this->logger->addProcessor($referenceIdProcessor);

        $plugins = $services->get('ControllerPluginManager');
        /** @var \EasyAdmin\Mvc\Controller\Plugin\Addons $addons */
        $addons = $plugins->get('easyAdminAddons');

        $addonList = $this->getArg('addons', []);
        $fromSource = (bool) $this->getArg('from_source', false);

        // If empty, check all installed modules.
        if (empty($addonList)) {
            $moduleManager = $services->get('Omeka\ModuleManager');
            $modules = $moduleManager->getModules();
            $addonList = array_keys($modules);
        }

        $report = [];
        $hasIssues = false;
        foreach ($addonList as $moduleId) {
            $addon = $addons->dataFromNamespace($moduleId, 'module')
                ?: $addons->dataFromNamespace($moduleId);
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

            $result = $addons->checkIntegrity(
                $addon,
                $fromSource
            );
            $report[$moduleId] = $result;

            if ($result['status'] === 'modified') {
                $hasIssues = true;
                $this->logger->warn(
                    'Module "{name}" has been modified: {count} file(s).', // @translate
                    [
                        'name' => $moduleId,
                        'count' => count($result['modified'])
                            + count($result['added'])
                            + count($result['deleted']),
                    ]
                );
            } elseif ($result['status'] === 'clean') {
                $this->logger->info(
                    'Module "{name}" is clean.', // @translate
                    ['name' => $moduleId]
                );
            } else {
                $this->logger->info(
                    'Module "{name}": no checksums available.', // @translate
                    ['name' => $moduleId]
                );
            }
        }

        // Store the report.
        $config = $services->get('Config');
        $basePath = $config['file_store']['local']['base_path']
            ?: (OMEKA_PATH . '/files');
        $reportDir = $basePath . '/check';
        if (!is_dir($reportDir)) {
            @mkdir($reportDir, 0775, true);
        }
        $reportFile = $reportDir . '/integrity-report-'
            . date('Ymd-His') . '.json';
        file_put_contents(
            $reportFile,
            json_encode($report, JSON_PRETTY_PRINT
                | JSON_UNESCAPED_SLASHES)
        );

        $this->logger->notice(
            'Integrity report saved to {file}.', // @translate
            ['file' => basename($reportFile)]
        );

        if ($hasIssues) {
            $this->logger->warn(
                'Some modules have been modified. Check the report for details.', // @translate
            );
        } else {
            $this->logger->notice(
                'All checked modules are clean or have no reference checksums.', // @translate
            );
        }
    }
}
