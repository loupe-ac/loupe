<?php

declare(strict_types=1);

namespace App\Api\OpenApi;

use OpenApi\Attributes as OA;

/** The `fields` query parameter of a read: a comma list of the top-level names of the resource, or `all`. */
final class FieldsParameter extends OA\Parameter
{
    /**
     * @param list<string> $fields  the top-level names of the resource
     * @param list<string> $refused the names the route refuses, `all` included when it refuses the whole resource
     */
    public function __construct(array $fields, array $refused = [])
    {
        parent::__construct(
            name: 'fields',
            description: 'The fields to return, as a comma list such as `number,title`. Each named field comes back whole, and nothing else does. `all` returns the whole resource and stands alone.',
            in: 'query',
            required: false,
            schema: new OA\Schema(type: 'array', items: new OA\Items(type: 'string', enum: array_values(array_diff([...$fields, 'all'], $refused)))),
            style: 'form',
            explode: false,
        );
    }
}
