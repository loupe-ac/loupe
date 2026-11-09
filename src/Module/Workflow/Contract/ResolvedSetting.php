<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

/** The value of one workflow setting, the source that gave it, and the warning lines of the key. */
final readonly class ResolvedSetting
{
    /**
     * @param string|bool|list<string>|null $value    an empty list is a stated value, and null means that no source states one
     * @param list<string>                  $warnings
     */
    public function __construct(
        public string $key,
        public string|bool|array|null $value,
        public SettingSource $source,
        public array $warnings = [],
    ) {
    }
}
