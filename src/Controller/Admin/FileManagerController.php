<?php declare(strict_types=1);

namespace EasyAdmin\Controller\Admin;

use Common\Stdlib\PsrMessage;
use EasyAdmin\Controller\TraitEasyDir;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
use Omeka\Form\ConfirmForm;
use Omeka\Permissions\Acl;

class FileManagerController extends AbstractActionController
{
    use TraitEasyDir;

    /**
     * Default number of files per page.
     */
    protected const PER_PAGE = 100;

    /**
     * @var \Omeka\Permissions\Acl
     */
    protected $acl;

    /**
     * @var bool
     */
    protected $allowAnyPath;

    /**
     * @var string
     */
    protected $basePath;

    /**
     * @var string
     */
    protected $baseUri;

    /**
     * @var string
     */
    protected $tempDir;

    public function __construct(
        Acl $acl,
        bool $allowAnyPath,
        string $basePath,
        ?string $baseUri,
        string $tempDir
    ) {
        $this->acl = $acl;
        $this->allowAnyPath = $allowAnyPath;
        $this->basePath = $basePath;
        $this->baseUri = $baseUri;
        $this->tempDir = $tempDir;
    }

    public function browseAction()
    {
        $user = $this->identity();
        $settings = $this->settings();

        $isAdmin = $user ? $this->acl->isAdminRole($user->getRole()) : false;
        $canCreateItems = $this->acl->userIsAllowed('Omeka\Api\Adapter\ItemAdapter', 'create');
        $userId = $user ? $user->getId() : null;

        $errorMessage = null;
        $currentPath = $this->params()->fromQuery('dir_path');
        $dirPath = $this->getAndCheckDirPath($currentPath, $errorMessage);

        // Check if directory is protected (read-only).
        $isProtected = $dirPath ? $this->isProtectedDirectory($dirPath) : false;

        // Check if directory is a userdata directory.
        $isUserData = $dirPath ? $this->isUserDataDirectory($dirPath) : false;

        // Userdata directories require the ability to create items.
        // Non-admin users can only access their own directory.
        if ($isUserData) {
            if (!$canCreateItems) {
                $this->messenger()->addError('Access denied.'); // @translate
                return $this->redirect()->toRoute('admin/easy-admin/file-manager', ['action' => 'browse']);
            }
            if (!$isAdmin) {
                $dirUserId = $this->getUserIdFromPath($dirPath);
                if ($dirUserId !== $userId) {
                    $this->messenger()->addError('Access denied.'); // @translate
                    return $this->redirect()->toRoute('admin/easy-admin/file-manager', ['action' => 'browse']);
                }
            }
        }

        // Determine write access: own userdata dir or regular non-protected dir.
        $canWriteInDir = false;
        if ($dirPath && !$isProtected) {
            if ($isUserData) {
                $dirUserId = $this->getUserIdFromPath($dirPath);
                $canWriteInDir = $dirUserId === $userId;
            } else {
                $canWriteInDir = true;
            }
        }

        // Pagination parameters.
        $page = (int) $this->params()->fromQuery('page', 1);
        $perPage = self::PER_PAGE;

        // Upload configuration (only for writeable directories).
        $uploadData = null;
        if ($dirPath && $canWriteInDir) {
            $uploadData = $this->prepareUploadData($settings);
        }

        // Ensure .htaccess if userdata directories are enabled.
        if ($settings->get('easyadmin_user_directories') && $canCreateItems) {
            $this->ensureUserDataHtaccess();
        }

        // File listing with pagination.
        $files = [];
        $totalFiles = 0;
        $totalPages = 1;
        $localUrl = null;
        $formDelete = null;

        if ($dirPath) {
            $base = $this->baseUri
                ? rtrim($this->baseUri, '/')
                : rtrim($this->url()->fromRoute('top', []), '/') . '/files';
            $partPath = trim(mb_substr($dirPath, mb_strlen(rtrim($this->basePath, '/')) + 1), '/');
            $localUrl = $base . '/' . $partPath;

            // Get paginated files.
            $displayDir = (bool) $this->params()->fromQuery('display_dir');
            $result = $this->getPaginatedFiles($dirPath, $page, $perPage, $displayDir);
            $files = $result['files'];
            $totalFiles = $result['total'];
            $totalPages = (int) ceil($totalFiles / $perPage);

            // Delete form (only for writeable, non-protected directories).
            if ($canWriteInDir) {
                $formDelete = $this->getForm(ConfirmForm::class);
                $formDelete
                    ->setAttribute('action', $this->url()->fromRoute(null, ['action' => 'batch-delete'], true))
                    ->setAttribute('id', 'confirm-delete')
                    ->setButtonLabel('Confirm delete'); // @translate
            }
        } else {
            $this->messenger()->addError($this->translate($errorMessage));
        }

        // Directory list for dropdown.
        $dirPaths = $this->getAvailableDirectories($settings, $dirPath, $isAdmin, $canCreateItems, $userId);

        return new ViewModel([
            'basePath' => $this->basePath,
            'localUrl' => $localUrl,
            'dirPath' => $dirPath,
            'dirPaths' => $dirPaths,
            'isProtected' => $isProtected,
            'isUserData' => $isUserData,
            'canWriteInDir' => $canWriteInDir,
            'uploadData' => $uploadData,
            'files' => $files,
            'totalFiles' => $totalFiles,
            'page' => $page,
            'perPage' => $perPage,
            'totalPages' => $totalPages,
            'formDelete' => $formDelete,
            'isAdmin' => $isAdmin,
        ]);
    }

    /**
     * Download a file from a userdata directory.
     *
     * Userdata directories are protected by .htaccess, so direct http access
     * is denied. This action streams the file after verifying authentication.
     * Admins can download from any user directory; other users can only
     * download from their own directory.
     */
    public function downloadAction()
    {
        $user = $this->identity();
        if (!$user || !$this->acl->userIsAllowed('Omeka\Api\Adapter\ItemAdapter', 'create')) {
            $this->messenger()->addError('Access denied.'); // @translate
            return $this->redirect()->toRoute('admin/easy-admin/file-manager', ['action' => 'browse']);
        }

        $dirPath = $this->params()->fromQuery('dir_path');
        $filename = $this->params()->fromQuery('filename');

        if (!$dirPath || !$filename) {
            $this->messenger()->addError('Invalid request.'); // @translate
            return $this->redirect()->toRoute('admin/easy-admin/file-manager', ['action' => 'browse']);
        }

        // Only allow download from userdata directories via this action.
        if (!$this->isUserDataDirectory($dirPath)) {
            $this->messenger()->addError('Invalid request.'); // @translate
            return $this->redirect()->toRoute('admin/easy-admin/file-manager', ['action' => 'browse']);
        }

        // Non-admin users can only download from their own directory.
        if (!$this->acl->isAdminRole($user->getRole())) {
            $dirUserId = $this->getUserIdFromPath($dirPath);
            if ($dirUserId !== $user->getId()) {
                $this->messenger()->addError('Access denied.'); // @translate
                return $this->redirect()->toRoute('admin/easy-admin/file-manager', ['action' => 'browse']);
            }
        }

        // Security: use basename to prevent directory traversal.
        $safeFilename = basename($filename);
        $filepath = rtrim($dirPath, '/') . '/' . $safeFilename;

        // Verify the file is actually in a userdata directory (paranoid check).
        $realUserDataBase = realpath($this->getUserDataBasePath());
        $realFilepath = realpath($filepath);
        if ($realUserDataBase === false || $realFilepath === false
            || strpos($realFilepath, $realUserDataBase . DIRECTORY_SEPARATOR) !== 0
        ) {
            $this->messenger()->addError('Invalid file path.'); // @translate
            return $this->redirect()->toRoute('admin/easy-admin/file-manager', ['action' => 'browse']);
        }

        // Use SendFile plugin for streaming.
        $response = $this->sendFile($filepath, [
            'filename' => $safeFilename,
            'disposition_mode' => 'attachment',
            'cache' => false,
        ]);

        if (!$response) {
            $this->messenger()->addError('File not found.'); // @translate
            return $this->redirect()->toRoute('admin/easy-admin/file-manager', ['action' => 'browse']);
        }

        return $response;
    }

    /**
     * Prepare upload configuration data.
     */
    protected function prepareUploadData($settings): array
    {
        if ($settings->get('disable_file_validation', false)) {
            $allowedMediaTypes = '';
            $allowedExtensions = '';
        } else {
            $allowedMediaTypes = implode(',', $settings->get('media_type_whitelist', []));
            $allowedExtensions = implode(',', $settings->get('extension_whitelist', []));
        }

        $skipCsrf = (bool) $settings->get('easyadmin_disable_csrf', false);
        $allowEmptyFiles = (bool) $settings->get('easyadmin_allow_empty_files', false);

        return [
            'data-bulk-upload' => true,
            'data-csrf' => $skipCsrf ? '' : (new \Laminas\Form\Element\Csrf('csrf'))->getValue(),
            'data-allowed-media-types' => $allowedMediaTypes,
            'data-allowed-extensions' => $allowedExtensions,
            'data-allow-empty-files' => (int) $allowEmptyFiles,
            'data-translate-pause' => $this->translate('Pause'), // @translate
            'data-translate-resume' => $this->translate('Resume'), // @translate
            'data-translate-no-file' => $this->translate('No files currently selected for upload'), // @translate
            'data-translate-invalid-file' => $allowEmptyFiles
                ? $this->translate('Not a valid file type or extension. Update your selection.') // @translate
                : $this->translate('Not a valid file type, extension or size. Update your selection.'), // @translate
            'data-translate-unknown-error' => $this->translate('An issue occurred.'), // @translate
        ];
    }

    /**
     * Get paginated list of files from directory.
     *
     * Uses scandir for better performance with large directories (100k+ files).
     */
    protected function getPaginatedFiles(string $dirPath, int $page, int $perPage, bool $includeDirectories = false): array
    {
        $allEntries = @scandir($dirPath);
        if ($allEntries === false) {
            return ['files' => [], 'total' => 0];
        }

        // Filter entries.
        $entries = [];
        foreach ($allEntries as $entry) {
            // Skip hidden files and special entries.
            if ($entry === '.' || $entry === '..' || mb_substr($entry, 0, 1) === '.') {
                continue;
            }

            $fullPath = $dirPath . '/' . $entry;
            $isDir = is_dir($fullPath);

            if ($isDir && !$includeDirectories) {
                continue;
            }

            if (!is_readable($fullPath)) {
                continue;
            }

            $entries[] = [
                'name' => $entry,
                'path' => $fullPath,
                'isDir' => $isDir,
            ];
        }

        $total = count($entries);

        // Sort: directories first, then files alphabetically.
        usort($entries, function ($a, $b) {
            if ($a['isDir'] !== $b['isDir']) {
                return $a['isDir'] ? -1 : 1;
            }
            return strcasecmp($a['name'], $b['name']);
        });

        // Paginate.
        $offset = ($page - 1) * $perPage;
        $pagedEntries = array_slice($entries, $offset, $perPage);

        // Enrich with file info.
        $files = [];
        foreach ($pagedEntries as $entry) {
            $file = [
                'name' => $entry['name'],
                'path' => $entry['path'],
                'isDir' => $entry['isDir'],
                'isWritable' => is_writeable($entry['path']),
            ];

            if (!$entry['isDir']) {
                $file['size'] = filesize($entry['path']);
                $file['mtime'] = filemtime($entry['path']);
            }

            $files[] = $file;
        }

        return ['files' => $files, 'total' => $total];
    }

    /**
     * Get available directories for the dropdown.
     *
     * @return array Associative array of path => label for the dropdown.
     */
    protected function getAvailableDirectories($settings, ?string $currentPath, bool $isAdmin = false, bool $canCreateItems = false, ?int $userId = null): array
    {
        $paths = $settings->get('easyadmin_local_paths', []);
        if (!is_array($paths)) {
            $paths = [];
        }

        // Add default path.
        $defaultPath = $settings->get('easyadmin_local_path');
        if ($defaultPath) {
            array_unshift($paths, $defaultPath);
        }

        // Add current path if valid (but not userdata — handled below).
        if ($currentPath && !$this->isUserDataDirectory($currentPath)) {
            $paths[] = $currentPath;
        }

        // Add protected directories for browsing.
        $basePath = rtrim($this->basePath, '/');
        foreach (self::PROTECTED_DIRECTORIES as $dir) {
            $protectedPath = $basePath . '/' . $dir;
            if (is_dir($protectedPath) && is_readable($protectedPath)) {
                $paths[] = $protectedPath;
            }
        }

        $paths = array_unique(array_filter($paths));
        sort($paths);

        // Build the result as path => label.
        $result = [];
        foreach ($paths as $path) {
            $result[$path] = str_replace(OMEKA_PATH, '', $path);
        }

        // Add userdata directories for users who can create items.
        // Admins see all user directories; other users see only their own.
        if ($canCreateItems && $settings->get('easyadmin_user_directories')) {
            $this->ensureUserDataHtaccess();

            $userDataBase = $this->getUserDataBasePath();

            // Ensure the current user's directory exists.
            if ($userId) {
                $ownDirPath = $this->getUserDirPath($userId);
                $this->checkDestinationDir($ownDirPath);
            }

            if ($isAdmin) {
                // Admins see all userdata subdirectories.
                $userIds = [];
                if (is_dir($userDataBase)) {
                    $entries = @scandir($userDataBase);
                    if ($entries) {
                        foreach ($entries as $entry) {
                            if ($entry === '.' || $entry === '..' || !ctype_digit($entry)) {
                                continue;
                            }
                            $entryPath = $userDataBase . '/' . $entry;
                            if (is_dir($entryPath)) {
                                $userIds[] = (int) $entry;
                            }
                        }
                    }
                }
            } else {
                // Non-admin users see only their own directory.
                $userIds = $userId ? [$userId] : [];
            }

            // Resolve user names via API.
            $userNames = [];
            if ($userIds) {
                try {
                    $api = $this->api();
                    $users = $api->search('users', ['id' => $userIds])->getContent();
                    foreach ($users as $userRepr) {
                        $userNames[$userRepr->id()] = $userRepr->name();
                    }
                } catch (\Exception $e) {
                    // Silently continue without names.
                }
            }

            // Add userdata directories to the dropdown.
            foreach ($userIds as $uid) {
                $path = $userDataBase . '/' . $uid;
                $name = $userNames[$uid]
                    ?? sprintf($this->translate('(deleted user #%d)'), $uid); // @translate
                $label = $uid === $userId
                    ? sprintf('/files/userdata/%d — %s (%s)', $uid, $name, $this->translate('your directory')) // @translate
                    : sprintf('/files/userdata/%d — %s', $uid, $name);
                $result[$path] = $label;
            }
        }

        return $result;
    }

    public function deleteConfirmAction()
    {
        $errorMessage = null;
        $currentPath = $this->params()->fromQuery('dir_path');
        $dirPath = $this->getAndCheckDirPath($currentPath, $errorMessage);
        if (!$dirPath) {
            throw new \Laminas\Mvc\Exception\RuntimeException($this->translate($errorMessage));
        }

        // Protected directories cannot be modified.
        if ($this->isProtectedDirectory($dirPath)) {
            throw new \Laminas\Mvc\Exception\RuntimeException(
                $this->translate('This directory is managed by Omeka and is read-only.') // @translate
            );
        }

        $filename = $this->params()->fromQuery('filename');
        $errorMessage = null;
        if (!$this->checkFilename($filename, $errorMessage)) {
            throw new \Laminas\Mvc\Exception\RuntimeException($this->translate($errorMessage));
        }

        $filepath = rtrim($dirPath, '/') . '/' . $filename;
        $errorMessage = null;
        if (!$this->checkFile($filepath, $errorMessage)) {
            throw new \Laminas\Mvc\Exception\RuntimeException($this->translate($errorMessage));
        }

        $form = $this->getForm(ConfirmForm::class);
        $form->setAttribute('action', $this->url()->fromRoute(
            'admin/easy-admin/file-manager',
            ['action' => 'delete'],
            ['query' => ['dir_path' => $dirPath, 'filename' => $filename]],
            true
        ));

        return (new ViewModel([
            'resource' => $filename,
            'dirPath' => $dirPath,
            'file' => $filename,
            'resourceLabel' => 'file', // @translate
            'form' => $form,
            'partialPath' => null,
            'linkTitle' => true,
            'wrapSidebar' => false,
        ]))->setTerminal(true);
    }

    public function deleteAction()
    {
        $dirPath = $this->params()->fromQuery('dir_path');

        if ($this->getRequest()->isPost()) {
            $form = $this->getForm(ConfirmForm::class);
            $form->setData($this->getRequest()->getPost());
            if ($form->isValid()) {
                $filename = $this->params()->fromQuery('filename');
                $this->deleteFile($dirPath, $filename);
            } else {
                $this->messenger()->addFormErrors($form);
            }
        }

        return $this->redirect()->toRoute(
            'admin/easy-admin/file-manager',
            ['action' => 'browse'],
            ['query' => ['dir_path' => $dirPath]],
            true
        );
    }

    public function batchDeleteAction()
    {
        $dirPath = $this->params()->fromPost('dir_path');
        $query = ['dir_path' => $dirPath];

        if (!$this->getRequest()->isPost()) {
            return $this->redirect()->toRoute(null, ['action' => 'browse'], ['query' => $query], true);
        }

        // Validate directory.
        $errorMessage = null;
        $dirPath = $this->getAndCheckDirPath($dirPath, $errorMessage, true);
        if (!$dirPath) {
            $this->messenger()->addError($errorMessage ?: 'You must set a writeable directory to delete files.'); // @translate
            return $this->redirect()->toRoute(null, ['action' => 'browse'], ['query' => $query], true);
        }

        // Protected directories cannot be modified.
        if ($this->isProtectedDirectory($dirPath)) {
            $this->messenger()->addError('This directory is managed by Omeka and is read-only.'); // @translate
            return $this->redirect()->toRoute(null, ['action' => 'browse'], ['query' => $query], true);
        }

        $filenames = $this->params()->fromPost('filenames', []);
        if (!$filenames) {
            $this->messenger()->addError('You must select at least one file to delete.'); // @translate
            return $this->redirect()->toRoute(null, ['action' => 'browse'], ['query' => $query], true);
        }

        $form = $this->getForm(ConfirmForm::class);
        $form->setData($this->getRequest()->getPost());
        if ($form->isValid()) {
            $deleted = 0;
            foreach ($filenames as $filename) {
                if ($this->deleteFile($dirPath, $filename, false)) {
                    ++$deleted;
                }
            }
            if ($deleted) {
                $this->messenger()->addSuccess(new PsrMessage(
                    '{count} file(s) deleted.', // @translate
                    ['count' => $deleted]
                ));
            } else {
                $this->messenger()->addWarning('No file deleted.'); // @translate
            }
        } else {
            $this->messenger()->addFormErrors($form);
        }

        return $this->redirect()->toRoute(null, ['action' => 'browse'], ['query' => $query], true);
    }

    /**
     * Delete a single file.
     *
     * @param bool $showMessages Whether to show success/error messages.
     */
    protected function deleteFile(string $dirPath, string $filename, bool $showMessages = true): bool
    {
        // Validate directory for writing.
        $errorMessage = null;
        $dirPath = $this->getAndCheckDirPath($dirPath, $errorMessage, true);
        if (!$dirPath) {
            if ($showMessages) {
                $this->messenger()->addError($errorMessage);
            }
            return false;
        }

        // Protected directories cannot be modified.
        if ($this->isProtectedDirectory($dirPath)) {
            if ($showMessages) {
                $this->messenger()->addError('This directory is managed by Omeka and is read-only.'); // @translate
            }
            return false;
        }

        // Userdata directories: only the owner can delete files.
        // Shared directories: only admins can delete files.
        if ($this->isUserDataDirectory($dirPath)) {
            $user = $this->identity();
            $dirUserId = $this->getUserIdFromPath($dirPath);
            if (!$user || $dirUserId !== $user->getId()) {
                if ($showMessages) {
                    $this->messenger()->addError('You can only delete files in your own directory.'); // @translate
                }
                return false;
            }
        } else {
            $user = $this->identity();
            $acl = $this->getEvent()->getApplication()->getServiceManager()->get('Omeka\Acl');
            if (!$user || !$acl->isAdminRole($user->getRole())) {
                if ($showMessages) {
                    $this->messenger()->addError('Only administrators can delete files in shared directories.'); // @translate
                }
                return false;
            }
        }

        // Validate filename.
        $errorMessage = null;
        if (!$this->checkFilename($filename, $errorMessage)) {
            if ($showMessages) {
                $this->messenger()->addError($errorMessage);
            }
            return false;
        }

        $filepath = rtrim($dirPath, '/') . '/' . $filename;

        if (!file_exists($filepath)) {
            return true; // Already deleted.
        }

        if (is_dir($filepath)) {
            if ($showMessages) {
                $this->messenger()->addError('Cannot delete a folder.'); // @translate
            }
            return false;
        }

        if (!is_file($filepath) || !is_writeable($filepath)) {
            if ($showMessages) {
                $this->messenger()->addError('The file cannot be deleted.'); // @translate
            }
            return false;
        }

        if (@unlink($filepath)) {
            if ($showMessages) {
                $this->messenger()->addSuccess('File deleted.'); // @translate
            }
            return true;
        }

        if ($showMessages) {
            $this->messenger()->addError('An error occurred during deletion.'); // @translate
        }
        return false;
    }
}
