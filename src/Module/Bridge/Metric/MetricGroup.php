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
    case None = 'none';

    /** A grouping that a single run answers, so a card splits into one row per key. */
    public function isRunGrouping(): bool
    {
        return \in_array($this, [self::Stage, self::Model, self::Variant, self::Bridge, self::Harness, self::Account, self::HarnessVersion, self::CliVersion, self::SkillVersion], true);
    }
}
