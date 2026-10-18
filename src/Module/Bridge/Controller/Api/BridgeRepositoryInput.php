<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use Symfony\Component\Validator\Constraints as Assert;

/** The repository folder of one followed project on the machine of a bridge. */
final class BridgeRepositoryInput
{
    public const int MAX_PATH_LENGTH = 4096;

    public const int MAX_REMOTE_URL_LENGTH = 2048;

    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Uuid]
        public ?string $projectId = null,

        #[Assert\Length(max: self::MAX_PATH_LENGTH)]
        #[Assert\NotBlank]
        public ?string $path = null,

        #[Assert\Length(max: self::MAX_REMOTE_URL_LENGTH)]
        public ?string $remoteUrl = null,
    ) {
    }
}
