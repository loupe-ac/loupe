<?php

declare(strict_types=1);

namespace App\Module\Bridge\Workflow\Condition;

use App\Module\Workflow\Contract\Evidence;
use App\Module\Workflow\Contract\ParameterValue;

/** The evidence of live work: the asked kind, or with no kind the kind that began first. */
final class ActiveKindEvidence
{
    /** The one value of the `origin` parameter: count only the work that a rule started. */
    public const string ORIGIN_RULE = 'rule';

    /** @param array<string, mixed> $params */
    public static function isRuleOnly(array $params): bool
    {
        return self::ORIGIN_RULE === ParameterValue::optionalString($params, 'origin');
    }

    /** @param array<string, \DateTimeImmutable> $since */
    public static function of(array $since, ?string $kind): ?Evidence
    {
        if (null === $kind) {
            asort($since);
            $kind = array_key_first($since);
        }
        $at = null === $kind ? null : $since[$kind] ?? null;

        return null === $at ? null : new Evidence(['%kind%' => (string) $kind]);
    }
}
