<?php

declare(strict_types=1);

namespace App\Api\OpenApi;

use OpenApi\Attributes as OA;

/** A success answer of a public route whose body is a file, with the budget headers. */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class BinaryResponse extends OA\Response
{
    public function __construct(int $response, string $description)
    {
        parent::__construct(
            response: $response,
            description: $description,
            headers: PublicResponses::rateLimitHeaders(),
            content: new OA\MediaType(mediaType: '*/*', schema: new OA\Schema(type: 'string', format: 'binary')),
        );
    }
}
