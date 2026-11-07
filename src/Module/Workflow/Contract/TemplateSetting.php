<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

/** A setting the workflow template declares. A source states its value, and the default applies when none does. */
final readonly class TemplateSetting
{
    /** The format of a branch name pattern that holds the placeholder {number} exactly once. */
    public const string FORMAT_EPIC_BRANCH = 'epic-branch';

    /**
     * @param list<string>     $values  the allowed values of a choice, empty for any other type
     * @param string|bool|null $default the value that applies when no source states one, or null when the setting has no default
     * @param ?string          $format  the name of the format a text value must follow, or null for any text
     */
    public function __construct(
        public string $key,
        public SettingType $type,
        public SettingScope $scope,
        public array $values = [],
        public string|bool|null $default = null,
        public ?string $format = null,
    ) {
    }
}
