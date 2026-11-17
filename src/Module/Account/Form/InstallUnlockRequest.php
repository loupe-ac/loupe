<?php

declare(strict_types=1);

namespace App\Module\Account\Form;

use Symfony\Component\Validator\Constraints as Assert;

class InstallUnlockRequest
{
    public function __construct(
        #[Assert\NotBlank]
        public ?string $token = null,
    ) {
    }
}
