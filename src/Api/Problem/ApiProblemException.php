<?php

declare(strict_types=1);

namespace App\Api\Problem;

/** Thrown by an API controller or listener to refuse a request with a coded problem. */
final class ApiProblemException extends \RuntimeException
{
    public function __construct(
        public readonly ApiProblem $problem,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($problem->detail ?? $problem->title, $problem->status, $previous);
    }

    public static function invalidCursor(string $detail): self
    {
        return new self(new ApiProblem(400, 'invalid_cursor', 'Invalid cursor', $detail));
    }

    /** @param list<array{field: string, code: string, message: string}> $errors */
    public static function validationFailed(array $errors): self
    {
        return new self(new ApiProblem(422, 'validation_failed', 'Validation failed', 'One or more fields were refused.', errors: $errors));
    }

    /** @param array<string, string> $headers */
    public static function rateLimited(int $retryAfterSeconds, array $headers = []): self
    {
        return new self(new ApiProblem(
            429,
            'rate_limited',
            'Rate limited',
            'The request budget of this window is spent.',
            headers: ['Retry-After' => (string) $retryAfterSeconds] + $headers,
        ));
    }
}
