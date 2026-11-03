<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Assert;

/**
 * Validates an answer against a component of the committed public spec.
 *
 * OpenAPI 3.0 writes a null value as `nullable: true`, which a JSON Schema validator
 * ignores. The helper turns each nullable schema into a type list that holds `null`.
 */
final class PublicSpec
{
    private static ?object $components = null;

    /** @param array<mixed>|object $data */
    public static function assertMatches(string $component, array|object $data, string $message = ''): void
    {
        $error = self::errorOf($component, $data);

        Assert::assertNull($error, $message.' '.$component.': '.$error);
    }

    /** @param array<mixed>|object $data */
    public static function assertDoesNotMatch(string $component, array|object $data, string $message = ''): void
    {
        Assert::assertNotNull(self::errorOf($component, $data), $message.' The answer should not match '.$component.'.');
    }

    /** @return array<string, mixed> */
    public static function schema(string $component): array
    {
        $spec = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/openapi/public.json'), true, flags: \JSON_THROW_ON_ERROR);
        Assert::assertIsArray($spec);
        Assert::assertArrayHasKey($component, $spec['components']['schemas'], 'The public spec has no component '.$component.'.');

        return $spec['components']['schemas'][$component];
    }

    /** @param array<mixed>|object $data */
    private static function errorOf(string $component, array|object $data): ?string
    {
        $document = (object) ['$ref' => '#/components/schemas/'.$component, 'components' => self::components()];
        $result = new Validator()->validate(json_decode((string) json_encode($data, \JSON_THROW_ON_ERROR), false, flags: \JSON_THROW_ON_ERROR), $document);

        return $result->isValid() || null === $result->error() ? null : (string) json_encode(new ErrorFormatter()->format($result->error()));
    }

    private static function components(): object
    {
        $spec = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/openapi/public.json'), false, flags: \JSON_THROW_ON_ERROR);
        self::$components ??= self::nullable($spec->components);
        Assert::assertIsObject(self::$components);

        return self::$components;
    }

    private static function nullable(mixed $node): mixed
    {
        if (\is_array($node)) {
            return array_map(self::nullable(...), $node);
        }
        if (!\is_object($node)) {
            return $node;
        }

        $copy = new \stdClass();
        foreach (get_object_vars($node) as $key => $value) {
            $copy->{$key} = self::nullable($value);
        }
        if (true === ($copy->nullable ?? null)) {
            if (isset($copy->type) && \is_string($copy->type)) {
                $copy->type = [$copy->type, 'null'];
            }
            if (isset($copy->enum) && \is_array($copy->enum) && !\in_array(null, $copy->enum, true)) {
                $copy->enum[] = null;
            }
            if (isset($copy->{'$ref'}) || isset($copy->allOf)) {
                $copy = (object) ['anyOf' => [(object) ['type' => 'null'], self::withoutNullable($copy)]];
            }
        }

        return $copy;
    }

    private static function withoutNullable(\stdClass $node): \stdClass
    {
        unset($node->nullable);

        return $node;
    }
}
