<?php

declare(strict_types=1);

namespace App\Api\Pagination;

use App\Api\Problem\ApiProblemException;
use Symfony\Component\Uid\Uuid;

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
    private const string TIME_FORMAT = 'Y-m-d H:i:s.u';

    private const string NO_RUN = 'none';

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

    /**
     * The cursor that resumes after a row of a list that sorts on its creation time.
     *
     * @param array<string, scalar|null> $filters
     */
    public static function afterCreated(string $sort, \DateTimeImmutable $createdAt, Uuid $id, array $filters = []): self
    {
        return new self($sort, $createdAt->format(self::TIME_FORMAT), (string) $id, $filters);
    }

    /**
     * The cursor that resumes after a row of a list that sorts on the time of a last run. A row with no run holds the sort value `none`.
     *
     * @param array<string, scalar|null> $filters
     */
    public static function afterLastRun(string $sort, ?\DateTimeImmutable $lastRunAt, Uuid $id, array $filters = []): self
    {
        return new self($sort, null === $lastRunAt ? self::NO_RUN : $lastRunAt->format(self::TIME_FORMAT), (string) $id, $filters);
    }

    /**
     * The cursor that resumes after a row of a list that sorts on the time of a last run, then on a name. A row with no run holds the sort value `none`.
     *
     * @param array<string, scalar|null> $filters
     */
    public static function afterLastRunName(string $sort, ?\DateTimeImmutable $lastRunAt, string $name, array $filters = []): self
    {
        return new self($sort, null === $lastRunAt ? self::NO_RUN : $lastRunAt->format(self::TIME_FORMAT), $name, $filters);
    }

    /**
     * The cursor that resumes after a row of a list that sorts on a name.
     *
     * @param array<string, scalar|null> $filters
     */
    public static function afterName(string $sort, string $name, array $filters = []): self
    {
        return new self($sort, $name, '', $filters);
    }

    /**
     * @return array{?\DateTimeImmutable, string} the time of the last run, null for a row with no run, and the name of the last row of the previous page
     *
     * @throws ApiProblemException `invalid_cursor` for a position that does not parse
     */
    public function lastRunNamePosition(): array
    {
        $at = null;
        if (self::NO_RUN !== $this->sortValue) {
            $at = \DateTimeImmutable::createFromFormat(self::TIME_FORMAT, $this->sortValue);
            if (false === $at) {
                throw ApiProblemException::invalidCursor('The cursor is broken. Start the list again without it.');
            }
        }

        return [$at, $this->id];
    }

    /** The name of the last row of the previous page of a list that sorts on a name. */
    public function namePosition(): string
    {
        return $this->sortValue;
    }

    /**
     * @return array{?\DateTimeImmutable, Uuid} the time of the last run, null for a row with no run, and the id of the last row of the previous page
     *
     * @throws ApiProblemException `invalid_cursor` for a position that does not parse
     */
    public function lastRunPosition(): array
    {
        $at = null;
        if (self::NO_RUN !== $this->sortValue) {
            $at = \DateTimeImmutable::createFromFormat(self::TIME_FORMAT, $this->sortValue);
            if (false === $at) {
                throw ApiProblemException::invalidCursor('The cursor is broken. Start the list again without it.');
            }
        }
        if (!Uuid::isValid($this->id)) {
            throw ApiProblemException::invalidCursor('The cursor is broken. Start the list again without it.');
        }

        return [$at, Uuid::fromString($this->id)];
    }

    /**
     * @return array{\DateTimeImmutable, Uuid} the creation time and the id of the last row of the previous page
     *
     * @throws ApiProblemException `invalid_cursor` for a position that does not parse
     */
    public function createdPosition(): array
    {
        $at = \DateTimeImmutable::createFromFormat(self::TIME_FORMAT, $this->sortValue);
        if (false === $at || !Uuid::isValid($this->id)) {
            throw ApiProblemException::invalidCursor('The cursor is broken. Start the list again without it.');
        }

        return [$at, Uuid::fromString($this->id)];
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
