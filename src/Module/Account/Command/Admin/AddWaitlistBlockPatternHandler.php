<?php

declare(strict_types=1);

namespace App\Module\Account\Command\Admin;

use App\Exception\DomainErrors;
use App\Module\Account\Entity\WaitlistBlockPattern;
use App\Module\Account\Repository\WaitlistBlockPatternRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

final readonly class AddWaitlistBlockPatternHandler
{
    private const string DUPLICATE = 'account.admin.waitlist.block_patterns.error.duplicate';

    public function __construct(
        private WaitlistBlockPatternRepository $waitlistBlockPatterns,
        private EntityManagerInterface $em,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(AddWaitlistBlockPatternCommand $command): WaitlistBlockPattern
    {
        if (null !== $this->waitlistBlockPatterns->findOneByPattern($command->pattern)) {
            throw new DomainErrors(['pattern' => self::DUPLICATE]);
        }

        $pattern = new WaitlistBlockPattern($command->pattern);

        try {
            $this->em->persist($pattern);
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            throw new DomainErrors(['pattern' => self::DUPLICATE]);
        }

        // The pattern text stays out of the record, because a pattern can be a whole address.
        $patternId = (string) ($pattern->id ?? throw new \LogicException('A persisted block pattern always has an id.'));
        $this->auditor->record(
            'account.waitlist_block_pattern_added',
            AuditOutcome::Success,
            ['patternId' => $patternId],
            new AuditSubject('waitlist_block_pattern', $patternId),
        );

        return $pattern;
    }
}
