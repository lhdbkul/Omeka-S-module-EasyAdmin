<?php declare(strict_types=1);

namespace EasyAdmin\Mvc\Controller\Plugin;

use Common\Stdlib\PsrMessage;
use Doctrine\Inflector\InflectorFactory;
use Exception;
use Laminas\Http\Client\Adapter\Exception\RuntimeException;
use Laminas\Http\Client as HttpClient;
use Laminas\Mvc\Controller\Plugin\AbstractPlugin;
use Laminas\Session\Container;
use Laminas\Uri\Http as HttpUri;
use Omeka\Api\Representation\ModuleRepresentation;
use Omeka\Module\Manager as ModuleManager;
use Omeka\Mvc\Controller\Plugin\Api;
use Omeka\Mvc\Controller\Plugin\Messenger;
use ZipArchive;

/**
 * Manage addons for Omeka.
 *
 * A simplified version can be found in tool Install Omeka S.
 * @see https://daniel-km.github.io/UpgradeToOmekaS/omeka_s_install.html
 *
 * @todo This plugin can be simplified if the lists contain all the data.
 */
class Addons extends AbstractPlugin
{
    /**
     * @var \Omeka\Mvc\Controller\Plugin\Api
     */
    protected $api;

    /**
     * @var \Laminas\Http\Client
     */
    protected $httpClient;

    /**
     * @var \Omeka\Mvc\Controller\Plugin\Messenger
     */
    protected $messenger;

    /**
     * @var \Omeka\Module\Manager
     */
    protected $moduleManager;

    /**
     * Source of data and destination of addons.
     *
     * @var array
     */
    protected $data = [
        'omekamodule' => [
            'source' => 'https://omeka.org/add-ons/json/s_module.json',
            'destination' => '/modules',
        ],
        'omekatheme' => [
            'source' => 'https://omeka.org/add-ons/json/s_theme.json',
            'destination' => '/themes',
        ],
        'module' => [
            'source' => 'https://raw.githubusercontent.com/Daniel-KM/UpgradeToOmekaS/master/_data/omeka_s_modules.csv',
            'destination' => '/modules',
        ],
        'theme' => [
            'source' => 'https://raw.githubusercontent.com/Daniel-KM/UpgradeToOmekaS/master/_data/omeka_s_themes.csv',
            'destination' => '/themes',
        ],
    ];

    /**
     * Cache for the list of addons.
     *
     * @var array
     */
    protected $addons = [];

    /**
     * Expiration hops.
     *
     * @var int
     */
    protected $expirationHops = 10;

    /**
     * Expiration seconds.
     *
     * @var int
     */
    protected $expirationSeconds = 3600;

    /**
     * Cache for the list of selections.
     *
     * @var array
     */
    protected $selections = [];

    public function __construct(
        Api $api,
        HttpClient $httpClient,
        Messenger $messenger,
        ?ModuleManager $moduleManager = null
    ) {
        $this->api = $api;
        $this->httpClient = $httpClient;
        $this->messenger = $messenger;
        $this->moduleManager = $moduleManager;
    }

    public function __invoke(): self
    {
        return $this;
    }

    public function getAddons(bool $refresh = false): array
    {
        $this->initAddons($refresh);
        return $this->addons;
    }

    /**
     * Get curated selections of modules from the web.
     */
    public function getSelections(bool $refresh = false): array
    {
        // Build the list of selections only once.
        $isEmpty = !count($this->selections);

        if (!$refresh && !$isEmpty) {
            return $this->selections;
        }

        // Check the cache.
        $container = new Container('EasyAdmin');
        if (!$refresh && isset($container->selections)) {
            $this->selections = $container->selections;
            $isEmpty = !count($this->selections);
            if (!$isEmpty) {
                return $this->selections;
            }
        }

        $this->selections = [];
        $csv = @file_get_contents('https://raw.githubusercontent.com/Daniel-KM/UpgradeToOmekaS/refs/heads/master/_data/omeka_s_selections.csv');
        if ($csv) {
            // Get the column for name and modules.
            $headers = [];
            $isFirst = true;
            foreach (explode("\n", $csv) as $row) {
                $row = str_getcsv($row) ?: [];
                if ($isFirst) {
                    $headers = array_flip($row);
                    $isFirst = false;
                } elseif ($row) {
                    $name = $row[$headers['Name']] ?? '';
                    if ($name) {
                        $dirs = explode(',', $row[$headers['Modules and themes']] ?? '');
                        $this->selections[$name] = array_values(array_filter(array_map(
                            fn ($v) => str_replace(' ', '', trim($v)),
                            $dirs
                        )));
                    }
                }
            }
        }

        $container->selections = $this->selections;
        $container
            ->setExpirationSeconds($this->expirationSeconds)
            ->setExpirationHops($this->expirationHops);

        return $this->selections;
    }

    /**
     * Check if the lists of addons are empty before init.
     */
    public function isEmpty(): bool
    {
        if (empty($this->addons)) {
            return true;
        }
        foreach ($this->addons as $addons) {
            if (!empty($addons)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Get the list of default types.
     */
    public function types(): array
    {
        return array_keys($this->data);
    }

    /**
     * Get addon data from the namespace of the module.
     */
    public function dataFromNamespace(string $namespace, ?string $type = null): array
    {
        $listAddons = $this->getAddons();

        $list = $type
            ? (isset($listAddons[$type]) ? [$type => $listAddons[$type]] : [])
            : $listAddons;
        foreach ($list as $type => $addonsForType) {
            $addonsUrl = array_column($addonsForType, 'url', 'dir');
            if (isset($addonsUrl[$namespace]) && isset($addonsForType[$addonsUrl[$namespace]])) {
                return $addonsForType[$addonsUrl[$namespace]];
            }
        }
        return [];
    }

    /**
     * Get addon data from the url of the repository.
     */
    public function dataFromUrl(string $url, string $type): array
    {
        $listAddons = $this->getAddons();
        return $listAddons && isset($listAddons[$type][$url])
            ? $listAddons[$type][$url]
            : [];
    }

    /**
     * Check if an addon is installed.
     *
     * @param array $addon
     */
    public function dirExists($addon): bool
    {
        $destination = OMEKA_PATH . $this->data[$addon['type']]['destination'];
        $existings = $this->listDirsInDir($destination);
        $existings = array_map('strtolower', $existings);
        return in_array(strtolower($addon['dir']), $existings)
            || in_array(strtolower($addon['basename']), $existings);
    }

    /**
     * Check if an addon is managed by Composer (in composer-addons/).
     */
    public function isComposerManaged(array $addon): bool
    {
        $type = $addon['type'] ?? '';
        $dir = $addon['dir'] ?? '';
        if (!$type || !$dir) {
            return false;
        }

        $subDir = in_array($type, ['module', 'omekamodule'])
            ? 'modules' : 'themes';
        $composerPath = OMEKA_PATH . '/composer-addons/' . $subDir
            . '/' . $dir;

        if (!file_exists($composerPath)) {
            return false;
        }

        // Check if the local path is a symlink pointing to
        // composer-addons.
        $localPath = OMEKA_PATH . '/' . $subDir . '/' . $dir;
        if (is_link($localPath)) {
            $target = realpath($localPath);
            $composerReal = realpath($composerPath);
            if ($target && $composerReal
                && strpos($target, $composerReal) === 0
            ) {
                return true;
            }
        }

        // The addon exists only in composer-addons/.
        $destination = OMEKA_PATH . '/' . $subDir . '/' . $dir;
        return !file_exists($destination) || !is_dir($destination);
    }

    /**
     * Get the installed version from the addon ini file.
     */
    public function getInstalledVersion(array $addon): ?string
    {
        $type = $addon['type'] ?? '';
        $dir = $addon['dir'] ?? '';
        if (!$type || !$dir) {
            return null;
        }

        $subDir = in_array($type, ['module', 'omekamodule'])
            ? 'modules' : 'themes';
        $iniFile = in_array($type, ['module', 'omekamodule'])
            ? 'config/module.ini' : 'config/theme.ini';

        $path = OMEKA_PATH . '/' . $subDir . '/' . $dir
            . '/' . $iniFile;

        // Fallback to composer-addons.
        if (!file_exists($path)) {
            $path = OMEKA_PATH . '/composer-addons/' . $subDir
                . '/' . $dir . '/' . $iniFile;
        }

        if (!file_exists($path)) {
            return null;
        }

        $ini = parse_ini_file($path);
        return $ini['version'] ?? null;
    }

    /**
     * Enrich the addon list with local state information.
     *
     * Fills installed_version, installed, is_composer, and
     * update_available for each addon.
     */
    public function enrichWithLocalState(array &$addons): void
    {
        foreach ($addons as $type => &$addonsForType) {
            foreach ($addonsForType as $url => &$addon) {
                $addon['installed'] = $this->dirExists($addon);
                $addon['is_composer'] = $this->isComposerManaged(
                    $addon
                );
                $addon['installed_version'] = $addon['installed']
                    ? $this->getInstalledVersion($addon)
                    : null;
                $addon['update_available'] = $addon['installed']
                    && $addon['installed_version']
                    && $addon['version']
                    && version_compare(
                        $addon['installed_version'],
                        $addon['version'],
                        '<'
                    );

                // For modules, get the state from ModuleManager.
                if ($addon['installed']
                    && $this->moduleManager
                    && in_array($type, ['module', 'omekamodule'])
                ) {
                    $module = $this->moduleManager->getModule(
                        $addon['dir']
                    );
                    $addon['state'] = $module
                        ? $module->getState() : null;
                } else {
                    $addon['state'] = null;
                }
            }
        }
        unset($addon, $addonsForType);
    }

    /**
     * Update an addon: download new version with backup/rollback.
     *
     * @return bool True on success.
     */
    public function updateAddon(array $addon): bool
    {
        if ($this->isComposerManaged($addon)) {
            $this->messenger->addError(new PsrMessage(
                'The addon "{name}" is managed by Composer and cannot be updated here.', // @translate
                ['name' => $addon['name']]
            ));
            return false;
        }

        $type = $addon['type'] ?? '';
        $subDir = in_array($type, ['module', 'omekamodule'])
            ? 'modules' : 'themes';
        $destination = OMEKA_PATH . '/' . $subDir;
        $addonDir = $destination . '/' . $addon['dir'];

        if (!is_writeable($destination)) {
            $this->messenger->addError(new PsrMessage(
                'The {type} directory is not writeable by the server.', // @translate
                ['type' => $subDir]
            ));
            return false;
        }

        if (!file_exists($addonDir)) {
            $this->messenger->addError(new PsrMessage(
                'The addon "{name}" is not installed.', // @translate
                ['name' => $addon['name']]
            ));
            return false;
        }

        $tempDir = sys_get_temp_dir() . '/easyadmin_update_'
            . $addon['dir'] . '_' . time();
        @mkdir($tempDir, 0775, true);

        // Download new version.
        $zipFile = $tempDir . '/' . basename($addon['zip']);
        $result = $this->downloadFile($addon['zip'], $zipFile);
        if (!$result) {
            $this->messenger->addError(new PsrMessage(
                'Unable to fetch the update for'
                    . ' "{name}".', // @translate
                ['name' => $addon['name']]
            ));
            $this->rmDir($tempDir);
            return false;
        }

        // Unzip into temp.
        $result = $this->unzipFile($zipFile, $tempDir);
        @unlink($zipFile);
        if (!$result) {
            $this->messenger->addError(new PsrMessage(
                'An error occurred during the unzipping of "{name}".', // @translate
                ['name' => $addon['name']]
            ));
            $this->rmDir($tempDir);
            return false;
        }

        // Backup current directory.
        $timestamp = date('Ymd_His');
        $backupDir = $addonDir . '.bak-' . $timestamp;
        $renamed = @rename($addonDir, $backupDir);
        if (!$renamed) {
            $this->messenger->addError(new PsrMessage(
                'Unable to backup the current version of'
                    . ' "{name}".', // @translate
                ['name' => $addon['name']]
            ));
            $this->rmDir($tempDir);
            return false;
        }

        // Move the new addon from temp using existing logic.
        // moveAddon expects the addon to be extracted into
        // $destination (like installAddon), so we move from temp
        // to destination first.
        $extractedDirs = array_diff(
            scandir($tempDir) ?: [],
            ['.', '..']
        );
        $moved = false;
        foreach ($extractedDirs as $extractedDir) {
            $extractedPath = $tempDir . '/' . $extractedDir;
            if (is_dir($extractedPath)) {
                $moved = @rename($extractedPath, $addonDir);
                if (!$moved) {
                    // Try to find the right dir via moveAddon
                    // pattern by moving all dirs to destination.
                    @rename(
                        $extractedPath,
                        $destination . '/' . $extractedDir
                    );
                    $moved = $this->moveAddon($addon);
                }
                break;
            }
        }

        $this->rmDir($tempDir);

        if (!$moved || !file_exists($addonDir)) {
            // Rollback: restore from backup.
            @rename($backupDir, $addonDir);
            $this->messenger->addError(new PsrMessage(
                'Failed to install the update for "{name}".'
                    . ' The previous version has been'
                    . ' restored.', // @translate
                ['name' => $addon['name']]
            ));
            return false;
        }

        // Generate checksums for the new version.
        $this->generateChecksums($addon);

        $this->messenger->addSuccess(new PsrMessage(
            'The addon "{name}" was successfully updated.'
                . ' The backup is stored in'
                . ' "{backup}".', // @translate
            ['name' => $addon['name'], 'backup' => basename($backupDir)]
        ));

        return true;
    }

    /**
     * Remove an addon: uninstall from DB and delete files.
     *
     * @return bool True on success.
     */
    public function removeAddon(array $addon): bool
    {
        if ($this->isComposerManaged($addon)) {
            $this->messenger->addError(new PsrMessage(
                'The addon "{name}" is managed by Composer and cannot be removed here.', // @translate
                ['name' => $addon['name']]
            ));
            return false;
        }

        $type = $addon['type'] ?? '';
        $subDir = in_array($type, ['module', 'omekamodule'])
            ? 'modules' : 'themes';
        $destination = OMEKA_PATH . '/' . $subDir;
        $addonDir = $destination . '/' . $addon['dir'];

        if (!is_writeable($destination)) {
            $this->messenger->addError(new PsrMessage(
                'The {type} directory is not writeable by the server.', // @translate
                ['type' => $subDir]
            ));
            return false;
        }

        if (!file_exists($addonDir)) {
            $this->messenger->addError(new PsrMessage(
                'The addon "{name}" is not installed.', // @translate
                ['name' => $addon['name']]
            ));
            return false;
        }

        // For modules: uninstall from DB via ModuleManager.
        if (in_array($type, ['module', 'omekamodule'])
            && $this->moduleManager
        ) {
            $module = $this->moduleManager->getModule(
                $addon['dir']
            );
            if ($module) {
                $state = $module->getState();
                try {
                    if ($state === ModuleManager::STATE_ACTIVE) {
                        $this->moduleManager->deactivate($module);
                    }
                    if (in_array($state, [
                        ModuleManager::STATE_ACTIVE,
                        ModuleManager::STATE_NOT_ACTIVE,
                    ])) {
                        $this->moduleManager->uninstall($module);
                    }
                } catch (Exception $e) {
                    $this->messenger->addError(new PsrMessage(
                        'Error during uninstall of "{name}": {error}', // @translate
                        [
                            'name' => $addon['name'],
                            'error' => $e->getMessage(),
                        ]
                    ));
                    return false;
                }
            }
        }

        // For themes: check no site uses it as active theme.
        if (in_array($type, ['theme', 'omekatheme'])) {
            $sites = $this->api->search('sites', [
                'limit' => 0,
            ])->getContent();
            foreach ($sites as $site) {
                $siteTheme = $site->theme();
                if ($siteTheme === $addon['dir']) {
                    $this->messenger->addError(new PsrMessage(
                        'The theme "{name}" is used by site "{site}" and cannot be removed.', // @translate
                        [
                            'name' => $addon['name'],
                            'site' => $site->title(),
                        ]
                    ));
                    return false;
                }
            }
        }

        $result = $this->rmDir($addonDir);
        if (!$result) {
            $this->messenger->addError(new PsrMessage(
                'Unable to remove the directory of'
                    . ' "{name}".', // @translate
                ['name' => $addon['name']]
            ));
            return false;
        }

        // Remove checksums file if exists.
        $checksumsDir = OMEKA_PATH
            . '/files/check/addon-checksums';
        $checksumsFile = $checksumsDir . '/'
            . $addon['dir'] . '.json';
        if (file_exists($checksumsFile)) {
            @unlink($checksumsFile);
        }

        $this->messenger->addSuccess(new PsrMessage(
            'The addon "{name}" was successfully'
                . ' removed.', // @translate
            ['name' => $addon['name']]
        ));

        return true;
    }

    /**
     * Generate SHA-256 checksums for an addon and store as JSON.
     */
    public function generateChecksums(array $addon): bool
    {
        $type = $addon['type'] ?? '';
        $dir = $addon['dir'] ?? '';
        if (!$type || !$dir) {
            return false;
        }

        $subDir = in_array($type, ['module', 'omekamodule'])
            ? 'modules' : 'themes';
        $addonDir = OMEKA_PATH . '/' . $subDir . '/' . $dir;

        if (!file_exists($addonDir) || !is_dir($addonDir)) {
            return false;
        }

        $checksums = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $addonDir,
                \RecursiveDirectoryIterator::SKIP_DOTS
            )
        );
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $relativePath = ltrim(
                    str_replace($addonDir, '', $file->getPathname()),
                    '/'
                );
                // Skip vendor/ and node_modules/.
                if (strpos($relativePath, 'vendor/') === 0
                    || strpos($relativePath, 'node_modules/') === 0
                ) {
                    continue;
                }
                $checksums[$relativePath] = hash_file(
                    'sha256',
                    $file->getPathname()
                );
            }
        }

        ksort($checksums);

        $checksumsDir = OMEKA_PATH
            . '/files/check/addon-checksums';
        if (!file_exists($checksumsDir)) {
            @mkdir($checksumsDir, 0775, true);
        }

        $version = $this->getInstalledVersion($addon);
        $data = [
            'addon' => $dir,
            'version' => $version,
            'generated' => date('c'),
            'algorithm' => 'sha256',
            'files' => $checksums,
        ];

        $result = file_put_contents(
            $checksumsDir . '/' . $dir . '.json',
            json_encode($data, JSON_PRETTY_PRINT
                | JSON_UNESCAPED_SLASHES)
        );

        return $result !== false;
    }

    /**
     * Check integrity of an addon by comparing checksums.
     *
     * @param bool $fromSource Re-download zip to generate
     *   reference checksums.
     * @return array With keys status, modified, added, deleted.
     */
    public function checkIntegrity(
        array $addon,
        bool $fromSource = false
    ): array {
        $result = [
            'status' => 'unknown',
            'modified' => [],
            'added' => [],
            'deleted' => [],
        ];

        $type = $addon['type'] ?? '';
        $dir = $addon['dir'] ?? '';
        if (!$type || !$dir) {
            return $result;
        }

        $subDir = in_array($type, ['module', 'omekamodule'])
            ? 'modules' : 'themes';
        $addonDir = OMEKA_PATH . '/' . $subDir . '/' . $dir;

        if (!file_exists($addonDir)) {
            return $result;
        }

        if ($fromSource) {
            $referenceChecksums = $this
                ->generateChecksumsFromSource($addon);
        } else {
            $checksumsFile = OMEKA_PATH
                . '/files/check/addon-checksums/'
                . $dir . '.json';
            if (!file_exists($checksumsFile)) {
                return $result;
            }
            $json = json_decode(
                file_get_contents($checksumsFile),
                true
            );
            $referenceChecksums = $json['files'] ?? [];
        }

        if (!$referenceChecksums) {
            return $result;
        }

        // Compute current checksums.
        $currentChecksums = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $addonDir,
                \RecursiveDirectoryIterator::SKIP_DOTS
            )
        );
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $relativePath = ltrim(
                    str_replace(
                        $addonDir,
                        '',
                        $file->getPathname()
                    ),
                    '/'
                );
                if (strpos($relativePath, 'vendor/') === 0
                    || strpos(
                        $relativePath,
                        'node_modules/'
                    ) === 0
                ) {
                    continue;
                }
                $currentChecksums[$relativePath] = hash_file(
                    'sha256',
                    $file->getPathname()
                );
            }
        }

        // Compare.
        foreach ($referenceChecksums as $path => $hash) {
            if (!isset($currentChecksums[$path])) {
                $result['deleted'][] = $path;
            } elseif ($currentChecksums[$path] !== $hash) {
                $result['modified'][] = $path;
            }
        }
        foreach ($currentChecksums as $path => $hash) {
            if (!isset($referenceChecksums[$path])) {
                $result['added'][] = $path;
            }
        }

        $result['status'] = ($result['modified']
            || $result['added']
            || $result['deleted'])
            ? 'modified' : 'clean';

        return $result;
    }

    /**
     * Download the source zip and compute reference checksums.
     */
    protected function generateChecksumsFromSource(
        array $addon
    ): array {
        $tempDir = sys_get_temp_dir() . '/easyadmin_check_'
            . ($addon['dir'] ?? 'unknown') . '_' . time();
        @mkdir($tempDir, 0775, true);

        $zipFile = $tempDir . '/' . basename($addon['zip'] ?? '');
        if (!$this->downloadFile(
            $addon['zip'] ?? '',
            $zipFile
        )) {
            $this->rmDir($tempDir);
            return [];
        }

        if (!$this->unzipFile($zipFile, $tempDir)) {
            $this->rmDir($tempDir);
            return [];
        }
        @unlink($zipFile);

        // Find the extracted directory.
        $dirs = array_filter(
            array_diff(scandir($tempDir) ?: [], ['.', '..']),
            fn ($f) => is_dir($tempDir . '/' . $f)
        );
        $extractedDir = $tempDir . '/' . reset($dirs);
        if (!$extractedDir || !is_dir($extractedDir)) {
            $this->rmDir($tempDir);
            return [];
        }

        $checksums = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $extractedDir,
                \RecursiveDirectoryIterator::SKIP_DOTS
            )
        );
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $relativePath = ltrim(
                    str_replace(
                        $extractedDir,
                        '',
                        $file->getPathname()
                    ),
                    '/'
                );
                if (strpos($relativePath, 'vendor/') === 0
                    || strpos(
                        $relativePath,
                        'node_modules/'
                    ) === 0
                ) {
                    continue;
                }
                $checksums[$relativePath] = hash_file(
                    'sha256',
                    $file->getPathname()
                );
            }
        }

        $this->rmDir($tempDir);
        ksort($checksums);
        return $checksums;
    }

    protected function initAddons(bool $refresh = false): self
    {
        // Build the list of addons only once.
        if (!$refresh && !$this->isEmpty()) {
            return $this;
        }

        // Check the cache.
        $container = new Container('EasyAdmin');
        if (!$refresh && isset($container->addons)) {
            $this->addons = $container->addons;
            if (!$this->isEmpty()) {
                return $this;
            }
        }

        $this->addons = [];
        foreach ($this->types() as $addonType) {
            $this->addons[$addonType] = $this->listAddonsForType($addonType);
        }

        $container->addons = $this->addons;
        $container
            ->setExpirationSeconds($this->expirationSeconds)
            ->setExpirationHops($this->expirationHops);

        return $this;
    }

    /**
     * Helper to list the addons from a web page.
     *
     * @param string $type
     */
    protected function listAddonsForType($type): array
    {
        if (!isset($this->data[$type]['source'])) {
            return [];
        }
        $source = $this->data[$type]['source'];

        $content = $this->fileGetContents($source);
        if (empty($content)) {
            return [];
        }

        switch ($type) {
            case 'module':
            case 'theme':
                return $this->extractAddonList($content, $type);
            case 'omekamodule':
            case 'omekatheme':
                return $this->extractAddonListFromOmeka($content, $type);
        }
    }

    /**
     * Helper to get content from an external url.
     *
     * @param string $url
     */
    protected function fileGetContents($url): ?string
    {
        $uri = new HttpUri($url);
        $this->httpClient->reset();
        $this->httpClient->setUri($uri);
        try {
            $response = $this->httpClient->send();
            $response = $response->isOk() ? $response->getBody() : null;
        } catch (RuntimeException $e) {
            $response = null;
        }

        if (empty($response)) {
            $this->messenger->addError(new PsrMessage(
                'Unable to fetch the url {url}.', // @translate
                ['url' => $url]
            ));
        }

        return $response;
    }

    /**
     * Helper to parse a csv file to get urls and names of addons.
     *
     * @param string $csv
     * @param string $type
     */
    protected function extractAddonList($csv, $type): array
    {
        $list = [];

        $addons = array_map('str_getcsv', explode(PHP_EOL, $csv));
        $headers = array_flip($addons[0]);

        foreach ($addons as $key => $row) {
            if ($key == 0 || empty($row) || !isset($row[$headers['Url']])) {
                continue;
            }

            $url = $row[$headers['Url']];
            $name = $row[$headers['Name']];
            $version = $row[$headers['Last version']];
            $addonName = preg_replace('~[^A-Za-z0-9]~', '', $name);
            $dirname = $row[$headers['Directory name']] ?: $addonName;
            $server = strtolower(parse_url($url, PHP_URL_HOST));
            $dependencies = empty($headers['Dependencies']) || empty($row[$headers['Dependencies']])
                ? []
                : array_filter(array_map('trim', explode(',', $row[$headers['Dependencies']])));

            $zip = $row[$headers['Last released zip']];
            // Warning: the url with master may not have dependencies.
            if (!$zip) {
                switch ($server) {
                    case 'github.com':
                        $zip = $url . '/archive/master.zip';
                        break;
                    case 'gitlab.com':
                        $zip = $url . '/repository/archive.zip';
                        break;
                    default:
                        $zip = $url . '/master.zip';
                        break;
                }
            }

            $addon = [];
            $addon['type'] = $type;
            $addon['server'] = $server;
            $addon['name'] = $name;
            $addon['basename'] = basename($url);
            $addon['dir'] = $dirname;
            $addon['version'] = $version;
            $addon['url'] = $url;
            $addon['zip'] = $zip;
            $addon['dependencies'] = $dependencies;

            $list[$url] = $addon;
        }

        return $list;
    }

    /**
     * Helper to parse html to get urls and names of addons.
     *
     * @todo Manage dependencies for addon from omeka.org.
     *
     * @param string $json
     * @param string $type
     */
    protected function extractAddonListFromOmeka($json, $type): array
    {
        $list = [];

        $addonsList = json_decode($json, true);
        if (!$addonsList) {
            return [];
        }

        foreach ($addonsList as $name => $data) {
            if (!$data) {
                continue;
            }

            $version = $data['latest_version'];
            $url = 'https://github.com/' . $data['owner'] . '/' . $data['repo'];
            // Warning: the url with master may not have dependencies.
            $zip = $data['versions'][$version]['download_url'] ?? $url . '/archive/master.zip';

            $addon = [];
            $addon['type'] = strtr($type, ['omeka' => '']);
            $addon['server'] = 'omeka.org';
            $addon['name'] = $name;
            $addon['basename'] = $data['dirname'];
            $addon['dir'] = $data['dirname'];
            $addon['version'] = $data['latest_version'];
            $addon['url'] = $url;
            $addon['zip'] = $zip;
            $addon['dependencies'] = [];

            $list[$url] = $addon;
        }

        return $list;
    }

    /**
     * Helper to install an addon.
     */
    public function installAddon(array $addon): bool
    {
        switch ($addon['type']) {
            case 'module':
                $destination = OMEKA_PATH . '/modules';
                $type = 'module';
                break;
            case 'theme':
                $destination = OMEKA_PATH . '/themes';
                $type = 'theme';
                break;
            default:
                return false;
        }

        $missingDependencies = [];
        if (!empty($addon['dependencies'])) {
            foreach ($addon['dependencies'] as $dependency) {
                $module = $this->getModule($dependency);
                if (empty($module)
                    || (
                        $dependency !== 'Generic'
                            && $module->getJsonLd()['o:state'] !== \Omeka\Module\Manager::STATE_ACTIVE
                    )
                ) {
                    $missingDependencies[] = $dependency;
                }
            }
        }
        if ($missingDependencies) {
            $this->messenger->addError(new PsrMessage(
                'The module "{module}" requires the dependencies "{names}" installed and enabled first.', // @translate
                ['module' => $addon['name'], 'names' => implode('", "', $missingDependencies)]
            ));
            return false;
        }

        $isWriteableDestination = is_writeable($destination);
        if (!$isWriteableDestination) {
            $this->messenger->addError(new PsrMessage(
                'The {type} directory is not writeable by the server.', // @translate
                ['type' => $type]
            ));
            return false;
        }
        // Add a message for security hole.
        $this->messenger->addWarning(new PsrMessage(
            'Don’t forget to protect the {type} directory from writing after installation.', // @translate
            ['type' => $type]
        ));

        // Local zip file path.
        $zipFile = $destination . DIRECTORY_SEPARATOR . basename($addon['zip']);
        if (file_exists($zipFile)) {
            $result = @unlink($zipFile);
            if (!$result) {
                $this->messenger->addError(new PsrMessage(
                    'A zipfile exists with the same name in the {type} directory and cannot be removed.', // @translate
                    ['type' => $type]
                ));
                return false;
            }
        }

        if (file_exists($destination . DIRECTORY_SEPARATOR . $addon['dir'])) {
            $this->messenger->addError(new PsrMessage(
                'The {type} directory "{name}" already exists.', // @translate
                ['type' => $type, 'name' => $addon['dir']]
            ));
            return false;
        }

        // Get the zip file from server.
        $result = $this->downloadFile($addon['zip'], $zipFile);
        if (!$result) {
            $this->messenger->addError(new PsrMessage(
                'Unable to fetch the {type} "{name}".', // @translate
                ['type' => $type, 'name' => $addon['name']]
            ));
            return false;
        }

        // Unzip downloaded file.
        $result = $this->unzipFile($zipFile, $destination);

        unlink($zipFile);

        if (!$result) {
            $this->messenger->addError(new PsrMessage(
                'An error occurred during the unzipping of the {type} "{name}".', // @translate
                ['type' => $type, 'name' => $addon['name']]
            ));
            return false;
        }

        // Move the addon to its destination.
        $result = $this->moveAddon($addon);

        // Check the special case of dependency Generic to avoid a fatal error.
        // This is used only for modules downloaded from omeka.org, since the
        // dependencies are not available here.
        // TODO Get the dependencies for the modules on omeka.org.
        if ($type === 'module') {
            $moduleFile = $destination . DIRECTORY_SEPARATOR . $addon['dir'] . DIRECTORY_SEPARATOR . 'Module.php';
            if (file_exists($moduleFile) && filesize($moduleFile)) {
                $modulePhp = file_get_contents($moduleFile);
                if (strpos($modulePhp, 'use Generic\AbstractModule;') !== false) {
                    /** @var \Omeka\Api\Representation\ModuleRepresentation $module */
                    $module = $this->getModule('Generic');
                    if (empty($module)
                        || version_compare($module->getJsonLd()['o:ini']['version'] ?? '', '3.4.47', '<')
                    ) {
                        $this->messenger->addError(new PsrMessage(
                            'The module "{name}" requires the dependency "Generic" version "{version}" available first.', // @translate
                            ['name' => $addon['name'], 'version' => '3.4.47']
                        ));
                        // Remove the folder to avoid a fatal error (Generic is a
                        // required abstract class).
                        $this->rmDir($destination . DIRECTORY_SEPARATOR . $addon['dir']);
                        return false;
                    }
                }
            }
        }

        $message = new PsrMessage(
            'If "{name}" doesn’t appear in the list of {type}, its directory may need to be renamed.', // @translate
            ['name' => $addon['name'], 'type' => InflectorFactory::create()->build()->pluralize($type)]
        );
        $this->messenger->add(
            $result ? Messenger::NOTICE : Messenger::WARNING,
            $message
        );
        $this->messenger->addSuccess(new PsrMessage(
            '{type} uploaded successfully', // @translate
            ['type' => ucfirst($type)]
        ));

        $this->messenger->addNotice(new PsrMessage(
            'It is always recommended to read the original readme or help of the addon.' // @translate
        ));

        // Generate checksums for integrity checking.
        $this->generateChecksums($addon);

        return true;
    }

    /**
     * Get a module by its name.
     *
     * @todo Modules cannot be api read or fetch one by one by the api (core issue).
     */
    protected function getModule(string $module): ?ModuleRepresentation
    {
        /** @var \Omeka\Api\Representation\ModuleRepresentation[] $modules */
        $modules = $this->api->search('modules', ['id' => $module])->getContent();
        return $modules[$module] ?? null;
    }

    /**
     * Helper to download a file.
     *
     * @param string $source
     * @param string $destination
     * @return bool
     */
    protected function downloadFile($source, $destination): bool
    {
        // Only allow http/https to prevent local file reads via file://, php://, etc.
        $scheme = parse_url($source, PHP_URL_SCHEME);
        if (!in_array($scheme, ['http', 'https'])) {
            return false;
        }

        // Restrict to trusted hosts to prevent SSRF.
        $host = strtolower((string) parse_url($source, PHP_URL_HOST));
        $trustedHosts = [
            'github.com',
            'gitlab.com',
            'omeka.org',
            'api.github.com',
            'codeload.github.com',
        ];
        $isTrusted = false;
        foreach ($trustedHosts as $trusted) {
            if ($host === $trusted || str_ends_with($host, '.' . $trusted)) {
                $isTrusted = true;
                break;
            }
        }
        if (!$isTrusted) {
            return false;
        }

        // Limit download size to 200 MB to prevent disk exhaustion.
        $maxSize = 200 * 1024 * 1024;

        $handle = @fopen($source, 'rb');
        if (empty($handle)) {
            return false;
        }

        $destHandle = @fopen($destination, 'wb');
        if (!$destHandle) {
            @fclose($handle);
            return false;
        }

        $written = 0;
        while (!feof($handle)) {
            $chunk = @fread($handle, 8192);
            if ($chunk === false) {
                break;
            }
            $written += strlen($chunk);
            if ($written > $maxSize) {
                @fclose($handle);
                @fclose($destHandle);
                @unlink($destination);
                return false;
            }
            fwrite($destHandle, $chunk);
        }

        @fclose($handle);
        @fclose($destHandle);

        return $written > 0;
    }

    /**
     * Helper to unzip a file.
     *
     * @param string $source A local file.
     * @param string $destination A writeable dir.
     * @return bool
     */
    protected function unzipFile($source, $destination): bool
    {
        // Unzip via php-zip.
        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive;
            $result = $zip->open($source);
            if ($result === true) {
                // Validate entries to prevent zip-slip (path traversal).
                $realDestination = realpath($destination) ?: $destination;
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $entryName = $zip->getNameIndex($i);
                    if ($entryName === false
                        || strpos($entryName, '..') !== false
                        || strpos($entryName, '/') === 0
                        || strpos($entryName, '\\') === 0
                        || preg_match('/^[a-zA-Z]:/', $entryName)
                    ) {
                        $zip->close();
                        return false;
                    }
                }
                $result = $zip->extractTo($destination);
                $zip->close();
            } else {
                /*
                $zipErrors = [
                    ZipArchive::ER_EXISTS => 'File already exists',
                    ZipArchive::ER_INCONS => 'Zip archive inconsistent',
                    ZipArchive::ER_INVAL => 'Invalid argument',
                    ZipArchive::ER_MEMORY => 'Malloc failure',
                    ZipArchive::ER_NOENT => 'No such file',
                    ZipArchive::ER_NOZIP => 'Not a zip archive',
                    ZipArchive::ER_OPEN => 'Can’t open file',
                    ZipArchive::ER_READ => 'Read error',
                    ZipArchive::ER_SEEK => 'Seek error',
                ];
                $this->logger->err(
                    'Error when unzipping: {msg}', // @translate
                    ['msg' => $zipErrors[$result] ?? 'Other zip error']
                );
                */
                $result = false;
            }
        }

        // Unzip via command line
        else {
            // Check if the zip command exists.
            try {
                $status = $output = $errors = null;
                $this->executeCommand('unzip', $status, $output, $errors);
            } catch (Exception $e) {
                $status = 1;
            }
            // A return value of 0 indicates the convert binary is working correctly.
            $result = $status == 0;
            if ($result) {
                $command = 'unzip ' . escapeshellarg($source) . ' -d ' . escapeshellarg($destination);
                try {
                    $this->executeCommand($command, $status, $output, $errors);
                } catch (Exception $e) {
                    $status = 1;
                }
                $result = $status == 0;
            }
        }

        return $result;
    }

    /**
     * Helper to rename the directory of an addon.
     *
     * The name of the directory is unknown, because it is a subfolder inside
     * the zip file, and the name of the module may be different from the name
     * of the directory.
     * @todo Get the directory name from the zip.
     *
     * @param string $addon
     * @return bool
     */
    protected function moveAddon($addon): bool
    {
        switch ($addon['type']) {
            case 'module':
                $destination = OMEKA_PATH . '/modules';
                break;
            case 'theme':
                $destination = OMEKA_PATH . '/themes';
                break;
            default:
                return false;
        }

        // Allows to manage case like AddItemLink, where the project name on
        // github is only "AddItem".
        $loop = [$addon['dir']];
        if ($addon['basename'] != $addon['dir']) {
            $loop[] = $addon['basename'];
        }

        // Manage only the most common cases.
        // @todo Use a scan dir + a regex.
        $checks = [
            ['', ''],
            ['', '-master'],
            ['', '-module-master'],
            ['', '-theme-master'],
            ['omeka-', '-master'],
            ['omeka-s-', '-master'],
            ['omeka-S-', '-master'],
            ['module-', '-master'],
            ['module_', '-master'],
            ['omeka-module-', '-master'],
            ['omeka-s-module-', '-master'],
            ['omeka-S-module-', '-master'],
            ['theme-', '-master'],
            ['theme_', '-master'],
            ['omeka-theme-', '-master'],
            ['omeka-s-theme-', '-master'],
            ['omeka-S-theme-', '-master'],
            ['omeka_', '-master'],
            ['omeka_s_', '-master'],
            ['omeka_S_', '-master'],
            ['omeka_module_', '-master'],
            ['omeka_s_module_', '-master'],
            ['omeka_S_module_', '-master'],
            ['omeka_theme_', '-master'],
            ['omeka_s_theme_', '-master'],
            ['omeka_S_theme_', '-master'],
            ['omeka_Module_', '-master'],
            ['omeka_s_Module_', '-master'],
            ['omeka_S_Module_', '-master'],
            ['omeka_Theme_', '-master'],
            ['omeka_s_Theme_', '-master'],
            ['omeka_S_Theme_', '-master'],
        ];

        $source = '';
        foreach ($loop as $addonName) {
            foreach ($checks as $check) {
                $sourceCheck = $destination . DIRECTORY_SEPARATOR
                    . $check[0] . $addonName . $check[1];
                if (file_exists($sourceCheck)) {
                    $source = $sourceCheck;
                    break 2;
                }
                // Allows to manage case like name is "Ead", not "EAD".
                $sourceCheck = $destination . DIRECTORY_SEPARATOR
                    . $check[0] . ucfirst(strtolower($addonName)) . $check[1];
                if (file_exists($sourceCheck)) {
                    $source = $sourceCheck;
                    $addonName = ucfirst(strtolower($addonName));
                    break 2;
                }
                if ($check[0]) {
                    $sourceCheck = $destination . DIRECTORY_SEPARATOR
                        . ucfirst($check[0]) . $addonName . $check[1];
                    if (file_exists($sourceCheck)) {
                        $source = $sourceCheck;
                        break 2;
                    }
                    $sourceCheck = $destination . DIRECTORY_SEPARATOR
                        . ucfirst($check[0]) . ucfirst(strtolower($addonName)) . $check[1];
                    if (file_exists($sourceCheck)) {
                        $source = $sourceCheck;
                        $addonName = ucfirst(strtolower($addonName));
                        break 2;
                    }
                }
            }
        }

        if ($source === '') {
            return false;
        }

        $path = $destination . DIRECTORY_SEPARATOR . $addon['dir'];
        if ($source === $path) {
            return true;
        }

        return rename($source, $path);
    }

    /**
     * List directories in a directory, not recursively.
     *
     * @param string $dir
     */
    protected function listDirsInDir($dir): array
    {
        static $dirs;

        if (isset($dirs[$dir])) {
            return $dirs[$dir];
        }

        if (empty($dir) || !file_exists($dir) || !is_dir($dir) || !is_readable($dir)) {
            return [];
        }

        $list = array_filter(array_diff(scandir($dir), ['.', '..']), fn ($file) => is_dir($dir . DIRECTORY_SEPARATOR . $file));

        $dirs[$dir] = $list;
        return $dirs[$dir];
    }

    /**
     * Execute a shell command without exec().
     *
     * @see \Omeka\Stdlib\Cli::send()
     *
     * @param string $command
     * @param int $status
     * @param string $output
     * @param array $errors
     * @throws \Exception
     */
    protected function executeCommand($command, &$status, &$output, &$errors): void
    {
        // Using proc_open() instead of exec() solves a problem where exec('convert')
        // fails with a "Permission Denied" error because the current working
        // directory cannot be set properly via exec().  Note that exec() works
        // fine when executing in the web environment but fails in CLI.
        $descriptorSpec = [
            0 => ['pipe', 'r'], //STDIN
            1 => ['pipe', 'w'], //STDOUT
            2 => ['pipe', 'w'], //STDERR
        ];
        $pipes = [];
        if ($proc = proc_open($command, $descriptorSpec, $pipes, getcwd())) {
            $output = stream_get_contents($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            $status = proc_close($proc);
        } else {
            throw new Exception((string) new PsrMessage(
                'Failed to execute command: {command}', // @translate
                ['command' => $command]
            ));
        }
    }

    /**
     * Remove a dir from filesystem.
     *
     * @param string $dirpath Absolute path.
     */
    protected function rmDir(string $dirPath): bool
    {
        if (!file_exists($dirPath)) {
            return true;
        }
        $real = realpath($dirPath);
        if ($real === false
            || $real === '/'
            || strpos($real, '/..') !== false
        ) {
            return false;
        }
        $dirPath = $real;
        $files = array_diff(scandir($dirPath) ?: [], ['.', '..']);
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
