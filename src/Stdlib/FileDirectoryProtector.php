<?php declare(strict_types=1);

namespace EasyAdmin\Stdlib;

use Doctrine\DBAL\Connection;

/**
 * Protect the sensitive sub-directories of the files directory with a
 * ".htaccess" that denies any direct web access (backups, imports, exports,
 * logs, contributions, user data…). Public media and derivative directories are
 * never touched and an existing ".htaccess" is never overwritten.
 *
 * The owner module of a directory is resolved from the settings that point to
 * it (a setting whose value is a path, mapped to the module of its key), then
 * from a curated list, so the report can tell where a directory comes from.
 */
class FileDirectoryProtector
{
    const HTACCESS_MARKER = 'Managed by Omeka S module EasyAdmin';

    protected string $basePath;

    /**
     * @var array<string, string> Directory name (lower case) => module name.
     */
    protected array $owners;

    /**
     * @var \Common\Stdlib\DirectoryManager
     */
    protected $directoryManager;

    /**
     * @param array<string, string> $owners Directory (lower case) => module.
     * @param \Common\Stdlib\DirectoryManager $directoryManager Provides the
     * shared decisions (sensitive/public directory, .htaccess content), so this
     * class only adds the owner mapping and the report on top of Common.
     */
    public function __construct(string $basePath, array $owners = [], $directoryManager = null)
    {
        $this->basePath = rtrim($basePath, '/');
        $this->owners = $owners;
        $this->directoryManager = $directoryManager;
    }

    public function basePath(): string
    {
        return $this->basePath;
    }

    /**
     * The content of the protective ".htaccess" (Apache 2.2 and 2.4).
     */
    public function htaccessContent(): string
    {
        return $this->directoryManager->denyHtaccessContent();
    }

    /**
     * Directories serving public media or derivatives, never to be protected.
     */
    public function isPublicDir(string $name): bool
    {
        return $this->directoryManager->isPublicDir($name);
    }

    /**
     * Directories holding server side or sensitive data, to protect from a
     * direct web access.
     */
    public function isSensitiveDir(string $name): bool
    {
        return $this->directoryManager->isSensitiveDir($name);
    }

    /**
     * The module owning a directory, or null when it is unknown.
     */
    public function owner(string $name): ?string
    {
        return $this->owners[strtolower($name)] ?? $this->curatedOwner($name);
    }

    /**
     * Create a protective ".htaccess" in each existing sensitive directory.
     *
     * @return array{created: array<string, ?string>, existing: array<string, ?string>, failed: array<string, ?string>}
     *   Each list maps a directory name to its owner module.
     */
    public function protectSensitiveDirectories(): array
    {
        $result = ['created' => [], 'existing' => [], 'failed' => []];
        if (!is_dir($this->basePath)) {
            return $result;
        }
        $content = $this->htaccessContent();
        foreach (new \DirectoryIterator($this->basePath) as $dir) {
            if (!$dir->isDir() || $dir->isDot()) {
                continue;
            }
            $name = $dir->getFilename();
            if ($this->isPublicDir($name) || !$this->isSensitiveDir($name)) {
                continue;
            }
            $htaccess = $dir->getPathname() . '/.htaccess';
            $owner = $this->owner($name);
            if (file_exists($htaccess)) {
                $result['existing'][$name] = $owner;
            } elseif (@file_put_contents($htaccess, $content) === false) {
                $result['failed'][$name] = $owner;
            } else {
                $result['created'][$name] = $owner;
            }
        }
        return $result;
    }

    /**
     * Audit the files directory: sensitive directories left unprotected (with
     * their owner), other directories without a ".htaccess" to review, and
     * executable php files (code execution risk).
     *
     * @return array{sensitive: array<string, ?string>, unknown: string[], php: string[]}
     */
    public function audit(): array
    {
        $audit = ['sensitive' => [], 'unknown' => [], 'php' => []];
        if (!is_dir($this->basePath)) {
            return $audit;
        }
        foreach (new \DirectoryIterator($this->basePath) as $entry) {
            if ($entry->isDot()) {
                continue;
            }
            $name = $entry->getFilename();
            if ($entry->isFile() && strtolower($entry->getExtension()) === 'php') {
                $audit['php'][] = $name;
                continue;
            }
            if (!$entry->isDir() || $this->isPublicDir($name)) {
                continue;
            }
            if (file_exists($entry->getPathname() . '/.htaccess')) {
                continue;
            }
            if ($this->isSensitiveDir($name)) {
                $audit['sensitive'][$name] = $this->owner($name);
            } else {
                $audit['unknown'][] = $name;
            }
        }
        return $audit;
    }

    /**
     * Owner of a directory from a curated list, when no setting points to it.
     */
    protected function curatedOwner(string $name): ?string
    {
        $map = [
            '/contribution/i' => 'Contribute',
            '/^contactus$/i' => 'ContactUs',
            '/^bulk_export$/i' => 'BulkExport',
            '/^bulk_import$/i' => 'BulkImport',
            '/(^logs?$)/i' => 'Log',
            '/triplestore/i' => 'Sparql',
            '/(backup|bkp|^sql|userdata|meminfo|^preload$|^_?imports?$)/i' => 'EasyAdmin',
        ];
        foreach ($map as $pattern => $module) {
            if (preg_match($pattern, $name)) {
                return $module;
            }
        }
        return null;
    }

    /**
     * Build the directory => module map from the settings that store a path: a
     * setting whose value is a path is attributed to the installed module whose
     * identifier is the prefix of the setting name (the longest match wins).
     *
     * @return array<string, string> Directory name (lower case) => module.
     */
    public static function buildOwnerMap(Connection $connection): array
    {
        $modules = [];
        foreach ($connection->executeQuery('SELECT id FROM module')->fetchFirstColumn() as $id) {
            $modules[$id] = preg_replace('/[^a-z0-9]/', '', strtolower((string) $id));
        }
        // Longest identifier first, so BulkImportFiles wins over BulkImport.
        uasort($modules, fn ($a, $b) => strlen($b) <=> strlen($a));

        $owners = [];
        foreach ($connection->executeQuery('SELECT id, value FROM setting')->fetchAllAssociative() as $row) {
            $keyNorm = preg_replace('/[^a-z0-9]/', '', strtolower((string) $row['id']));
            foreach (self::flattenStrings(json_decode((string) $row['value'], true)) as $value) {
                // Keep only absolute filesystem paths, so certificates, keys or
                // urls stored in a setting are not mistaken for a directory.
                if ($value === '' || $value[0] !== '/' || strpos($value, "\n") !== false) {
                    continue;
                }
                $base = strtolower(basename(rtrim($value, '/')));
                if ($base === '' || isset($owners[$base]) || !preg_match('/^[\w.-]+$/', $base)) {
                    continue;
                }
                foreach ($modules as $moduleId => $norm) {
                    if ($norm !== '' && strpos($keyNorm, $norm) === 0) {
                        $owners[$base] = $moduleId;
                        break;
                    }
                }
            }
        }
        return $owners;
    }

    /**
     * Recursively collect the string values of a decoded setting.
     *
     * @return string[]
     */
    protected static function flattenStrings($value): array
    {
        if (is_string($value)) {
            return [$value];
        }
        if (!is_array($value)) {
            return [];
        }
        $strings = [];
        foreach ($value as $item) {
            $strings = array_merge($strings, self::flattenStrings($item));
        }
        return $strings;
    }
}
