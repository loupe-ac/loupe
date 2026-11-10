<?php

declare(strict_types=1);

namespace App\Module\Account\Command\Admin;

use App\Exception\DomainErrors;
use App\Module\Account\Entity\WaitlistBlockPattern;
use App\Module\Account\Repository\WaitlistBlockPatternRepository;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

final readonly class AddWaitlistBlockPatternHandler
{
    private const string DUPLICATE = 'account.admin.waitlist.block_patterns.error.duplicate';

    public function __construct(
        private WaitlistBlockPatternRepository $waitlistBlockPatterns,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(AddWaitlistBlockPatternCommand $command): void
    {
        // The entity setter refuses a pattern of only "*", so the check stays on the entity.
        $pattern = new WaitlistBlockPattern($command->pattern);

        $id = $this->waitlistBlockPatterns->insertIfAbsent($pattern->pattern);
        if (null === $id) {
            throw new DomainErrors(['pattern' => self::DUPLICATE]);
        }

        // The pattern text stays out of the record, because a pattern can be a whole address.
        $this->auditor->record(
            'account.waitlist_block_pattern_added',
            AuditOutcome::Success,
            ['patternId' => (string) $id],
            new AuditSubject('waitlist_block_pattern', (string) $id),
        );
    }
}
