<?php

declare(strict_types=1);

namespace App\Api\Pagination;

use App\Api\Problem\ApiProblemException;

/**
 * Where a list walk stands: the sort value and the id of the last row of a page.
 *
 * A cursor is unsigned base64url JSON. It also names the filters and the sort it was
 * made for, so a walk that changes either one is refused instead of skipping rows.
 * The query still runs under the owner voters, so a forged cursor can only skip rows
 * the caller may read.
 */
final readonly class Cursor
{
    /**
     * @param array<string, scalar|null> $filters
     */
    public function __construct(
        public string $sort,
        public string $sortValue,
        public string $id,
        public array $filters = [],
    ) {
    }

    public function encode(): string
    {
        $json = json_encode(['s' => $this->sort, 'v' => $this->sortValue, 'i' => $this->id, 'f' => self::normalise($this->filters)], \JSON_THROW_ON_ERROR);

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    /**
     * @param array<string, scalar|null> $filters the filters of the current request
     *
     * @throws ApiProblemException `invalid_cursor` for a broken cursor, or one made for other filters or another sort
     */
    public static function decode(string $token, string $sort, array $filters = []): self
    {
        $json = base64_decode(strtr($token, '-_', '+/'), true);
        $data = false === $json ? null : json_decode($json, true);

        if (!\is_array($data) || !\is_string($data['s'] ?? null) || !\is_string($data['v'] ?? null) || !\is_string($data['i'] ?? null) || !\is_array($data['f'] ?? null)) {
            throw ApiProblemException::invalidCursor('The cursor is broken. Start the list again without it.');
        }

        if ($data['s'] !== $sort || $data['f'] !== self::normalise($filters)) {
            throw ApiProblemException::invalidCursor('The filters or the sort changed since this cursor was made. Start the list again without it.');
        }

        /** @var array<string, scalar|null> $cursorFilters */
        $cursorFilters = $data['f'];

        return new self($data['s'], $data['v'], $data['i'], $cursorFilters);
    }

    /**
     * @param array<string, scalar|null> $filters
     *
     * @return array<string, scalar|null>
     */
    private static function normalise(array $filters): array
    {
        $kept = array_filter($filters, static fn (mixed $value): bool => null !== $value);
        ksort($kept);

        return $kept;
    }
}
