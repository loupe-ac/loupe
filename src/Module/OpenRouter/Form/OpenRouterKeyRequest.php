<?php

declare(strict_types=1);

namespace App\Module\OpenRouter\Form;

use Symfony\Component\Validator\Constraints as Assert;

class OpenRouterKeyRequest
{
    public function __construct(
        #[Assert\Length(max: 255, normalizer: 'trim')]
        #[Assert\NotBlank(normalizer: 'trim')]
        public ?string $secret = null,
    ) {
    }
}
