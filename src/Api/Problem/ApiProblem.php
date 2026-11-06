<?php

declare(strict_types=1);

namespace App\Api\Problem;

use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * One refusal of the HTTP API, before it has a wire form. A controller throws
 * it inside an ApiProblemException, and the exception listener renders it as
 * Problem JSON.
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
     * The body the MCP endpoint answers: the flat `error` key and the real extras.
     * Two codes keep the names that MCP clients read.
     *
     * @return array<string, mixed>
     */
    public function toFlatBody(): array
    {
        $error = match ($this->code) {
            'credential_not_allowed' => 'insufficient_scope',
            'invalid_credentials' => 'unauthorized',
            default => $this->code,
        };

        return ['error' => $error] + $this->extras;
    }

    /** Problem JSON on an /api path, the flat body on any other, such as /mcp. */
    public function toResponse(string $path): JsonResponse
    {
        $problemJson = ApiPaths::isApi($path);
        $response = new JsonResponse($problemJson ? $this->toProblemBody() : $this->toFlatBody(), $this->status);
        if ($problemJson) {
            $response->headers->set('Content-Type', 'application/problem+json');
        }
        foreach ($this->headers as $name => $value) {
            $response->headers->set($name, $value);
        }

        return $response;
    }
}
