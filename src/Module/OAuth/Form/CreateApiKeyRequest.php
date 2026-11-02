<?php

declare(strict_types=1);

namespace App\Module\OAuth\Form;

use App\Module\OAuth\Entity\ApiKey;
use App\Module\OAuth\Entity\ApiKeyExpiry;
use Symfony\Component\Validator\Constraints as Assert;

class CreateApiKeyRequest
{
    public function __construct(
        #[Assert\Length(max: ApiKey::NAME_MAX_LENGTH)]
        #[Assert\NotBlank]
        public ?string $name = null,
        public ApiKeyExpiry $expiry = ApiKeyExpiry::NinetyDays,
    ) {
    }
}
