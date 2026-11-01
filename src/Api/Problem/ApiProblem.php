<?php

declare(strict_types=1);

namespace App\Api\Problem;

/**
 * One refusal of the HTTP API, before it has a wire form. A controller throws
 * it inside an ApiProblemException, and the exception listener renders it as
 * Problem JSON on /api/v1 and as the old flat error on an old path.
 */
final readonly class ApiProblem
{
    public const string TYPE_BASE = 'https://loupe.ac/docs/reference/api/errors#';

    /**
     * @param array<string, mixed>                                      $extras  keys beside the standard members, such as `versions` or `subscribeUrl`
     * @param list<array{field: string, code: string, message: string}> $errors  the refused fields of a 422
     * @param array<string, string>                                     $headers response headers, such as Retry-After
     */
    public function __construct(
        public int $status,
        public string $code,
        public string $title,
        public string $detail,
        public array $extras = [],
        public array $errors = [],
        public array $headers = [],
    ) {
    }

    /** @return array<string, mixed> */
    public function toProblemBody(): array
    {
        $body = [
            'type' => self::TYPE_BASE.$this->code,
            'title' => $this->title,
            'status' => $this->status,
            'code' => $this->code,
            'detail' => $this->detail,
        ];
        if ([] !== $this->errors) {
            $body['errors'] = $this->errors;
        }

        return $body + $this->extras;
    }

    /**
     * The body an old path answers: the flat `error` key and the real extras. Two codes
     * keep their old names, so a client that reads them does not notice the rewrite.
     *
     * @return array<string, mixed>
     */
    public function toLegacyBody(): array
    {
        $legacy = match ($this->code) {
            'credential_not_allowed' => 'insufficient_scope',
            'invalid_credentials' => 'unauthorized',
            default => $this->code,
        };

        $body = ['error' => $legacy];
        if ([] !== $this->errors) {
            $body['errors'] = $this->errors;
        }

        return $body + $this->extras;
    }
}
