<?php

declare(strict_types=1);

namespace App\Module\Notification\Service;

/** A key pair that passed validation, both keys in base64url as the library reads them. */
final readonly class VapidKeys
{
    public function __construct(
        public string $publicKey,

        #[\SensitiveParameter]
        public string $privateKey,
    ) {
    }
}
