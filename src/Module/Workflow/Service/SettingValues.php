<?php

declare(strict_types=1);

namespace App\Module\Workflow\Service;

use App\Module\Workflow\Contract\SettingType;
use App\Module\Workflow\Contract\TemplateSetting;
use App\Module\Workflow\Template\TemplateSettings;

/** Encodes a setting value for its column, reads it back, and words the warning lines of a key. */
final class SettingValues
{
    private function __construct()
    {
    }

    public static function encode(string|bool $value): string
    {
        return json_encode($value, \JSON_THROW_ON_ERROR);
    }

    /** The value of a column, or null when the column holds nothing the setting accepts. */
    public static function decode(TemplateSetting $setting, ?string $stored): string|bool|null
    {
        if (null === $stored) {
            return null;
        }
        $value = json_decode($stored, true);

        return (\is_string($value) || \is_bool($value)) && TemplateSettings::accepts($setting, $value) ? $value : null;
    }

    public static function unknownKey(string $key): string
    {
        return \sprintf('The setting "%s" is not known. Loupe ignores it.', $key);
    }

    public static function invalidValue(TemplateSetting $setting): string
    {
        return \sprintf('The setting "%s" has a value that is not allowed. %s', $setting->key, self::allowed($setting));
    }

    private static function allowed(TemplateSetting $setting): string
    {
        return match ($setting->type) {
            SettingType::Boolean => 'Allowed values: true, false.',
            SettingType::Choice => 'Allowed values: '.implode(', ', $setting->values).'.',
            SettingType::Text => TemplateSetting::FORMAT_EPIC_BRANCH === $setting->format
                ? 'Allowed value: a branch name that holds {number} once.'
                : 'Allowed value: a text that is not empty.',
        };
    }
}
