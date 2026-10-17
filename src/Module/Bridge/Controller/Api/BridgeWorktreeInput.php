<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use Symfony\Component\Validator\Constraints as Assert;

/** One worktree of a project folder on the machine of a bridge. */
final class BridgeWorktreeInput
{
    public const int MAX_BRANCH_LENGTH = 255;

    public const int MAX_PATH_LENGTH = 4096;

    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Uuid]
        public ?string $projectId = null,

        #[Assert\Length(max: self::MAX_BRANCH_LENGTH)]
        #[Assert\NotBlank]
        public ?string $branch = null,

        #[Assert\Length(max: self::MAX_PATH_LENGTH)]
        #[Assert\NotBlank]
        public ?string $path = null,
    ) {
    }
}
