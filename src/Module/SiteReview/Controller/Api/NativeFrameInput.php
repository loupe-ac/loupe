<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Controller\Api;

use Symfony\Component\Validator\Constraints as Assert;

/** A box in the points of the simulator window. */
final class NativeFrameInput
{
    public function __construct(
        #[Assert\NotNull]
        #[Assert\Range(min: -100000, max: 100000)]
        public ?float $x = null,

        #[Assert\NotNull]
        #[Assert\Range(min: -100000, max: 100000)]
        public ?float $y = null,

        #[Assert\NotNull]
        #[Assert\Range(min: 0, max: 100000)]
        public ?float $width = null,

        #[Assert\NotNull]
        #[Assert\Range(min: 0, max: 100000)]
        public ?float $height = null,
    ) {
    }
}
