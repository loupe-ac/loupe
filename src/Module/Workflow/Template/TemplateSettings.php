<?php

declare(strict_types=1);

namespace App\Module\Workflow\Template;

use App\Module\Workflow\Contract\SettingScope;
use App\Module\Workflow\Contract\SettingType;
use App\Module\Workflow\Contract\TemplateSetting;

/** Reads the `settings`, `settingValues` and `requirements` keys of a template, and tells which value a setting accepts. */
final class TemplateSettings
{
    private const array FIELDS = ['key', 'type', 'scope', 'values', 'default', 'format'];

    private function __construct()
    {
    }

    public static function accepts(TemplateSetting $setting, mixed $value): bool
    {
        return match ($setting->type) {
            SettingType::Boolean => \is_bool($value),
            SettingType::Choice => \is_string($value) && \in_array($value, $setting->values, true),
            SettingType::Text => \is_string($value) && self::followsFormat($setting->format, $value),
        };
    }

    private static function followsFormat(?string $format, string $value): bool
    {
        return match ($format) {
            TemplateSetting::FORMAT_EPIC_BRANCH => \strlen($value) <= Template::EPIC_BRANCH_MAX_LENGTH && 1 === preg_match(Template::EPIC_BRANCH_RULE, $value),
            default => '' !== $value,
        };
    }

    /**
     * @param list<string> $errors
     *
     * @return list<TemplateSetting>
     */
    public static function declared(mixed $given, bool $lenient, array &$errors): array
    {
        if (!\is_array($given) || !array_is_list($given)) {
            if (!$lenient) {
                $errors[] = 'settings: must be a list of settings';
            }

            return [];
        }
        $settings = [];
        $listed = [];
        foreach ($given as $index => $entry) {
            $where = \sprintf('settings[%d]', $index);
            $setting = null;
            $error = self::entryError($entry, $where, $listed, $setting);
            if (null === $error && $setting instanceof TemplateSetting) {
                $listed[$setting->key] = true;
                $settings[] = $setting;

                continue;
            }
            if (!$lenient) {
                $errors[] = $error ?? $where.': is not valid';
            }
        }

        return $settings;
    }

    /**
     * @param array<string, true> $listed
     */
    private static function entryError(mixed $entry, string $where, array $listed, ?TemplateSetting &$setting): ?string
    {
        if (!\is_array($entry) || array_is_list($entry)) {
            return $where.': must be a map';
        }
        $unknown = array_diff(array_keys($entry), self::FIELDS);
        if ([] !== $unknown) {
            return \sprintf('%s: unknown field "%s"', $where, reset($unknown));
        }
        $key = $entry['key'] ?? null;
        if (!\is_string($key) || 1 !== preg_match(Template::KEY_PATTERN, $key)) {
            return \sprintf('%s: key "%s" must be lowercase letters, digits and hyphens', $where, \is_scalar($key) ? $key : get_debug_type($key));
        }
        if (isset($listed[$key])) {
            return \sprintf('%s: duplicate key "%s"', $where, $key);
        }
        $type = \is_string($entry['type'] ?? null) ? SettingType::tryFrom($entry['type']) : null;
        if (null === $type) {
            return \sprintf('%s: type must be bool, string or enum', $where);
        }
        $scope = \is_string($entry['scope'] ?? null) ? SettingScope::tryFrom($entry['scope']) : null;
        if (null === $scope) {
            return \sprintf('%s: scope must be repository or project', $where);
        }
        $values = $entry['values'] ?? [];
        if (SettingType::Choice === $type) {
            if (!\is_array($values) || !array_is_list($values) || [] === $values || !array_all($values, static fn (mixed $value): bool => \is_string($value) && '' !== $value) || \count($values) !== \count(array_unique($values))) {
                return $where.': values must be a list of one or more different non-empty strings';
            }
        } elseif (\array_key_exists('values', $entry)) {
            return $where.': values are for an enum only';
        }
        $format = $entry['format'] ?? null;
        if (null !== $format && (SettingType::Text !== $type || TemplateSetting::FORMAT_EPIC_BRANCH !== $format)) {
            return \sprintf('%s: format must be "%s" and belongs to a string', $where, TemplateSetting::FORMAT_EPIC_BRANCH);
        }
        /** @var list<string> $values */
        $candidate = new TemplateSetting($key, $type, $scope, SettingType::Choice === $type ? $values : [], null, $format);
        $default = $entry['default'] ?? null;
        if (\array_key_exists('default', $entry)) {
            if (!self::accepts($candidate, $default) || !(\is_string($default) || \is_bool($default))) {
                return $where.': default is not an allowed value';
            }
            $candidate = new TemplateSetting($key, $type, $scope, $candidate->values, $default, $format);
        }
        $setting = $candidate;

        return null;
    }

    /**
     * The values that a project stores in its template copy. A value is kept when a setting of the project scope accepts it.
     *
     * @param list<TemplateSetting> $settings
     * @param list<string>          $errors
     *
     * @return array<string, bool|string>
     */
    public static function projectValues(mixed $given, array $settings, bool $lenient, array &$errors): array
    {
        if (null === $given) {
            return [];
        }
        if (!\is_array($given) || ([] !== $given && array_is_list($given))) {
            if (!$lenient) {
                $errors[] = 'settingValues: must be a map of setting keys to values';
            }

            return [];
        }
        $byKey = [];
        foreach ($settings as $setting) {
            $byKey[$setting->key] = $setting;
        }
        $values = [];
        foreach ($given as $key => $value) {
            $setting = $byKey[$key] ?? null;
            if (null === $setting || SettingScope::Project !== $setting->scope) {
                $error = \sprintf('settingValues: "%s" is not a setting of the project scope', $key);
            } elseif (!self::accepts($setting, $value) || !(\is_string($value) || \is_bool($value))) {
                $error = \sprintf('settingValues: "%s" is not an allowed value', $key);
            } else {
                $values[(string) $key] = $value;

                continue;
            }
            if (!$lenient) {
                $errors[] = $error;
            }
        }

        return $values;
    }

    /**
     * @param list<string> $errors
     *
     * @return list<string>
     */
    public static function requirements(mixed $given, bool $lenient, array &$errors): array
    {
        if (null === $given) {
            return [];
        }
        if (!\is_array($given) || !array_is_list($given) || !array_all($given, static fn (mixed $text): bool => \is_string($text) && '' !== trim($text))) {
            if (!$lenient) {
                $errors[] = 'requirements: must be a list of non-empty strings';
            }

            return [];
        }

        return $given;
    }
}
