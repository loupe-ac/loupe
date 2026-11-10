<?php

declare(strict_types=1);

namespace App\Module\Account\Command\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

final readonly class RemoveWaitlistBlockPatternHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(RemoveWaitlistBlockPatternCommand $command): void
    {
        $patternId = (string) ($command->pattern->id ?? throw new \LogicException('A persisted block pattern always has an id.'));

        $this->em->remove($command->pattern);
        $this->em->flush();

        $this->auditor->record(
            'account.waitlist_block_pattern_removed',
            AuditOutcome::Success,
            ['patternId' => $patternId],
            new AuditSubject('waitlist_block_pattern', $patternId),
        );
    }
}
