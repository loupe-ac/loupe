<?php

declare(strict_types=1);

namespace App\Api\OpenApi;

use OpenApi\Analysis;
use OpenApi\Annotations\Schema;
use OpenApi\Generator;

/**
 * Builds the partial component of a resource that a read can narrow with `fields`. The
 * partial holds the properties of the whole component and requires none, so the copy
 * cannot drift. A response class asks for it with
 * `#[OA\Schema(x: [FieldsSchemaProcessor::MARKER => self::PARTIAL])]`, where the `PARTIAL`
 * constant of the class names the new component. The marker stays out of the spec.
 */
final class FieldsSchemaProcessor
{
    public const string MARKER = 'fields-partial';

    public function __invoke(Analysis $analysis): void
    {
        $openapi = $analysis->openapi;
        $schemas = $openapi?->components->schemas ?? [];
        if (null === $openapi || Generator::isDefault($schemas)) {
            return;
        }

        $partials = [];
        foreach ($schemas as $schema) {
            if (Generator::isDefault($schema->x) || !isset($schema->x[self::MARKER])) {
                continue;
            }

            $name = $schema->x[self::MARKER];
            unset($schema->x[self::MARKER]);

            $partial = clone $schema;
            $partial->schema = $name;
            $partial->description = 'Some of the fields of `'.$schema->schema.'`, each one whole. A field that the request does not name is absent.';
            self::clear($partial, 'required');
            $partials[] = $partial;
        }

        if ([] !== $partials) {
            $openapi->components->schemas = [...$schemas, ...$partials];
        }
    }

    /** An empty list is not valid OpenAPI 3.0, so a property goes back to the unset value of the library. */
    private static function clear(Schema $schema, string $property): void
    {
        $schema->{$property} = Generator::UNDEFINED;
    }
}
