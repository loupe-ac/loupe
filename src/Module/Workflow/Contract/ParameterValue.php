<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

/** Reads a typed parameter value. The template parser checks the types, so a wrong type here is a bug. */
final class ParameterValue
{
    /** @param array<string, mixed> $params */
    public static function string(array $params, string $name): string
    {
        $value = $params[$name] ?? null;

        return \is_string($value) ? $value : throw new \LogicException(\sprintf('Parameter "%s" must be a string.', $name));
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return list<string>
     */
    public static function strings(array $params, string $name): array
    {
        $value = $params[$name] ?? null;
        if (!\is_array($value) || !array_is_list($value) || !array_all($value, static fn (mixed $entry): bool => \is_string($entry))) {
            throw new \LogicException(\sprintf('Parameter "%s" must be a list of strings.', $name));
        }

        return $value;
    }

    /** @param array<string, mixed> $params */
    public static function optionalString(array $params, string $name): ?string
    {
        return null === ($params[$name] ?? null) ? null : self::string($params, $name);
    }

    /** @param array<string, mixed> $params */
    public static function int(array $params, string $name): int
    {
        $value = $params[$name] ?? null;

        return \is_int($value) ? $value : throw new \LogicException(\sprintf('Parameter "%s" must be an integer.', $name));
    }

    /** @param array<string, mixed> $params */
    public static function optionalInt(array $params, string $name): ?int
    {
        return null === ($params[$name] ?? null) ? null : self::int($params, $name);
    }

    /** @param array<string, mixed> $params */
    public static function optionalBool(array $params, string $name): ?bool
    {
        $value = $params[$name] ?? null;

        return null === $value || \is_bool($value) ? $value : throw new \LogicException(\sprintf('Parameter "%s" must be a boolean.', $name));
    }
}
