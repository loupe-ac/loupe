<?php

declare(strict_types=1);

namespace App\Module\Bridge\Metric;

enum MetricGroup: string
{
    case Stage = 'stage';
    case Model = 'model';
    case Variant = 'variant';
    case CardType = 'card-type';
    case Bridge = 'bridge';
    case Harness = 'harness';
    case Account = 'account';
    case HarnessVersion = 'harness-version';
    case CliVersion = 'cli-version';
    case SkillVersion = 'skill-version';
    case AccountWindow = 'account-window';
    case WakeGap = 'wake-gap';
    case WakeCache = 'wake-cache';
    case None = 'none';

    /** A grouping that a single run answers, so a card splits into one row per key. */
    public function isRunGrouping(): bool
    {
        return \in_array($this, [self::Stage, self::Model, self::Variant, self::Bridge, self::Harness, self::Account, self::HarnessVersion, self::CliVersion, self::SkillVersion, self::WakeGap, self::WakeCache], true);
    }

    /** A grouping that only the wake metrics offer. */
    public function isWakeGrouping(): bool
    {
        return self::WakeGap === $this || self::WakeCache === $this;
    }

    /**
     * The fixed order of the series of a grouping that buckets a number, or null when the labels sort the series.
     *
     * @return list<string>|null
     */
    public function seriesOrder(): ?array
    {
        return match ($this) {
            self::WakeGap => WakeGapBucket::values(),
            self::WakeCache => WakeGapBucket::cacheValues(),
            default => null,
        };
    }
}
