<?php

declare(strict_types=1);

namespace App\Api\OpenApi;

use OpenApi\Analysis;
use OpenApi\Annotations\Schema;
use OpenApi\Generator;

/**
 * Makes each field of a partial change optional in the spec. Such a request has
 * typed properties with no default, so a field left out stays uninitialized and an
 * explicit null fails the type. The generator marks those properties as required
 * and has no switch to stop it. A request class asks for this processor with
 * `#[OA\Schema(x: [PartialChangeSchemaProcessor::MARKER => true])]`, and the marker
 * stays out of the spec.
 */
final class PartialChangeSchemaProcessor
{
    public const string MARKER = 'partial-change';

    public function __invoke(Analysis $analysis): void
    {
        $schemas = $analysis->openapi?->components->schemas ?? [];
        if (Generator::isDefault($schemas)) {
            return;
        }

        foreach ($schemas as $schema) {
            if (Generator::isDefault($schema->x) || !isset($schema->x[self::MARKER])) {
                continue;
            }

            self::clear($schema, 'required');
            unset($schema->x[self::MARKER]);
        }
    }

    /** An empty list is not valid OpenAPI 3.0, so a property goes back to the unset value of the library. */
    private static function clear(Schema $schema, string $property): void
    {
        $schema->{$property} = Generator::UNDEFINED;
    }
}
