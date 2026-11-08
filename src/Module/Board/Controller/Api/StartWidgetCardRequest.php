<?php

declare(strict_types=1);

namespace App\Module\Board\Controller\Api;

use Symfony\Component\Validator\Constraints as Assert;

final class StartWidgetCardRequest
{
    public function __construct(
        #[Assert\NotBlank]
        public ?string $columnId = null,
    ) {
    }
}
