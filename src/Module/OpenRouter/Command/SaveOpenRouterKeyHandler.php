<?php

declare(strict_types=1);

namespace App\Module\OpenRouter\Command;

use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\OpenRouter\Entity\OpenRouterKey;
use App\Module\OpenRouter\Repository\OpenRouterKeyRepository;
use App\Module\OpenRouter\Service\OpenRouterAnalytics;
use App\Module\OpenRouter\Service\OpenRouterKeyChangedPublisher;
use App\Module\OpenRouter\Service\OpenRouterProbeResult;
use Doctrine\ORM\EntityManagerInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/**
 * Tests the key with OpenRouter before it stores anything, so a key that fails
 * the test never replaces a key that works.
 */
final readonly class SaveOpenRouterKeyHandler
{
    public function __construct(
        private OpenRouterAnalytics $analytics,
        private OpenRouterKeyRepository $openRouterKeys,
        private EntityManagerInterface $em,
        private OpenRouterKeyChangedPublisher $changed,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(SaveOpenRouterKeyCommand $command): void
    {
        $secret = trim($command->secret);

        $error = match ($this->analytics->probe($secret)) {
            OpenRouterProbeResult::Ok => null,
            OpenRouterProbeResult::NotManagementKey => 'openrouter.error.not_management_key',
            OpenRouterProbeResult::Refused => 'openrouter.error.key_refused',
            OpenRouterProbeResult::Unreachable => 'openrouter.error.unreachable',
        };
        if (null !== $error) {
            throw new DomainErrors(['secret' => $error]);
        }

        $owner = $command->owner;
        $ownerId = $owner instanceof User
            ? $owner->id ?? throw new \LogicException('A stored user has an id.') : $owner->requireId();

        $key = $this->em->wrapInTransaction(function () use ($owner, $ownerId, $secret): OpenRouterKey {
            $owner instanceof User ? $this->openRouterKeys->deleteForUser($ownerId) : $this->openRouterKeys->deleteForProject($ownerId);

            $key = $owner instanceof User ? new OpenRouterKey($secret, user: $owner) : new OpenRouterKey($secret, project: $owner);
            $this->em->persist($key);

            return $key;
        });

        $this->changed->changed($owner);
        $this->auditor->record(
            'openrouter.key_saved',
            AuditOutcome::Success,
            ['keyId' => (string) $key->id, 'scope' => $owner instanceof User ? 'account' : 'project', 'ownerId' => (string) $ownerId],
            new AuditSubject('openrouter_key', (string) $key->id),
        );
    }
}
