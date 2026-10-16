<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use Symfony\Component\Validator\Constraints as Assert;

/** One parameter of a command entry, and the pattern that its value must match whole. */
final class BridgeCommandParamInput
{
    public const int MAX_NAME_LENGTH = 100;

    public const int MAX_PATTERN_LENGTH = 500;

    public const int MAX_DESCRIPTION_LENGTH = 500;

    public function __construct(
        #[Assert\Length(max: self::MAX_NAME_LENGTH)]
        #[Assert\NotBlank]
        public ?string $name = null,

        #[Assert\Length(max: self::MAX_PATTERN_LENGTH)]
        #[Assert\NotBlank]
        public ?string $pattern = null,

        #[Assert\Length(max: self::MAX_DESCRIPTION_LENGTH)]
        public ?string $description = null,
    ) {
    }
}
