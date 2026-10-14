<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

/** Work that a person starts from outside the workflow, such as an action on an inbox item. No rule owns it. */
final class PersonWork
{
    /** The rule id of a request that a person started begins with this prefix. The engine leaves such a request alone. */
    public const string RULE_PREFIX = 'inbox:';

    private function __construct()
    {
    }

    public static function owns(string $ruleId): bool
    {
        return str_starts_with($ruleId, self::RULE_PREFIX);
    }
}
