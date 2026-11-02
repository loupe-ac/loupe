<?php

declare(strict_types=1);

namespace App\Module\Board\Controller\Api;

use App\Module\Board\Entity\BoardColumn;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A partial change of a column. A field left out stays uninitialized and keeps
 * its value. An explicit null fails the property type, so it answers 422.
 *
 * The generator marks a typed property with no default as required, so the
 * schema `ColumnUpdate` lives in config/packages/nelmio_api_doc.yaml.
 */
final class UpdateBoardColumnRequest
{
    #[Assert\Length(min: 1, max: BoardColumn::MAX_LABEL_LENGTH)]
    public string $label;

    public bool $terminal;
}
