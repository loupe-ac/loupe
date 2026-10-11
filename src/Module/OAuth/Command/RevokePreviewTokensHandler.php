<?php

declare(strict_types=1);

namespace App\Module\OAuth\Command;

use App\Exception\DomainErrors;
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
    ) {
    }

    /** @throws DomainErrors when a revoke by label names a project the owner does not have */
    public function __invoke(RevokePreviewTokensCommand $command): int
    {
        $projectId = null;
        if (null !== $command->label) {
            $owner = $command->owner ?? throw new \LogicException('a revoke by label names the owner.');
            $project = $this->projects->findOneByIdOrSlugForOwner($command->handle ?? throw new \LogicException('a revoke by label names the project.'), $owner)
                ?? throw new DomainErrors(['handle' => 'oauth.preview_token.error.project_not_found']);
            $projectId = $project->id ?? throw new \LogicException('a persisted project always has an id.');
            $revoked = $this->previewTokens->revokeByLabel(
                $owner->id ?? throw new \LogicException('a persisted user always has an id.'),
                $projectId,
                trim($command->label),
            );
            $by = 'label';
        } elseif (null !== $command->accessTokenId) {
            $revoked = $this->previewTokens->revokeByAccessTokenId($command->accessTokenId);
            $by = 'token';
        } elseif (null !== $command->cardId) {
            $revoked = $this->previewTokens->revokeByCard($command->cardId);
            $by = 'card';
        } else {
            throw new \LogicException('a revoke names a label, a token or a card.');
        }

        if ($revoked > 0) {
            $this->auditor->record(
                'oauth.preview_tokens_revoked',
                AuditOutcome::Success,
                ['by' => $by, 'count' => $revoked, 'cardId' => $command->cardId?->toRfc4122(), 'projectId' => $projectId?->toRfc4122()],
            );
        }

        return $revoked;
    }
}
