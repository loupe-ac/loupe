<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

/** The value of one workflow setting, the source that gave it, and the warning lines of the key. */
final readonly class ResolvedSetting
{
    /**
     * @param list<string> $warnings
     */
    public function __construct(
        public string $key,
        public string|bool|null $value,
        public SettingSource $source,
        public array $warnings = [],
    ) {
    }
}
