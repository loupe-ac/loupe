<?php

declare(strict_types=1);

namespace App\Api\OpenApi;

use OpenApi\Attributes as OA;

/** The pieces that every response of a public route shares. */
final readonly class PublicResponses
{
    /**
     * The answers any signed-in public route can give. An operation lists its own
     * answers first, and its list wins for a status both name.
     *
     * @param list<OA\Response> $own
     *
     * @return list<OA\Response>
     */
    public static function withStandard(array $own): array
    {
        $given = array_map(static fn (OA\Response $response): string => (string) $response->response, $own);
        $standard = [
            new ProblemResponse(401, ['invalid_credentials', 'api_key_expired']),
            new ProblemResponse(402, ['subscription_required']),
            new ProblemResponse(403, ['credential_not_allowed']),
            new ProblemResponse(404, ['route_not_found']),
            new ProblemResponse(405, ['method_not_allowed']),
            new ProblemResponse(429, ['rate_limited']),
        ];

        return [...$own, ...array_values(array_filter($standard, static fn (OA\Response $response): bool => !\in_array((string) $response->response, $given, true)))];
    }

    /**
     * A reference to a header component of the spec, such as `Link` or `RateLimit-Limit`.
     *
     * @return array{OA\Header}
     */
    public static function header(string $name): array
    {
        return [new OA\Header(ref: '#/components/headers/'.$name, header: $name)];
    }

    /** @return list<OA\Header> */
    public static function rateLimitHeaders(): array
    {
        return [
            ...self::header('RateLimit-Limit'),
            ...self::header('RateLimit-Remaining'),
            ...self::header('RateLimit-Reset'),
        ];
    }
}
