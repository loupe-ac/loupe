<?php

declare(strict_types=1);

namespace App\Module\Bridge\Scheduler;

use App\Module\Bridge\Command\ResumeStrandedWaitsCommand;
use App\Module\Bridge\Command\ResumeStrandedWaitsHandler;
use Psr\Log\LoggerInterface;
use Symfony\Component\Scheduler\Attribute\AsCronTask;

/** Sends the resume again for each wait that ended while its bridge was offline and whose bridge is back, every minute. */
#[AsCronTask('%app.bridge.stranded_wait_resume_schedule%')]
final readonly class ResumeStrandedWaitsTask
{
    public function __construct(
        private ResumeStrandedWaitsHandler $resumeStrandedWaits,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(): void
    {
        $waits = ($this->resumeStrandedWaits)(new ResumeStrandedWaitsCommand());
        if ($waits > 0) {
            $this->logger->info('bridge.stranded_waits_resumed', ['waits' => $waits]);
        }
    }
}
