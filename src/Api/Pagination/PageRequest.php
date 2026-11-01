<?php

declare(strict_types=1);

namespace App\Api\Pagination;

use App\Api\Problem\ApiProblemException;
use Symfony\Component\HttpFoundation\Request;

/** The `limit` and `cursor` of a list request, checked against the filters and the sort the route uses. */
final readonly class PageRequest
{
    public const int DEFAULT_LIMIT = 50;

    public const int MAX_LIMIT = 200;

    public function __construct(
        public int $limit,
        public ?Cursor $cursor,
    ) {
    }

    /**
     * @param array<string, scalar|null> $filters
     */
    public static function fromRequest(Request $request, string $sort, array $filters = []): self
    {
        $limit = self::DEFAULT_LIMIT;
        $rawLimit = $request->query->get('limit');
        if (null !== $rawLimit) {
            $parsed = filter_var($rawLimit, \FILTER_VALIDATE_INT);
            if (false === $parsed || $parsed < 1 || $parsed > self::MAX_LIMIT) {
                throw ApiProblemException::validationFailed([['field' => 'limit', 'code' => 'limit_out_of_range', 'message' => sprintf('The limit must be a whole number from 1 to %d.', self::MAX_LIMIT)]]);
            }
            $limit = $parsed;
        }

        $token = $request->query->get('cursor');
        $cursor = \is_string($token) && '' !== $token ? Cursor::decode($token, $sort, $filters) : null;

        return new self($limit, $cursor);
    }

    /** One row more than the page holds, so the page knows whether a next page exists. */
    public function fetchSize(): int
    {
        return $this->limit + 1;
    }
}
