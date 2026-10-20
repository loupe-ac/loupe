<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Controller\Api;

use Symfony\Component\Validator\Constraints as Assert;

/** The accessibility element a reviewer picked on a native screen. */
final class NativeElementInput
{
    public function __construct(
        #[Assert\Length(max: 200)]
        #[Assert\NotBlank(normalizer: 'trim')]
        public ?string $role = null,

        #[Assert\Length(max: 2000)]
        public ?string $label = null,

        #[Assert\Length(max: 2000)]
        public ?string $identifier = null,

        #[Assert\NotNull]
        #[Assert\Valid]
        public ?NativeFrameInput $frame = null,
    ) {
    }
}
