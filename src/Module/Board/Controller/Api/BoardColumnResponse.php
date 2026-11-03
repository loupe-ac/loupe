<?php

declare(strict_types=1);

namespace App\Module\Board\Controller\Api;

use App\Api\OpenApi\FieldsSchemaProcessor;
use App\Module\Board\Entity\BoardColumn;
use OpenApi\Attributes as OA;

/** A column of a board, as the public API shows it. */
#[OA\Schema(required: ['slug', 'label', 'terminal', 'default', 'backlog'], x: [FieldsSchemaProcessor::MARKER => self::PARTIAL])]
final readonly class BoardColumnResponse
{
    /** The name of the partial component of this resource. */
    public const string PARTIAL = 'PartialBoardColumn';

    /** The top-level names of a column, in the order of its row. */
    public const array FIELDS = ['slug', 'label', 'terminal', 'default', 'backlog'];

    public function __construct(
        #[OA\Property(description: 'The value you pass as `status`.')]
        public string $slug,

        #[OA\Property(description: 'The name a person sees on the board.', maxLength: BoardColumn::MAX_LABEL_LENGTH)]
        public string $label,

        #[OA\Property(description: 'A terminal column is where finished work goes.')]
        public bool $terminal,

        #[OA\Property(description: 'True for the column where a new card lands when you pass no status.')]
        public bool $default,

        #[OA\Property(description: 'True for Backlog, which the board does not draw as a column.')]
        public bool $backlog,
    ) {
    }

    /** @param string $label the label as a person reads it, which a seeded column holds as a translation key */
    public static function fromColumn(BoardColumn $column, string $label): self
    {
        return new self($column->slug, $label, $column->terminal, $column->backlog, $column->backlog);
    }
}
