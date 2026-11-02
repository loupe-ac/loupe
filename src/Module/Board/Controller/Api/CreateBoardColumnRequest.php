<?php

declare(strict_types=1);

namespace App\Module\Board\Controller\Api;

use App\Module\Board\Entity\BoardColumn;
use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

/** A new column. The handler checks the slug that comes from the label. */
#[OA\Schema(required: ['label'])]
final readonly class CreateBoardColumnRequest
{
    public function __construct(
        #[Assert\Length(max: BoardColumn::MAX_LABEL_LENGTH)]
        #[Assert\NotBlank(normalizer: 'trim')]
        #[OA\Property(description: 'The slug comes from the label. The new column goes last, and it is not terminal.', maxLength: BoardColumn::MAX_LABEL_LENGTH, minLength: 1)]
        public ?string $label = null,
    ) {
    }
}
