<?php

declare(strict_types=1);

namespace App\Api\OpenApi;

use OpenApi\Attributes as OA;

/** The 429 of a route with no budget headers, such as a private route or a route with no sign-in. */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class RateLimitedResponse extends OA\Response
{
    public function __construct()
    {
        parent::__construct(
            response: 429,
            description: 'Refused: `rate_limited`.',
            headers: PublicResponses::header('Retry-After'),
            content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem')),
        );
    }
}
