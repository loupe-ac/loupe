<?php

declare(strict_types=1);

namespace App\Module\Board\Controller\Api;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

/** The one change a route writes on feedback. A missing status and an explicit null both fail NotBlank. */
#[OA\Schema(required: ['status'])]
final class FeedbackUpdateRequest
{
    public const string ADDRESSED = 'addressed';

    #[Assert\Choice(choices: [self::ADDRESSED])]
    #[Assert\NotBlank]
    #[OA\Property(description: 'The only status a route writes. Resolved stays with a person.', nullable: false, enum: [self::ADDRESSED])]
    public ?string $status = null;
}
