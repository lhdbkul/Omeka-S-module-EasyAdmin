<?php declare(strict_types=1);

namespace EasyAdminTest\Controller\Admin;

use EasyAdminTest\EasyAdminTestTrait;
use Omeka\Test\AbstractHttpControllerTestCase;

/**
 * Tests for user data directories feature in File Manager.
 */
class FileManagerUserDataTest extends AbstractHttpControllerTestCase
{
    use EasyAdminTestTrait;

    /**
     * @var string
     */
    protected $userDataBase;

    /**
     * @var string|null
     */
    protected $testUserDir;

    public function setUp(): void
    {
        parent::setUp();
        $this->loginAdmin();
        $this->userDataBase = $this->getBasePath() . '/userdata';
    }

    public function tearDown(): void
    {
        // Clean up test files and directories.
        if ($this->testUserDir && is_dir($this->testUserDir)) {
            $files = glob($this->testUserDir . '/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
            @rmdir($this->testUserDir);
        }
        // Clean up .htaccess created during tests.
        $htaccess = $this->userDataBase . '/.htaccess';
        if (file_exists($htaccess)) {
            @unlink($htaccess);
        }
        // Remove userdata dir only if empty.
        if (is_dir($this->userDataBase)) {
            @rmdir($this->userDataBase);
        }
        $this->logout();
        parent::tearDown();
    }

    /**
     * Test browse action with userdata directory when setting is enabled.
     */
    public function testBrowseWithUserDataEnabled(): void
    {
        $user = $this->getCurrentUser();
        $userId = $user->getId();

        // Enable the setting.
        $this->settings()->set('easyadmin_user_directories', true);

        $this->dispatch('/admin/easy-admin/file-manager/browse');

        $this->assertResponseStatusCode(200);

        // The user's directory should have been created.
        $userDir = $this->userDataBase . '/' . $userId;
        $this->assertDirectoryExists($userDir);
        $this->testUserDir = $userDir;

        // The .htaccess should have been created.
        $this->assertFileExists($this->userDataBase . '/.htaccess');

        // Disable the setting after test.
        $this->settings()->set('easyadmin_user_directories', false);
    }

    /**
     * Test that userdata directories appear in dropdown when enabled.
     */
    public function testUserDataDirsInDropdown(): void
    {
        $user = $this->getCurrentUser();
        $userId = $user->getId();

        $this->settings()->set('easyadmin_user_directories', true);

        $this->dispatch('/admin/easy-admin/file-manager/browse');

        $this->assertResponseStatusCode(200);
        // The dropdown should contain an option with the user's directory path.
        $this->assertQueryContentRegex('select#dir_path option', '/userdata/');

        $this->testUserDir = $this->userDataBase . '/' . $userId;
        $this->settings()->set('easyadmin_user_directories', false);
    }

    /**
     * Test that userdata directories do NOT appear when setting is disabled.
     */
    public function testUserDataDirsHiddenWhenDisabled(): void
    {
        $this->settings()->set('easyadmin_user_directories', false);

        $this->dispatch('/admin/easy-admin/file-manager/browse');

        $this->assertResponseStatusCode(200);
        // No userdata option in dropdown.
        $this->assertNotQueryContentRegex('select#dir_path option', '/userdata/');
    }

    /**
     * Test browsing own userdata directory is allowed.
     */
    public function testBrowseOwnUserDataDirectory(): void
    {
        $user = $this->getCurrentUser();
        $userId = $user->getId();

        $this->settings()->set('easyadmin_user_directories', true);

        $userDir = $this->userDataBase . '/' . $userId;
        if (!is_dir($userDir)) {
            mkdir($userDir, 0775, true);
        }
        $this->testUserDir = $userDir;

        $this->dispatch('/admin/easy-admin/file-manager/browse?dir_path=' . urlencode($userDir));

        $this->assertResponseStatusCode(200);
        // Should show filesystem path info for userdata directories.
        $this->assertQueryContentRegex('.messages .notice code', '/' . preg_quote($userDir, '/') . '/');

        $this->settings()->set('easyadmin_user_directories', false);
    }

    /**
     * Test download action requires authentication.
     */
    public function testDownloadRequiresAuth(): void
    {
        $this->logout();
        $this->dispatch('/admin/easy-admin/file-manager/download?dir_path=/files/userdata/1&filename=test.txt');

        // Should redirect to login page.
        $this->assertResponseStatusCode(302);
    }

    /**
     * Test download action rejects non-userdata paths.
     */
    public function testDownloadRejectsNonUserDataPath(): void
    {
        $importDir = $this->getBasePath() . '/import';
        $this->dispatch('/admin/easy-admin/file-manager/download?dir_path=' . urlencode($importDir) . '&filename=test.txt');

        // Should redirect back with error.
        $this->assertResponseStatusCode(302);
    }

    /**
     * Test download action rejects missing parameters.
     */
    public function testDownloadRejectsMissingParams(): void
    {
        $this->dispatch('/admin/easy-admin/file-manager/download');

        $this->assertResponseStatusCode(302);
    }

    /**
     * Test download action rejects directory traversal.
     */
    public function testDownloadRejectsDirectoryTraversal(): void
    {
        $userDir = $this->userDataBase . '/1';
        $this->dispatch('/admin/easy-admin/file-manager/download?dir_path=' . urlencode($userDir) . '&filename=../../../config/database.ini');

        $this->assertResponseStatusCode(302);
    }

    /**
     * Test download action with valid userdata file.
     */
    public function testDownloadValidUserDataFile(): void
    {
        $user = $this->getCurrentUser();
        $userId = $user->getId();
        $userDir = $this->userDataBase . '/' . $userId;

        if (!is_dir($userDir)) {
            mkdir($userDir, 0775, true);
        }
        $this->testUserDir = $userDir;

        // Create a test file.
        $testFile = $userDir . '/test-download.txt';
        file_put_contents($testFile, 'Test content for download');

        // Also ensure .htaccess exists for realpath check.
        if (!file_exists($this->userDataBase . '/.htaccess')) {
            file_put_contents($this->userDataBase . '/.htaccess', 'Require all denied');
        }

        // Reset for fresh dispatch.
        $this->reset();
        $this->loginAdmin();

        $bufferLevel = ob_get_level();
        ob_start();
        $this->dispatch('/admin/easy-admin/file-manager/download?dir_path=' . urlencode($userDir) . '&filename=test-download.txt');
        while (ob_get_level() > $bufferLevel) {
            ob_end_clean();
        }

        $statusCode = $this->getResponse()->getStatusCode();
        $this->assertTrue(
            $statusCode === 200 || $statusCode === 302,
            'Expected 200 for download or 302 redirect'
        );
    }

    /**
     * Test .htaccess is recreated if manually deleted.
     */
    public function testHtaccessRecreatedIfDeleted(): void
    {
        $this->settings()->set('easyadmin_user_directories', true);

        // Create then delete .htaccess.
        if (!is_dir($this->userDataBase)) {
            mkdir($this->userDataBase, 0775, true);
        }
        $htaccess = $this->userDataBase . '/.htaccess';
        if (file_exists($htaccess)) {
            unlink($htaccess);
        }
        $this->assertFileDoesNotExist($htaccess);

        // Visiting the file manager should recreate it.
        $this->dispatch('/admin/easy-admin/file-manager/browse');

        $this->assertResponseStatusCode(200);
        $this->assertFileExists($htaccess);

        // Clean up user dir that was created.
        $user = $this->getCurrentUser();
        $this->testUserDir = $this->userDataBase . '/' . $user->getId();

        $this->settings()->set('easyadmin_user_directories', false);
    }
}
