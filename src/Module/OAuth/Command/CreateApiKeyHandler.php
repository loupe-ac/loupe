<?php

declare(strict_types=1);

namespace App\Module\OAuth\Command;

use App\Exception\DomainErrors;
use App\Module\OAuth\Entity\ApiKey;
use App\Module\OAuth\Event\ApiKeyCreated;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

final readonly class CreateApiKeyHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private ClockInterface $clock,
        private EventDispatcherInterface $events,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(CreateApiKeyCommand $command): CreateApiKeyView
    {
        $name = trim($command->name);
        if ('' === $name || mb_strlen($name) > ApiKey::NAME_MAX_LENGTH) {
            throw new DomainErrors(['name' => 'oauth.api_key.error.name_length']);
        }

        $now = $this->clock->now();
        $secret = ApiKey::PREFIX.bin2hex(random_bytes(32));
        $key = new ApiKey(
            $command->owner,
            $name,
            ApiKey::hashOf($secret),
            ApiKey::displayStartOf($secret),
            $command->expiry->expiresAt($now),
            $now,
        );
        $this->em->persist($key);
        $this->em->flush();

        $keyId = (string) $key->id;
        $this->auditor->record(
            'oauth.api_key_created',
            AuditOutcome::Success,
            ['keyId' => $keyId, 'expiry' => $command->expiry->value],
            new AuditSubject('api_key', $keyId),
        );
        $this->events->dispatch(new ApiKeyCreated($key));

        return new CreateApiKeyView($key, $secret);
    }
}
