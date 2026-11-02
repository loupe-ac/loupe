<?php

declare(strict_types=1);

namespace App\Module\OAuth\Command;

use App\Exception\DomainErrors;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/** The caller decides who may revoke. The record says whether the owner or another person did. */
final readonly class RevokeApiKeyHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private ClockInterface $clock,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(RevokeApiKeyCommand $command): void
    {
        $key = $command->key;
        if ($key->isRevoked()) {
            throw new DomainErrors(['key' => 'oauth.api_key.error.already_revoked']);
        }

        $key->revokedAt = $this->clock->now();
        $this->em->flush();

        $keyId = (string) $key->id;
        $this->auditor->record(
            'oauth.api_key_revoked',
            AuditOutcome::Success,
            ['keyId' => $keyId, 'revokedBy' => $command->actor->id?->equals($key->owner->id) ? 'owner' : 'admin'],
            new AuditSubject('api_key', $keyId),
        );
    }
}
