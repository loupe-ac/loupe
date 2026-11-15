<?php

declare(strict_types=1);

namespace App\Module\OAuth\Command;

use App\Exception\DomainErrors;
use App\Mercure\AccountArea;
use App\Mercure\AccountChangedPublisher;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/** Deletes the row of a key that no longer works. A working key is revoked first. */
final readonly class RemoveApiKeyHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private ClockInterface $clock,
        private AccountChangedPublisher $accountChanged,
    ) {
    }

    public function __invoke(RemoveApiKeyCommand $command): void
    {
        $key = $command->key;
        if (!$key->isRevoked() && !$key->isExpiredAt($this->clock->now())) {
            throw new DomainErrors(['key' => 'oauth.api_key.error.remove_active']);
        }

        $ownerId = $key->owner->id ?? throw new \LogicException('a persisted user always has an id');
        $this->em->remove($key);
        $this->em->flush();
        $this->accountChanged->changed($ownerId, AccountArea::API_KEYS);
    }
}
