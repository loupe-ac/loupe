<?php

declare(strict_types=1);

namespace App\Module\Forge\Service;

use App\Module\Forge\Entity\ForgeRepository;
use App\Module\Forge\Entity\ForgeRepositoryCheck;
use App\Module\Forge\Messenger\CheckForgeRepository;
use App\Module\Forge\Messenger\CheckTrigger;
use App\Module\Forge\Repository\ForgeRepositoryCheckRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/** Says when a stored read is recent enough, and asks for a check when it is not. */
final readonly class RepositoryChecks
{
    private const string FRESH_FOR = '-5 minutes';

    public function __construct(
        private ForgeRepositoryCheckRepository $forgeRepositoryChecks,
        private MessageBusInterface $bus,
        private ClockInterface $clock,
    ) {
    }

    /** The age of a read is the newer of checkedAt and attemptedAt, so a failed read counts too. */
    public function isFresh(?ForgeRepositoryCheck $check, \DateTimeImmutable $now): bool
    {
        if (null === $check) {
            return false;
        }

        $readAt = max($check->checkedAt, $check->attemptedAt);

        return null !== $readAt && $readAt > $now->modify(self::FRESH_FOR);
    }

    /** @return bool true when it queued a check, false when a fresh read answers already */
    public function requestPageOpenCheck(ForgeRepository $repository): bool
    {
        if ($this->isFresh($this->forgeRepositoryChecks->findOneOfRepository($repository), $this->clock->now())) {
            return false;
        }

        $this->bus->dispatch(new CheckForgeRepository($repository->id?->toRfc4122() ?? throw new \LogicException('A persisted repository has an id.'), CheckTrigger::PageOpen));

        return true;
    }
}
