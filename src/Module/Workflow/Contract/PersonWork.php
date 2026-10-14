<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

/** Work that a person asks for outside the template. Its rule ids carry a prefix, and the engine leaves such a request alone. */
final class PersonWork
{
    public const string RULE_PREFIX = 'inbox:';

    public static function isPersonRule(string $ruleId): bool
    {
        return str_starts_with($ruleId, self::RULE_PREFIX);
    }
}
