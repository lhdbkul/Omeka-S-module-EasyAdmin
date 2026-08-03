<?php declare(strict_types=1);

namespace EasyAdmin\Controller\Admin;

use Common\Stdlib\PsrMessage;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;

class CheckAndFixController extends AbstractActionController
{
    /**
     * Enable the settings enhancements (filter and section navigation), from
     * the button added on the settings pages when they are disabled.
     */
    public function enableSettingsEnhancementsAction()
    {
        $request = $this->getRequest();
        if ($request->isPost()) {
            $validator = new \Laminas\Validator\Csrf(['name' => 'easyadmin_enable_enhancements']);
            if ($validator->isValid((string) $request->getPost('csrf'))) {
                $this->settings()->set('easyadmin_settings_enhancements', true);
                $this->messenger()->addSuccess('The settings filter is now enabled.'); // @translate
            } else {
                $this->messenger()->addError('Invalid or expired CSRF token.'); // @translate
            }
        }
        $referer = $request->getHeader('Referer');
        return $this->redirect()->toUrl($referer
            ? $referer->getFieldValue()
            : $this->url()->fromRoute('admin'));
    }

    public function indexAction()
    {
        /** @var \EasyAdmin\Form\CheckAndFixForm $form */
        $form = $this->getForm(\EasyAdmin\Form\CheckAndFixForm::class);
        $view = new ViewModel([
            'form' => $form,
        ]);

        $request = $this->getRequest();
        if (!$request->isPost()) {
            return $view;
        }

        $params = $request->getPost();

        $form->init();
        $form->setData($params);
        if (!$form->isValid()) {
            $this->messenger()->addErrors($form->getMessages());
            return $view;
        }

        $params = $form->getData();
        unset($params['csrf']);

        // Only first process is managed.
        $process = null;
        foreach ($params as $value) {
            if (is_array($value) && !empty($value['process'])) {
                $process = $value['process'];
                break;
            }
        }

        if (empty($process)) {
            $this->messenger()->addWarning('No process submitted.'); // @translate
            return $view;
        }

        /** @var \Omeka\Mvc\Controller\Plugin\JobDispatcher $dispatcher */
        $job = null;
        $dispatcher = $this->jobDispatcher();
        $defaultParams = [
            'process' => $process,
            'entity_types' => $params['files_checkfix']['entity_types'] ?? ['media'],
        ];

        switch ($process) {
            case 'files_excess_check_full':
                $defaultParams['include_derivatives'] = true;
                // no break
            case 'files_excess_check':
            case 'files_excess_move':
                $job = $dispatcher->dispatch(\EasyAdmin\Job\FileExcess::class, $defaultParams + ($params['files_checkfix']['files_excess'] ?? []));
                break;
            case 'files_missing_check_full':
                $params['files_checkfix']['files_missing']['include_derivatives'] = true;
                // no break
            case 'files_missing_check':
            case 'files_missing_fix':
            case 'files_missing_fix_db':
                $job = $dispatcher->dispatch(\EasyAdmin\Job\FileMissing::class, $defaultParams + $params['files_checkfix']['files_missing']);
                break;
            case 'files_derivative':
                $job = $dispatcher->dispatch(\EasyAdmin\Job\FileDerivative::class, $defaultParams + $params['files_checkfix']['files_derivative']);
                break;
            case 'files_derivative_file_system':
                $job = $dispatcher->dispatch(\EasyAdmin\Job\FileDerivativeFileSystem::class, $defaultParams + $params['files_checkfix']['files_derivative_file_system']);
                break;
            case 'files_media_no_original':
            case 'files_media_no_original_fix':
                $job = $dispatcher->dispatch(\EasyAdmin\Job\FileMediaNoOriginal::class, $defaultParams);
                break;
            case 'dirs_excess':
                $job = $dispatcher->dispatch(\EasyAdmin\Job\DirExcess::class, $defaultParams);
                break;
            case 'files_size_check':
            case 'files_size_fix':
                $job = $dispatcher->dispatch(\EasyAdmin\Job\FileSize::class, $defaultParams);
                break;
            case 'files_hash_check':
            case 'files_hash_fix':
                $job = $dispatcher->dispatch(\EasyAdmin\Job\FileHash::class, $defaultParams);
                break;
            case 'files_storage_check':
            case 'files_storage_fix':
                $job = $dispatcher->dispatch(\EasyAdmin\Job\FileStorage::class, $defaultParams);
                break;
            case 'files_media_type_check':
            case 'files_media_type_fix':
                $job = $dispatcher->dispatch(\EasyAdmin\Job\FileMediaType::class, $defaultParams);
                break;
            case 'files_dimension_check':
            case 'files_dimension_fix':
                $job = $dispatcher->dispatch(\EasyAdmin\Job\FileDimension::class, $defaultParams);
                break;
            case 'media_position_check':
            case 'media_position_fix':
                $job = $dispatcher->dispatch(\EasyAdmin\Job\MediaPosition::class, $defaultParams);
                break;
            case 'db_resource_invalid_check':
            case 'db_resource_invalid_fix':
                $job = $dispatcher->dispatch(\EasyAdmin\Job\DbResourceInvalid::class, $defaultParams);
                break;
            case 'db_loop_save':
                $job = $dispatcher->dispatch(\EasyAdmin\Job\DbLoopResources::class, $defaultParams + $params['resource_values']['db_loop_save']);
                break;
            case 'db_resource_incomplete_check':
            case 'db_resource_incomplete_fix':
                $job = $dispatcher->dispatch(\EasyAdmin\Job\DbResourceIncomplete::class, $defaultParams);
                break;
            case 'db_resource_orphans_check':
            case 'db_resource_orphans_fix':
                $job = $dispatcher->dispatch(\EasyAdmin\Job\DbResourceOrphans::class, $defaultParams);
                break;
            case 'db_item_no_value':
            case 'db_item_no_value_fix':
                $job = $dispatcher->dispatch(\EasyAdmin\Job\DbItemNoValue::class, $defaultParams);
                break;
            case 'db_utf8_encode_check':
            case 'db_utf8_encode_fix':
                $job = $dispatcher->dispatch(\EasyAdmin\Job\DbUtf8Encode::class, $defaultParams + $params['resource_values']['db_utf8_encode']);
                break;
            case 'db_value_clean_fix':
                $job = $dispatcher->dispatch(\EasyAdmin\Job\DbValueClean::class, $defaultParams + $params['resource_values']['db_value_clean']);
                break;
            case 'db_resource_title_check':
            case 'db_resource_title_fix':
                $job = $dispatcher->dispatch(\EasyAdmin\Job\DbResourceTitle::class, $defaultParams + $params['resource_values']['db_resource_title']);
                break;
            case 'db_item_primary_media_check':
            case 'db_item_primary_media_fix':
                $job = $dispatcher->dispatch(\EasyAdmin\Job\DbItemPrimaryMedia::class, $defaultParams);
                break;
            case 'db_value_annotation_template_check':
            case 'db_value_annotation_template_fix':
                $job = $dispatcher->dispatch(\EasyAdmin\Job\DbValueAnnotationTemplate::class, $defaultParams);
                break;
            case 'db_job_check':
            case 'db_job_fix':
            case 'db_job_fix_all':
                $job = $dispatcher->dispatch(\EasyAdmin\Job\DbJob::class, $defaultParams);
                break;
            case 'db_session_check':
            case 'db_session_clean':
            case 'db_session_recreate':
                $job = $dispatcher->dispatch(\EasyAdmin\Job\DbSession::class, $defaultParams + $params['database']['db_session']);
                break;
            case 'db_log_check':
            case 'db_log_clean':
                $job = $dispatcher->dispatch(\EasyAdmin\Job\DbLog::class, $defaultParams + $params['database']['db_log']);
                break;
            case 'db_customvocab_missing_itemsets_check':
            case 'db_customvocab_missing_itemsets_clean':
                $job = $dispatcher->dispatch(\EasyAdmin\Job\DbCustomVocabMissingItemSets::class, $defaultParams + $params['database']['db_customvocab_missing_itemsets']);
                break;
            case 'theme_templates_check':
            case 'theme_templates_fix':
                $job = $dispatcher->dispatch(\EasyAdmin\Job\ThemeTemplate::class, $defaultParams + $params['themes']['theme_templates'] + $params['themes']['theme_templates_warn']);
                break;
            case 'install_check':
                // TODO Improve the form to identify instant process, that are executed directly, not as jobs (quick, instant feedback).
                // TODO Make theses tasks available separately, in particular for a whole check.
                $this->checkInstall();
                break;
            case 'settings_environment_check':
            case 'settings_environment_fix':
                $this->checkSettingsEnvironment(
                    $process === 'settings_environment_fix',
                    !empty($params['system']['settings_environment']['include_empty'])
                );
                break;
            case 'cache_check':
            case 'cache_fix':
                $this->checkCache($params['system']['cache'], $process === 'cache_fix');
                break;
            case 'mail_check':
                $this->checkMail($params['system']['mail'] ?? []);
                break;
            case 'db_fulltext_index':
                $job = $dispatcher->dispatch(\Omeka\Job\IndexFulltextSearch::class);
                break;
            default:
                $eventManager = $this->getEventManager();
                $args = $eventManager->prepareArgs([
                    'process' => $process,
                    'params' => $params,
                    'job' => null,
                    'args' => [],
                ]);
                $eventManager->trigger('easyadmin.job', $this, $args);
                $jobClass = $args['job'];
                if ($jobClass) {
                    $job = $dispatcher->dispatch($jobClass, $args['args']);
                } else {
                    $this->messenger()->addError(new PsrMessage(
                        'Unknown process "{process}"', // @translate
                        ['process' => $process]
                    ));
                }
                break;
        }

        if ($job) {
            $urlPlugin = $this->url();
            $message = new PsrMessage(
                'Processing checks in background (job {link_job}#{job_id}{link_end}, {link_log}logs{link_end}).', // @translate
                [
                    'link_job' => sprintf('<a href="%s">', htmlspecialchars($urlPlugin->fromRoute('admin/id', ['controller' => 'job', 'id' => $job->getId()]))),
                    'job_id' => $job->getId(),
                    'link_end' => '</a>',
                    'link_log' => class_exists('Log\Module', false)
                        ? sprintf('<a href="%1$s">', htmlspecialchars($urlPlugin->fromRoute('admin/default', ['controller' => 'log'], ['query' => ['job_id' => $job->getId()]])))
                        : sprintf('<a href="%1$s" target="_blank" rel="noopener noreferrer">', htmlspecialchars($urlPlugin->fromRoute('admin/id', ['controller' => 'job', 'action' => 'log', 'id' => $job->getId()]))),
                ]
            );
            $message->setEscapeHtml(false);
            $this->messenger()->addSuccess($message);
        }

        // Reset the form after a submission.
        $form = $this->getForm(\EasyAdmin\Form\CheckAndFixForm::class);
        return $view
            ->setVariable('form', $form);
    }

    protected function checkCache(array $options, bool $fix): void
    {
        /** @var \Omeka\Mvc\Controller\Plugin\Messenger $messenger  */
        $messenger = $this->messenger();

        if (empty($options['type'])) {
            $messenger->addWarning('No type of cache selected.'); // @translate
            return;
        }

        if (in_array('doctrine', $options['type'])) {
            // Get the entity manager without factory.
            /** @var \Doctrine\ORM\EntityManager $entityManager */
            $services = $this->getEvent()->getApplication()->getServiceManager();
            $entityManager = $services->get('Omeka\EntityManager');
            $cache = $entityManager->getConfiguration()->getMetadataCache();
            if ($cache) {
                if (!$fix) {
                    $messenger->addNotice('The application cache of Symfony Doctrine is available.'); // @translate
                } else {
                    $result = false;
                    if (method_exists($cache, 'clear')) {
                        $result = $cache->clear();
                    } elseif (method_exists($cache, 'deleteAll')) {
                        $result = $cache->deleteAll();
                    }
                    if ($result) {
                        $messenger->addSuccess('The application cache of Symfony Doctrine was reset.'); // @translate
                    } else {
                        $messenger->addWarning('The application cache of Symfony Doctrine cannot be reset.'); // @translate
                    }
                }
            } else {
                $messenger->addNotice('The application cache of Symfony Doctrine is disabled.'); // @translate
            }
        }

        if (in_array('code', $options['type'])) {
            $hasCache = function_exists('opcache_reset');
            if ($hasCache) {
                $result = @opcache_get_status(false);
                if (!$result) {
                    $messenger->addWarning('An issue occurred when checking status of "opcache" or the status is not enabled.'); // @translate
                } else {
                    /*
                    $resultConfig = @opcache_get_configuration();
                    $json = json_encode($resultConfig, 448);
                    $msg = new PsrMessage(nl2br(htmlspecialchars($json === false ? '{}' : $json, ENT_NOQUOTES | ENT_SUBSTITUTE | ENT_XHTML)));
                    $msg->setEscapeHtml(false);
                    $messenger->addSuccess($msg);
                    */
                    $json = json_encode($result, 448);
                    $msg = new PsrMessage(nl2br(htmlspecialchars($json === false ? '{}' : $json, ENT_NOQUOTES | ENT_SUBSTITUTE | ENT_XHTML)));
                    $msg->setEscapeHtml(false);
                    $messenger->addSuccess($msg);
                }
                if ($fix) {
                    $result = opcache_reset();
                    if ($result) {
                        $messenger->addSuccess('The cache "opcache" was reset.'); // @translate
                    } else {
                        $messenger->addWarning('The cache "opcache" is disabled.'); // @translate
                    }
                }
            } else {
                $messenger->addWarning('The php extension "opcache" is not available.'); // @translate
            }
        }

        if (in_array('data', $options['type'])) {
            $hasCache = function_exists('apcu_clear_cache');
            if ($hasCache) {
                $result = @apcu_cache_info(true);
                if (!$result) {
                    $messenger->addWarning('An issue occurred when checking status of "apcu" or the status is disabled.'); // @translate
                } else {
                    $json = json_encode($result, 448);
                    $msg = new PsrMessage(nl2br(htmlspecialchars($json === false ? '{}' : $json, ENT_NOQUOTES | ENT_SUBSTITUTE | ENT_XHTML)));
                    $msg->setEscapeHtml(false);
                    $messenger->addSuccess($msg);
                }
                if ($fix) {
                    apcu_clear_cache();
                    $messenger->addSuccess('The cache "apcu" was reset.'); // @translate
                }
            } else {
                $messenger->addWarning('The php extension "apcu" is not available.'); // @translate
            }
        }

        if (in_array('path', $options['type'])
            && $fix
        ) {
            @clearstatcache(true);
            $messenger->addSuccess('The cache of real paths was reset.'); // @translate
        }
    }

    protected function checkInstall(): void
    {
        /** @var \Omeka\Mvc\Controller\Plugin\Messenger $messenger  */
        $messenger = $this->messenger();

        // Get the installer without factory.
        /** @var \Omeka\Installation\Installer $installer */
        $services = $this->getEvent()->getApplication()->getServiceManager();
        $installer = $services->get('Omeka\Installer');
        $result = $installer->preInstall();
        if ($result) {
            $messenger->addSuccess('Install tasks were run without errors.'); // @translate
        } else {
            $messenger->addErrors($installer->getErrors());
        }

        if (!extension_loaded('intl')) {
            $messenger->addWarning(
                'The php extension "intl" is not available. It is recommended to install it to translate dates.' // @translate
            );
        }
    }

    /**
     * List (and optionally adapt) the settings that depend on the server, so
     * they can be reviewed after a copy of the database (prod to test/dev).
     *
     * Only settings whose values are environment-specific are reported: the
     * installation title, urls, hosts, paths, emails, api keys and tokens. The
     * detection is based on the name of the setting, so it covers modules
     * without any dependency on them. Secret values (keys, tokens, passwords)
     * are redacted. The optional fix only prefixes the installation title with
     * « TEST »; all other values require a new server-specific value and are
     * listed for a manual review.
     *
     * Empty settings are listed only when $includeEmpty is set, and some known
     * settings that match a pattern but never depend on the server (standard
     * rights uris, durations, static route paths…) are always excluded.
     */
    protected function checkSettingsEnvironment(bool $fix, bool $includeEmpty): void
    {
        /** @var \Omeka\Mvc\Controller\Plugin\Messenger $messenger */
        $messenger = $this->messenger();

        $services = $this->getEvent()->getApplication()->getServiceManager();
        /** @var \Doctrine\DBAL\Connection $connection */
        $connection = $services->get('Omeka\Connection');

        // Settings matching a pattern but never depending on the server: they
        // are standard rights uris, durations wrongly matched as "token", or
        // static route paths. They are always excluded from the report.
        $excluded = [
            'iiifserver_manifest_rights_uri',
            'iiifserver_manifest_rights_url',
            'imageserver_info_rights_uri',
            'imageserver_info_rights_url',
            'botchallenge_exception_paths',
            'oaipmhrepository_token_expiration_time',
            'aigenerator_max_tokens',
            'contribute_token_duration',
            'guest_forgot_password_html_after',
            'guest_forgot_password_html_before',
            'guest_forgot_password_use_link_in_dialog_login',
        ];

        // Category of a setting, or null when it is not environment-specific.
        // Order matters: secrets are checked before emails/urls/paths so a key
        // like "credential_key_path" is redacted instead of shown as a path.
        $categorize = function (string $key) use ($excluded): ?string {
            if (in_array($key, $excluded, true)) {
                return null;
            }
            if ($key === 'installation_title') {
                return 'title';
            }
            if (preg_match('/(api_?key|_token|_secret|password|_credential)/', $key)) {
                return 'secret';
            }
            if (preg_match('/(_email|_recipient|_sender|reply_to)/', $key)) {
                return 'email';
            }
            if (preg_match('/(base_uri|_uri|_url|_host|_hostname|_endpoint)/', $key)) {
                return 'url';
            }
            if (preg_match('/(_path|_dir|_directory)/', $key)) {
                return 'path';
            }
            return null;
        };

        $labels = [
            'title' => 'Installation title', // @translate
            'url' => 'Urls, hosts and endpoints', // @translate
            'path' => 'Paths and directories', // @translate
            'email' => 'Emails', // @translate
            'secret' => 'Api keys, tokens and secrets (redacted)', // @translate
        ];

        $isEmpty = fn ($value): bool => $value === null || $value === '' || $value === [];

        // The category is guessed from the name, so filter out false positives
        // (option tokens, flags, email templates…) by the shape of the value.
        // The installation title is always shown; empty values only when the
        // option is set, as they may need a server-specific value.
        $relevant = function (string $category, $value) use ($isEmpty, $includeEmpty): bool {
            if ($category === 'title') {
                return true;
            }
            if ($isEmpty($value)) {
                return $includeEmpty;
            }
            if ($category === 'secret') {
                return true;
            }
            $flat = is_array($value)
                ? implode(' ', array_map(fn ($v) => is_scalar($v) ? (string) $v : '', $value))
                : (is_scalar($value) ? (string) $value : '');
            switch ($category) {
                case 'url': return str_contains($flat, '://') || str_starts_with($flat, '//');
                case 'path': return str_contains($flat, '/');
                case 'email': return str_contains($flat, '@');
                default: return true;
            }
        };

        // Format a decoded value for display, redacting secrets.
        $format = function ($value, bool $secret) use ($isEmpty): string {
            if ($isEmpty($value)) {
                return '(empty)';
            }
            if ($secret) {
                return '••• (defined)';
            }
            if (is_scalar($value)) {
                return (string) $value;
            }
            return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        };

        $grouped = array_fill_keys(array_keys($labels), []);

        $collect = function (string $key, $rawValue, string $prefix) use ($categorize, $relevant, $format, &$grouped): void {
            $category = $categorize($key);
            if (!$category) {
                return;
            }
            $value = is_string($rawValue) ? json_decode($rawValue, true) : $rawValue;
            if (!$relevant($category, $value)) {
                return;
            }
            $grouped[$category][] = sprintf('%s%s = %s', $prefix, $key, $format($value, $category === 'secret'));
        };

        $settings = $connection->executeQuery('SELECT id, value FROM setting ORDER BY id')->fetchAllAssociative();
        foreach ($settings as $row) {
            $collect($row['id'], $row['value'], '');
        }

        $siteSettings = $connection->executeQuery('SELECT id, site_id, value FROM site_setting ORDER BY site_id, id')->fetchAllAssociative();
        foreach ($siteSettings as $row) {
            $collect($row['id'], $row['value'], sprintf('[site #%s] ', $row['site_id']));
        }

        $blocks = [];
        foreach ($labels as $category => $label) {
            if (!$grouped[$category]) {
                continue;
            }
            $blocks[] = '<strong>' . $this->translator()->translate($label) . '</strong><br/>'
                . implode('<br/>', array_map('htmlspecialchars', $grouped[$category]));
        }

        if (!$blocks) {
            $messenger->addNotice('No environment-specific setting found.'); // @translate
        } else {
            $message = new PsrMessage(
                "Settings to review after a copy of the database:\n{list}", // @translate
                ['list' => '<br/><br/>' . implode('<br/><br/>', $blocks)]
            );
            $message->setEscapeHtml(false);
            $messenger->addSuccess($message);
        }

        // Configuration outside the settings tables, not detectable here.
        $messenger->addNotice(new PsrMessage(
            'Also review configurations stored outside the settings: search engines (SearchSolr cores/nodes, url and host), file paths and mailer in "config/local.config.php", and any external service of the modules.' // @translate
        ));

        if (!$fix) {
            return;
        }

        /** @var \Omeka\Settings\Settings $mainSettings */
        $mainSettings = $services->get('Omeka\Settings');
        $title = (string) $mainSettings->get('installation_title', '');
        if (mb_strpos($title, 'TEST') === 0) {
            $messenger->addNotice(new PsrMessage(
                'The installation title already starts with « TEST »: {title}', // @translate
                ['title' => $title]
            ));
        } else {
            $newTitle = trim('TEST ' . $title);
            $mainSettings->set('installation_title', $newTitle);
            $messenger->addSuccess(new PsrMessage(
                'The installation title is now: {title}', // @translate
                ['title' => $newTitle]
            ));
        }
    }

    protected function checkMail(array $options): void
    {
        /** @var \Omeka\Mvc\Controller\Plugin\Messenger $messenger */
        $messenger = $this->messenger();

        /** @var \EasyAdmin\Mvc\Controller\Plugin\CheckMailer $checkMailer */
        $checkMailer = $this->checkMailer();

        // Get the services (needed for mailer).
        $services = $this->getEvent()->getApplication()->getServiceManager();
        $settings = $services->get('Omeka\Settings');

        // Get and display configuration summary.
        $configSummary = $checkMailer->getConfigSummary();
        $configHtml = implode('<br/>', array_map('strval', $configSummary));
        $message = new PsrMessage(
            "Current email configuration:\n{config}", // @translate
            ['config' => '<br/>' . $configHtml]
        );
        $message->setEscapeHtml(false);
        $messenger->addSuccess($message);

        // Check dns records if requested.
        $checkDns = !empty($options['check_dns']);
        if ($checkDns) {
            $checkMailer->checkDnsWithMessages($options);
        }

        // Send test email if recipient is provided.
        $recipient = trim($options['recipient'] ?? '');
        if (empty($recipient)) {
            $messenger->addNotice('No recipient email provided. Test email was not sent.'); // @translate
            return;
        }

        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            $messenger->addError(new PsrMessage(
                'Invalid recipient email address: {email}', // @translate
                ['email' => $recipient]
            ));
            return;
        }

        // Send test email.
        try {
            /** @var \Omeka\Stdlib\Mailer $mailer */
            $mailer = $services->get('Omeka\Mailer');
            $installationTitle = $settings->get('installation_title', 'Omeka S');

            $subject = new PsrMessage(
                '[{title}] Test email', // @translate
                ['title' => $installationTitle]
            );
            $body = new PsrMessage(
                "This is a test email from {title}.\n\nEmail configuration:\n{config}\n\nSent at: {datetime}", // @translate
                ['title' => $installationTitle, 'config' => implode("\n", $configSummary), 'datetime' => date('Y-m-d H:i:s T')]
            );

            $message = $mailer->createMessage();
            $message->addTo($recipient)
                ->setSubject($subject->setTranslator($this->translator()))
                ->setBody($body->setTranslator($this->translator()));

            $mailer->send($message);

            $messenger->addSuccess(new PsrMessage(
                'Test email successfully sent to {email}.', // @translate
                ['email' => $recipient]
            ));
        } catch (\Throwable $e) {
            $messenger->addError(new PsrMessage(
                'Failed to send test email: {error}', // @translate
                ['error' => $e->getMessage()]
            ));

            // Diagnose ssl/tls mismatch from the error message.
            $errorMsg = $e->getMessage();
            $mailConfig = $services->get('Config')['mail'] ?? [];
            $transportOptions = $mailConfig['transport']['options'] ?? [];
            $ssl = $transportOptions['connection_config']['ssl'] ?? null;
            $port = $transportOptions['port'] ?? null;
            if ($ssl && (
                stripos($errorMsg, 'ssl') !== false
                || stripos($errorMsg, 'tls') !== false
                || stripos($errorMsg, 'crypto') !== false
                || stripos($errorMsg, 'connect') !== false
            )) {
                $alternative = $ssl === 'tls' ? 'ssl' : 'tls';
                $altPort = $ssl === 'tls' ? 465 : 587;
                $messenger->addNotice(new PsrMessage(
                    'Hint: Your current security setting is "ssl" => "{current}" (port {port}). A common fix is to try "ssl" => "{alternative}" with port {alt_port}.', // @translate
                    ['current' => $ssl, 'port' => $port ?: '?', 'alternative' => $alternative, 'alt_port' => $altPort]
                ));
            }
        }
    }
}
