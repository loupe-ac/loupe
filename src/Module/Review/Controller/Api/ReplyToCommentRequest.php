<?php

declare(strict_types=1);

namespace App\Module\Review\Controller\Api;

use App\Api\OpenApi\PartialChangeSchemaProcessor;
use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

/** A blank body passes here, so the handler refuses it with its own code. */
#[OA\Schema(x: [PartialChangeSchemaProcessor::MARKER => ['body']])]
final class ReplyToCommentRequest
{
    #[Assert\NotNull]
    #[OA\Property(description: 'The reply text. At most 65536 bytes. The reply is attributed to the agent.', nullable: false, minLength: 1)]
    public ?string $body = null;
}
