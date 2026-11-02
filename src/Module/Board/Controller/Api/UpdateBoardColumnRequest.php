<?php

declare(strict_types=1);

namespace App\Module\Board\Controller\Api;

use App\Api\OpenApi\PartialChangeSchemaProcessor;
use App\Module\Board\Entity\BoardColumn;
use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A partial change of a column. A field left out stays uninitialized and keeps
 * its value. An explicit null fails the property type, so it answers 422.
 */
#[OA\Schema(description: 'A field you leave out keeps its value.', x: [PartialChangeSchemaProcessor::MARKER => true])]
final class UpdateBoardColumnRequest
{
    #[Assert\Length(min: 1, max: BoardColumn::MAX_LABEL_LENGTH)]
    #[OA\Property(description: 'A new name. The slug changes with it, so a bridge rule or a script that uses the old slug stops working.', maxLength: BoardColumn::MAX_LABEL_LENGTH, minLength: 1)]
    public string $label;

    #[OA\Property(description: 'Whether finished work goes in this column.')]
    public bool $terminal;
}
