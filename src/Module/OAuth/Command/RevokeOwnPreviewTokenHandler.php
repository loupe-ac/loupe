<?php

declare(strict_types=1);

namespace App\Module\OAuth\Command;

use App\Module\OAuth\Repository\PreviewTokenRepository;

/** Revokes one live preview token of the owner, and returns its row. A token that is not the owner's, or not live, gives null. */
final readonly class RevokeOwnPreviewTokenHandler
{
    public function __construct(
        private PreviewTokenRepository $previewTokens,
        private RevokePreviewTokensHandler $revokePreviewTokens,
    ) {
    }

    public function __invoke(RevokeOwnPreviewTokenCommand $command): ?PreviewTokenRow
    {
        $ownerId = $command->owner->id ?? throw new \LogicException('a persisted user always has an id.');
        $row = $this->previewTokens->findLiveForUserByAccessTokenId($ownerId, $command->accessTokenId);
        if (null === $row) {
            return null;
        }

        ($this->revokePreviewTokens)(new RevokePreviewTokensCommand(owner: $command->owner, accessTokenId: $row->accessTokenId));

        return $row;
    }
}
