<?php

declare(strict_types=1);

namespace App\Module\Board\Controller\Api;

use App\Module\Board\Entity\BoardColumn;
use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

/** A partial change of a column. A field left out, or sent as null, keeps its value. */
#[OA\Schema(description: 'A field you leave out keeps its value.')]
final readonly class UpdateBoardColumnRequest
{
    public function __construct(
        #[Assert\Length(min: 1, max: BoardColumn::MAX_LABEL_LENGTH)]
        #[OA\Property(description: 'A new name. The slug changes with it, so a bridge rule or a script that uses the old slug stops working.', maxLength: BoardColumn::MAX_LABEL_LENGTH, minLength: 1)]
        public ?string $label = null,

        #[OA\Property(description: 'Whether finished work goes in this column.')]
        public ?bool $terminal = null,
    ) {
    }
}
