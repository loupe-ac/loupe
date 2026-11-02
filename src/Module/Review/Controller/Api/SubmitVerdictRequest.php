<?php

declare(strict_types=1);

namespace App\Module\Review\Controller\Api;

use App\Api\OpenApi\PartialChangeSchemaProcessor;
use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Call this only after the owner gave the verdict. The handler checks the version, the newest
 * verdict, the decisions and the note.
 */
#[OA\Schema(x: [PartialChangeSchemaProcessor::MARKER => ['versionNumber', 'verdict', 'expectedVerdictId']])]
final class SubmitVerdictRequest
{
    #[Assert\NotNull]
    #[Assert\Positive]
    #[OA\Property(description: 'The version you showed the owner.', nullable: false, minimum: 1)]
    public ?int $versionNumber = null;

    #[Assert\Choice(choices: ['approved', 'changes-requested'])]
    #[Assert\NotNull]
    #[OA\Property(nullable: false, enum: ['approved', 'changes-requested'])]
    public ?string $verdict = null;

    #[Assert\Uuid]
    #[OA\Property(description: 'The `verdictId` from the review. Pass null when that is null.', format: 'uuid', nullable: true)]
    public ?string $expectedVerdictId = null;

    #[OA\Property(description: 'The note of the owner.', nullable: false)]
    public ?string $note = null;
}
