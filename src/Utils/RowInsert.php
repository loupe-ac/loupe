<?php

declare(strict_types=1);

namespace App\Utils;

/**
 * A row that a live page shows now and the reader's screen lacks.
 *
 * @template T of object
 */
final readonly class RowInsert
{
    /**
     * @param T       $item     the row to draw
     * @param ?string $beforeId the id of the row that follows it on the page, or null when it is last
     */
    public function __construct(
        public object $item,
        public ?string $beforeId,
    ) {
    }
}
