<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use App\Module\Bridge\ValueObject\SkippedWorkRequest;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/** An offered work request that the bridge skipped, because the account it matched is blocked. */
final class BridgeSkippedWorkInput
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Uuid]
        public ?string $workRequestId = null,

        #[Assert\NotBlank]
        #[Assert\Regex(pattern: BridgeAccountInput::NAME_PATTERN)]
        public ?string $account = null,
    ) {
    }

    public function skipped(): SkippedWorkRequest
    {
        return new SkippedWorkRequest(
            Uuid::fromString($this->workRequestId ?? throw new \LogicException('workRequestId is required after validation.')),
            $this->account ?? throw new \LogicException('account is required after validation.'),
        );
    }
}
