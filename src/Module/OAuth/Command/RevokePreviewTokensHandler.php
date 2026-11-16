<?php

declare(strict_types=1);

namespace App\Module\OAuth\Command;

use App\Exception\DomainErrors;
use App\Mercure\AccountArea;
use App\Mercure\AccountChangedPublisher;
use App\Module\OAuth\Repository\PreviewTokenRepository;
use App\Module\Project\Repository\ProjectRepository;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;

/** Revokes preview tokens and returns how many it revoked. No match is not an error. */
final readonly class RevokePreviewTokensHandler
{
    public function __construct(
        private PreviewTokenRepository $previewTokens,
        private ProjectRepository $projects,
        private Auditor $auditor,
        private AccountChangedPublisher $accountChanged,
    ) {
    }

    /** @throws DomainErrors when a revoke by label names a project the owner does not have */
    public function __invoke(RevokePreviewTokensCommand $command): int
    {
        $projectId = null;
        $ownerIds = [];
        if (null !== $command->label) {
            $owner = $command->owner ?? throw new \LogicException('a revoke by label names the owner.');
            $project = $this->projects->findOneByIdOrSlugForOwner($command->handle ?? throw new \LogicException('a revoke by label names the project.'), $owner)
                ?? throw new DomainErrors(['handle' => 'oauth.preview_token.error.project_not_found']);
            $projectId = $project->id ?? throw new \LogicException('a persisted project always has an id.');
            $ownerId = $owner->id ?? throw new \LogicException('a persisted user always has an id.');
            $ownerIds = [$ownerId];
            $revoked = $this->previewTokens->revokeByLabel(
                $ownerId,
                $projectId,
                trim($command->label),
            );
            $by = 'label';
        } elseif (null !== $command->accessTokenId) {
            $owner = $command->owner ?? throw new \LogicException('a revoke by token names the owner.');
            $ownerId = $owner->id ?? throw new \LogicException('a persisted user always has an id.');
            $ownerIds = [$ownerId];
            $revoked = $this->previewTokens->revokeByAccessTokenId($command->accessTokenId, $ownerId);
            $by = 'token';
        } elseif (null !== $command->cardId) {
            $ownerIds = $this->previewTokens->findLiveOwnerIdsForCard($command->cardId);
            $revoked = $this->previewTokens->revokeByCard($command->cardId);
            $by = 'card';
        } else {
            throw new \LogicException('a revoke names a label, a token or a card.');
        }

        if ($revoked > 0) {
            foreach ($ownerIds as $ownerId) {
                $this->accountChanged->changed($ownerId, AccountArea::APPS);
            }
            $this->auditor->record(
                'oauth.preview_tokens_revoked',
                AuditOutcome::Success,
                ['by' => $by, 'count' => $revoked, 'cardId' => $command->cardId?->toRfc4122(), 'projectId' => $projectId?->toRfc4122()],
            );
        }

        return $revoked;
    }
}
