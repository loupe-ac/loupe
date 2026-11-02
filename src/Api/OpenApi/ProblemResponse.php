<?php

declare(strict_types=1);

namespace App\Api\OpenApi;

use OpenApi\Attributes as OA;

/** A refusal of a public route, as Problem JSON. The description names the codes it can carry. */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class ProblemResponse extends OA\Response
{
    /**
     * @param non-empty-list<string> $codes  the `code` values this status can carry
     * @param string                 $schema the component that describes the body
     */
    public function __construct(int $response, array $codes, string $schema = 'Problem')
    {
        parent::__construct(
            response: $response,
            description: 'Refused: '.implode(', ', array_map(static fn (string $code): string => '`'.$code.'`', $codes)).'.',
            headers: PublicResponses::rateLimitHeaders(),
            content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/'.$schema)),
        );
    }
}
