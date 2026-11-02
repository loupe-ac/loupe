<?php

declare(strict_types=1);

namespace App\Module\OAuth\Command;

use App\Exception\DomainErrors;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/** Deletes the row of a key that no longer works. A working key is revoked first. */
final readonly class RemoveApiKeyHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(RemoveApiKeyCommand $command): void
    {
        $key = $command->key;
        if (!$key->isRevoked() && !$key->isExpiredAt($this->clock->now())) {
            throw new DomainErrors(['key' => 'oauth.api_key.error.remove_active']);
        }

        $this->em->remove($key);
        $this->em->flush();
    }
}
