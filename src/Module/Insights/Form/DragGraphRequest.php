<?php

declare(strict_types=1);

namespace App\Module\Insights\Form;

use Symfony\Component\Validator\Constraints as Assert;

class DragGraphRequest
{
    public const int MAX_STEPS = 1000;

    public function __construct(
        /** Places to move: negative moves up, positive moves down. */
        #[Assert\NotNull]
        #[Assert\Range(min: -self::MAX_STEPS, max: self::MAX_STEPS)]
        public ?int $steps = null,
    ) {
    }
}
