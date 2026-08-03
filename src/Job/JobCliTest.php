<?php declare(strict_types=1);

namespace EasyAdmin\Job;

use Omeka\Job\AbstractJob;

/**
 * Trivial job used to test that the background pipeline (PHP-CLI, bootstrap,
 * database) works end to end: it only logs a success message. When it reaches
 * the status "completed", the whole chain is functional.
 */
class JobCliTest extends AbstractJob
{
    public function perform(): void
    {
        $services = $this->getServiceLocator();
        /** @var \Laminas\Log\Logger $logger */
        $logger = $services->get('Omeka\Logger');
        $logger->info('EasyAdmin: PHP-CLI test job ran successfully; the background pipeline works.'); // @translate
    }
}
