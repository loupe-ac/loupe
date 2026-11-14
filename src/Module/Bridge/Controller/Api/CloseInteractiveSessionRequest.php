<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use OpenApi\Attributes as OA;
use Symfony\Component\Serializer\Attribute\Ignore;

/**
 * The optional body of the session close. The field accepts any JSON value,
 * because a validation failure answers without the error code that the CLI
 * reads. The controller checks it instead.
 */
#[OA\Schema]
final class CloseInteractiveSessionRequest
{
    public function __construct(
        #[OA\Property(description: 'True when the work of the session ended well. Any other value answers 422 `invalid_finished`.', type: 'boolean', default: false)]
        public mixed $finished = false,
    ) {
    }

    #[Ignore]
    public function isValid(): bool
    {
        return \is_bool($this->finished);
    }

    public function finished(): bool
    {
        return true === $this->finished;
    }
}
