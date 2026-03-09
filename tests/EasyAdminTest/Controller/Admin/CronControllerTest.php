<?php declare(strict_types=1);

namespace EasyAdminTest\Controller\Admin;

use EasyAdminTest\EasyAdminTestTrait;
use Omeka\Test\AbstractHttpControllerTestCase;

/**
 * Tests for EasyAdmin Cron Controller.
 */
class CronControllerTest extends AbstractHttpControllerTestCase
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
     * Test cron index page is accessible.
     */
    public function testCronIndexAction(): void
    {
        $this->dispatch('/admin/easy-admin/cron');

        $this->assertResponseStatusCode(200);
        $this->assertControllerName('EasyAdmin\Controller\Admin\Cron');
        $this->assertActionName('index');
    }

    /**
     * Test cron page requires authentication.
     */
    public function testCronRequiresAuth(): void
    {
        $this->logout();
        $this->dispatch('/admin/easy-admin/cron');

        // Should redirect to login page.
        $this->assertResponseStatusCode(302);
    }

    /**
     * Test cron controller is registered.
     */
    public function testCronControllerIsRegistered(): void
    {
        $controllerManager = $this->getService('ControllerManager');

        $this->assertTrue(
            $controllerManager->has('EasyAdmin\Controller\Admin\Cron')
        );
    }
}
