<?php

declare(strict_types=1);

namespace App\Api\Pagination;

use App\Api\Problem\ApiProblemException;
use Symfony\Component\HttpFoundation\Request;

/** The `sort` query value of a list that orders by creation time. */
final readonly class CreatedSort
{
    public const string OLDEST = 'createdAt';
    public const string NEWEST = '-createdAt';

    private function __construct(
        /** @var self::OLDEST|self::NEWEST */
        public string $value,
    ) {
    }

    public static function oldest(): self
    {
        return new self(self::OLDEST);
    }

    public function newestFirst(): bool
    {
        return self::NEWEST === $this->value;
    }

    /** @throws ApiProblemException `validation_failed` for any other value */
    public static function fromRequest(Request $request): self
    {
        $value = $request->query->all()['sort'] ?? self::OLDEST;

        return match ($value) {
            self::OLDEST => self::oldest(),
            self::NEWEST => new self(self::NEWEST),
            default => throw ApiProblemException::validationFailed([['field' => 'sort', 'code' => 'sort_invalid', 'message' => 'The sort must be createdAt or -createdAt.']]),
        };
    }
}
