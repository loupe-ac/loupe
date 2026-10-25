<?php

declare(strict_types=1);

namespace App\Module\Notification\Form;

use Symfony\Component\Validator\Constraints as Assert;

/** The subscription a browser hands over. The handler checks that each field is present and well formed. */
class RegisterPushDeviceRequest
{
    public function __construct(
        #[Assert\Length(max: 2048)]
        public ?string $endpoint = null,

        #[Assert\Length(max: 512)]
        public ?string $publicKey = null,

        #[Assert\Length(max: 512)]
        public ?string $authToken = null,

        #[Assert\Length(max: 20)]
        public ?string $contentEncoding = null,

        #[Assert\Length(max: 500)]
        public ?string $label = null,
    ) {
    }
}
