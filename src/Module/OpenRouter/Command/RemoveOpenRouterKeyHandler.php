<?php

declare(strict_types=1);

namespace App\Module\OpenRouter\Command;

use App\Module\Account\Entity\User;
use App\Module\OpenRouter\Repository\OpenRouterKeyRepository;
use App\Module\OpenRouter\Service\OpenRouterKeyChangedPublisher;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/** Removing a key that does not exist is not an error. A second click on Remove must not fail. */
final readonly class RemoveOpenRouterKeyHandler
{
    public function __construct(
        private OpenRouterKeyRepository $openRouterKeys,
        private OpenRouterKeyChangedPublisher $changed,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(RemoveOpenRouterKeyCommand $command): void
    {
        $owner = $command->owner;
        $ownerId = $owner instanceof User
            ? $owner->id ?? throw new \LogicException('A stored user has an id.') : $owner->requireId();

        $removed = $owner instanceof User ? $this->openRouterKeys->deleteForUser($ownerId) : $this->openRouterKeys->deleteForProject($ownerId);
        if (0 === $removed) {
            return;
        }

        $this->changed->changed($owner);
        $scope = $owner instanceof User ? 'account' : 'project';
        $this->auditor->record(
            'openrouter.key_removed',
            AuditOutcome::Success,
            ['scope' => $scope, 'ownerId' => (string) $ownerId],
            new AuditSubject($scope, (string) $ownerId),
        );
    }
}
