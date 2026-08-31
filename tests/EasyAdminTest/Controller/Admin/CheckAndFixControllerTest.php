<?php declare(strict_types=1);

namespace EasyAdminTest\Controller\Admin;

use EasyAdmin\Controller\Admin\CheckAndFixController;
use EasyAdminTest\EasyAdminTestTrait;
use Omeka\Test\AbstractHttpControllerTestCase;

/**
 * Tests for EasyAdmin Check and Fix Controller.
 */
class CheckAndFixControllerTest extends AbstractHttpControllerTestCase
{
    use EasyAdminTestTrait;

    public function setUp(): void
    {
        parent::setUp();
        $this->loginAdmin();
    }

    public function tearDown(): void
    {
        $this->logout();
        parent::tearDown();
    }

    /**
     * Test check and fix index page is accessible.
     */
    public function testCheckAndFixIndexAction(): void
    {
        $this->dispatch('/admin/easy-admin/check-and-fix');

        $this->assertResponseStatusCode(200);
        $this->assertControllerName('EasyAdmin\Controller\Admin\CheckAndFix');
        $this->assertActionName('index');
    }

    /**
     * Test check and fix page requires authentication.
     */
    public function testCheckAndFixRequiresAuth(): void
    {
        $this->logout();
        $this->dispatch('/admin/easy-admin/check-and-fix');

        // Should redirect to login page.
        $this->assertResponseStatusCode(302);
    }

    /**
     * Test check and fix page contains form.
     */
    public function testCheckAndFixContainsForm(): void
    {
        $this->dispatch('/admin/easy-admin/check-and-fix');

        $this->assertResponseStatusCode(200);
        $this->assertQuery('#check-and-fix-form');
    }

    /**
     * A quick process runs in the web process, so it must be a real process of
     * the controller: a renamed or removed case would silently fall back to
     * the background dispatch.
     */
    public function testQuickProcessesAreHandledByTheController(): void
    {
        $source = file_get_contents(
            OMEKA_PATH . '/modules/EasyAdmin/src/Controller/Admin/CheckAndFixController.php'
        );
        foreach (CheckAndFixController::QUICK_PROCESSES as $process) {
            $this->assertStringContainsString(
                "case '" . $process . "':",
                $source,
                sprintf('The quick process "%s" is not handled by the controller.', $process)
            );
        }
    }

    /**
     * A quick process must not loop on the resources or the files: only bounded
     * sql or a loop on a fixed list may run in the web process.
     */
    public function testQuickProcessesAreNotFileProcesses(): void
    {
        foreach (CheckAndFixController::QUICK_PROCESSES as $process) {
            $this->assertStringStartsWith(
                'db_',
                $process,
                sprintf('The quick process "%s" is not a database process.', $process)
            );
        }
    }

    /**
     * The quick processes are flagged in the page, so the user knows which
     * tasks return their result immediately.
     */
    public function testQuickProcessesAreFlaggedInThePage(): void
    {
        $this->dispatch('/admin/easy-admin/check-and-fix');

        $this->assertResponseStatusCode(200);
        $body = $this->getResponse()->getBody();
        $this->assertStringContainsString('data-quick-processes', $body);
        foreach (CheckAndFixController::QUICK_PROCESSES as $process) {
            $this->assertStringContainsString($process, $body);
        }
    }
}
