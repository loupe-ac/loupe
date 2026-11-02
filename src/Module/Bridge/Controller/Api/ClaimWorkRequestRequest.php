<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use OpenApi\Attributes as OA;

/**
 * The claim of a bridge for one work request. The field carries no constraint
 * and accepts any JSON value, because a validation failure answers without the
 * error code that the bridge reads. A missing or malformed account reads as no
 * account, which the claim refuses with account_unknown.
 */
final class ClaimWorkRequestRequest
{
    public function __construct(
        #[OA\Property(description: 'The account the bridge chose for the request.', type: 'string', nullable: true)]
        public mixed $account = null,
    ) {
    }

    /** The account the bridge chose for the request, or null when the body names none that could be an account. */
    public function account(): ?string
    {
        return \is_string($this->account) && 1 === preg_match(BridgeAccountInput::NAME_PATTERN, $this->account) ? $this->account : null;
    }
}
