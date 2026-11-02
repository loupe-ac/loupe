<?php

declare(strict_types=1);

namespace App\Api\OpenApi;

use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/** A success answer of a public route, with the budget headers and the optional `Link` or `Location`. */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class SuccessResponse extends OA\Response
{
    /**
     * @param class-string $type       the response class that describes the body. The `models.names` list of nelmio_api_doc.yaml names its component.
     * @param bool         $page       true for a list, which names its next page in a `Link` header
     * @param bool         $location   true for a create, which names the new resource in a `Location` header
     * @param bool         $narrowable true for a read that takes `fields`. The answer is the whole resource or its partial, and the `PARTIAL` constant of `$type` names the partial.
     */
    public function __construct(int $response, string $description, string $type, bool $page = false, bool $location = false, bool $narrowable = false)
    {
        $headers = PublicResponses::rateLimitHeaders();
        if ($page) {
            $headers = [...$headers, ...PublicResponses::header('Link')];
        }
        if ($location) {
            $headers = [...$headers, ...PublicResponses::header('Location')];
        }

        parent::__construct(
            response: $response,
            description: $description,
            headers: $headers,
            content: $narrowable
                ? new OA\JsonContent(anyOf: [new OA\Schema(ref: new Model(type: $type)), new OA\Schema(ref: '#/components/schemas/'.\constant($type.'::PARTIAL'))])
                : new OA\JsonContent(ref: new Model(type: $type)),
        );
    }
}
