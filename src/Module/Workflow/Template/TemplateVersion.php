<?php

declare(strict_types=1);

namespace App\Module\Workflow\Template;

/** The version of a workflow template: a short hash of its content, so two states of a template never share a version. */
final readonly class TemplateVersion
{
    public const int LENGTH = 12;

    /** The keys that each project owns, so a stored copy and its shipped source give the same hash. */
    private const array IGNORED_KEYS = ['version', 'settingValues', 'approvalCoversConflictResolution'];

    /** @param array<mixed> $definition a template array, from a shipped YAML file or from a stored copy */
    public static function of(array $definition): string
    {
        foreach (self::IGNORED_KEYS as $key) {
            unset($definition[$key]);
        }

        return substr(hash('sha256', json_encode(self::canonical($definition), \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)), 0, self::LENGTH);
    }

    private static function canonical(mixed $value): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }
        $value = array_map(self::canonical(...), $value);
        if (!array_is_list($value)) {
            ksort($value, \SORT_STRING);
        }

        return $value;
    }
}
