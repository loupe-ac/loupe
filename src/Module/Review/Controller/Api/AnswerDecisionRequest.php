<?php

declare(strict_types=1);

namespace App\Module\Review\Controller\Api;

use App\Api\OpenApi\PartialChangeSchemaProcessor;
use App\Module\Review\Entity\DecisionAnswer;
use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The answer of the owner to one decision. The handler checks the version, the indexes and the
 * length of the note, so a refusal of those carries its own code.
 */
#[OA\Schema(x: [PartialChangeSchemaProcessor::MARKER => ['versionNumber', 'optionIndexes']])]
final class AnswerDecisionRequest
{
    #[Assert\NotNull]
    #[Assert\Positive]
    #[OA\Property(description: 'The version you showed the owner.', nullable: false, minimum: 1)]
    public ?int $versionNumber = null;

    /** @var list<int>|null */
    #[Assert\All(constraints: [new Assert\Type('int'), new Assert\PositiveOrZero()])]
    #[Assert\Count(min: 1)]
    #[Assert\NotNull]
    #[OA\Property(description: 'The indexes of the options the owner chose, from 0.', type: 'array', items: new OA\Items(type: 'integer', minimum: 0), nullable: false, minItems: 1)]
    public ?array $optionIndexes = null;

    #[OA\Property(description: 'The note of the owner. Leave it out to keep the stored note, and pass an empty string to remove it.', nullable: false, maxLength: DecisionAnswer::MAX_NOTE_LENGTH)]
    public ?string $note = null;
}
