<?php

declare(strict_types=1);

namespace App\Module\Insights\Service;

use App\Api\Pagination\Cursor;
use App\Api\Problem\ApiProblemException;
use Symfony\Component\Uid\Uuid;

/** The cursor of a list that walks in order of position, then id. */
final class PositionCursor
{
    public const string SORT = 'position';

    /** @param array<string, scalar|null> $filters */
    public static function after(int $position, Uuid $id, array $filters = []): Cursor
    {
        return new Cursor(self::SORT, (string) $position, (string) $id, $filters);
    }

    /**
     * @return array{int, string} the position and the id of the last row of the previous page
     *
     * @throws ApiProblemException `invalid_cursor` for a position that does not parse
     */
    public static function positionOf(Cursor $cursor): array
    {
        if (1 !== preg_match('/^-?\d{1,18}$/', $cursor->sortValue) || !Uuid::isValid($cursor->id)) {
            throw ApiProblemException::invalidCursor('The cursor is broken. Start the list again without it.');
        }

        return [(int) $cursor->sortValue, strtolower($cursor->id)];
    }

    /** Whether a row sorts after the position of a cursor. */
    public static function isAfter(int $position, Uuid $id, int $cursorPosition, string $cursorId): bool
    {
        return $position > $cursorPosition || ($position === $cursorPosition && strcmp((string) $id, $cursorId) > 0);
    }
}
