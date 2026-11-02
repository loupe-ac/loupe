<?php

declare(strict_types=1);

namespace App\Module\Board\Controller\Api;

use App\Module\Board\Entity\BoardColumn;
use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A new column. The handler checks the slug that comes from the label. A missing
 * label and an explicit null both fail NotBlank, so the type stays nullable and
 * the schema does not.
 */
#[OA\Schema(required: ['label'])]
final class CreateBoardColumnRequest
{
    #[Assert\Length(max: BoardColumn::MAX_LABEL_LENGTH)]
    #[Assert\NotBlank(normalizer: 'trim')]
    #[OA\Property(description: 'The slug comes from the label. The new column goes last, and it is not terminal.', nullable: false, maxLength: BoardColumn::MAX_LABEL_LENGTH, minLength: 1)]
    public ?string $label = null;
}
