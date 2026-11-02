<?php

declare(strict_types=1);

namespace App\Module\Review\Controller\Api;

use App\Api\OpenApi\PartialChangeSchemaProcessor;
use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

/** The only status a route writes. A person resolves a thread. */
#[OA\Schema(x: [PartialChangeSchemaProcessor::MARKER => ['status']])]
final class MarkCommentAddressedRequest
{
    #[Assert\EqualTo('addressed')]
    #[Assert\NotNull]
    #[OA\Property(description: 'The only status a route writes. Resolved stays with a person.', nullable: false, enum: ['addressed'])]
    public ?string $status = null;
}
