<?php

declare(strict_types=1);

namespace App\Module\Bridge\Workflow\Condition;

use App\Module\Workflow\Contract\Evidence;

/** The evidence of live work: the asked kind, or with no kind the kind that began first. */
final class ActiveKindEvidence
{
    /** @param array<string, \DateTimeImmutable> $since */
    public static function of(array $since, ?string $kind): ?Evidence
    {
        if (null === $kind) {
            asort($since);
            $kind = array_key_first($since);
        }
        $at = null === $kind ? null : $since[$kind] ?? null;

        return null === $at ? null : new Evidence($at, ['%kind%' => (string) $kind]);
    }
}
