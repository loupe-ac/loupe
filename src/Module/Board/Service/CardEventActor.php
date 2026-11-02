<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Account\Entity\User;
use App\Module\Workflow\Contract\Actor;
use App\Security\ApiKeyActor;
use App\Security\CurrentApiKeyActor;
use Symfony\Bundle\SecurityBundle\Security;

/** The account and the API key a history row names beside its actor kind. */
final readonly class CardEventActor
{
    public function __construct(
        private Security $security,
        private CurrentApiKeyActor $currentKey,
    ) {
    }

    /** A reviewer and the app act as no account, whoever holds the session. */
    public function userFor(Actor $actor): ?User
    {
        if (Actor::Human !== $actor && Actor::Agent !== $actor && Actor::ApiKey !== $actor) {
            return null;
        }
        $user = $this->security->getUser();

        return $user instanceof User ? $user : null;
    }

    public function apiKeyFor(Actor $actor): ?ApiKeyActor
    {
        return Actor::ApiKey === $actor ? $this->currentKey->current() : null;
    }
}
