<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use Symfony\Component\Validator\Constraints as Assert;

/** A project an account failed its check for, while the account stays ready for the others. */
final class BridgeFailedProjectInput
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Uuid]
        public ?string $projectId = null,

        #[Assert\Length(max: BridgeAccountInput::MAX_REASON_LENGTH, normalizer: 'trim')]
        #[Assert\NotBlank(normalizer: 'trim')]
        public ?string $reason = null,
    ) {
    }
}
