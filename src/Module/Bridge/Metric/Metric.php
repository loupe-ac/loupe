<?php

declare(strict_types=1);

namespace App\Module\Bridge\Metric;

/** The metrics an analytics query can read. The keys reach agents and URLs, so they stay stable. */
enum Metric: string
{
    case Cost = 'cost';
    case InputTokens = 'input-tokens';
    case OutputTokens = 'output-tokens';
    case CacheReadTokens = 'cache-read-tokens';
    case CacheWriteTokens = 'cache-write-tokens';
    case Duration = 'duration';
    case Runs = 'runs';
    case StopRate = 'stop-rate';
    case BucketTime = 'bucket-time';
    case BucketTimeAll = 'bucket-time-all';
    case BucketCalls = 'bucket-calls';
    case BucketTimeEach = 'bucket-time-each';
    case BucketTimeAllEach = 'bucket-time-all-each';
    case BucketCallsEach = 'bucket-calls-each';
    case HarnessEvents = 'harness-events';
    case AccountUsage = 'account-usage';
    case SessionUsage = 'session-usage';
    case WeekUsage = 'week-usage';

    /**
     * The metrics whose key says all. A named metric needs a name too, so a
     * picker that lists keys alone leaves it out.
     *
     * @return list<self>
     */
    public static function standalone(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $metric): bool => !$metric->isNamed()));
    }

    /** A named metric is a family: its key is `<family>:<name>`, such as bucket-time:tests. */
    public function isNamed(): bool
    {
        return match ($this) {
            self::BucketTime, self::BucketTimeAll, self::BucketCalls, self::HarnessEvents => true,
            default => false,
        };
    }

    /** A grouped bucket metric reads every bucket at once, and the bucket is the line. */
    public function isBucketEach(): bool
    {
        return \in_array($this, [self::BucketTimeEach, self::BucketTimeAllEach, self::BucketCallsEach], true);
    }

    /** @return list<MetricUnit> */
    public function units(): array
    {
        return match ($this) {
            self::Runs => [MetricUnit::Card],
            self::StopRate, self::SessionUsage, self::WeekUsage => [MetricUnit::Run],
            self::AccountUsage => [MetricUnit::Reading],
            default => [MetricUnit::Run, MetricUnit::Card],
        };
    }

    public function valueType(): MetricValueType
    {
        return match ($this) {
            self::Cost => MetricValueType::Money,
            self::InputTokens, self::OutputTokens, self::CacheReadTokens, self::CacheWriteTokens => MetricValueType::Tokens,
            self::Duration, self::BucketTime, self::BucketTimeAll, self::BucketTimeEach, self::BucketTimeAllEach => MetricValueType::Duration,
            self::Runs, self::HarnessEvents, self::BucketCalls, self::BucketCallsEach => MetricValueType::Count,
            self::StopRate => MetricValueType::Ratio,
            self::AccountUsage, self::SessionUsage, self::WeekUsage => MetricValueType::Percent,
        };
    }

    /** @return list<MetricStatistic> */
    public function statistics(): array
    {
        return $this->valueType()->statistics();
    }

    /**
     * The account usage splits by account window alone, because a reading belongs to no run. Every other metric
     * splits by what a run or a card names.
     *
     * @return list<MetricGroup>
     */
    public function groups(): array
    {
        if (self::AccountUsage === $this) {
            return [MetricGroup::AccountWindow];
        }
        if ($this->isBucketEach()) {
            return [MetricGroup::None];
        }

        return array_values(array_filter(MetricGroup::cases(), static fn (MetricGroup $group): bool => MetricGroup::AccountWindow !== $group));
    }

    /**
     * The usage metrics read the account or overlap other work, so a card of an experiment has no value of them. A
     * grouped bucket metric has one line per bucket, and an experiment compares one number per variant.
     */
    public function readsExperimentCards(): bool
    {
        return !$this->isBucketEach() && !\in_array($this, [self::AccountUsage, self::SessionUsage, self::WeekUsage], true);
    }

    /** A sum over the runs of a card gives its value on the card unit. */
    public function isAdditive(): bool
    {
        return !\in_array($this, [self::StopRate, self::AccountUsage, self::SessionUsage, self::WeekUsage], true);
    }

    public function description(): string
    {
        return match ($this) {
            self::Cost => 'The cost in US dollars; unknown when a started run has no price.', // @translation-check-ignore
            self::InputTokens => 'The input tokens the model read.', // @translation-check-ignore
            self::OutputTokens => 'The output tokens the model wrote.', // @translation-check-ignore
            self::CacheReadTokens => 'The tokens the model read from its prompt cache.', // @translation-check-ignore
            self::CacheWriteTokens => 'The tokens the model wrote to its prompt cache.', // @translation-check-ignore
            self::Duration => 'The time from the start to the end of a run, in milliseconds.', // @translation-check-ignore
            self::Runs => 'The number of worker runs on a finished card.', // @translation-check-ignore
            self::StopRate => 'The share of runs that reached an outcome and blocked, failed, gave no result or gave up.', // @translation-check-ignore
            self::AccountUsage => 'The share of a usage limit window of a bridge account that was used, in percent, as each stored reading gave it. A value past 100 means use past the limit. Only the bridges of the project owner that follow the project and have a name count. The group names the bridge, the account and the window, as in laptop/work session.', // @translation-check-ignore
            self::SessionUsage => 'The change in the use of the session window of the account of a run, in percentage points, from the first to the last reading inside the run. A run with fewer than two readings in the window is unknown. A run that overlaps other runs or sessions on the same account also counts their use.', // @translation-check-ignore
            self::WeekUsage => 'The change in the use of the weekly window of the account of a run, in percentage points, from the first to the last reading inside the run. A run with fewer than two readings in the window is unknown. A run that overlaps other runs or sessions on the same account also counts their use.', // @translation-check-ignore
            self::HarnessEvents => 'The number of harness events of one kind in a run, such as diagnostics. A run that reported events but none of this kind counts 0, and a run that reported none is unknown. Name the kind after a colon: harness-events:<kind>.', // @translation-check-ignore
            self::BucketTime => 'The time of the main-session tool calls of a run in one bucket of the project rules, in milliseconds. Name the bucket after a colon: bucket-time:<name>.', // @translation-check-ignore
            self::BucketTimeAll => 'The time of the tool calls of a run in one bucket of the project rules, in milliseconds. It includes the calls of subagents, so it can exceed the run time. Name the bucket after a colon: bucket-time-all:<name>.', // @translation-check-ignore
            self::BucketCalls => 'The number of tool calls of a run in one bucket of the project rules, subagent calls included. Name the bucket after a colon: bucket-calls:<name>.', // @translation-check-ignore
            self::BucketTimeEach => 'The time of the main-session tool calls of a run in each bucket of the project rules, in milliseconds. It gives one line per bucket, and a bucket the run did not touch counts 0.', // @translation-check-ignore
            self::BucketTimeAllEach => 'The time of the tool calls of a run in each bucket of the project rules, subagent calls included, in milliseconds. It gives one line per bucket, and a bucket the run did not touch counts 0.', // @translation-check-ignore
            self::BucketCallsEach => 'The number of tool calls of a run in each bucket of the project rules, subagent calls included. It gives one line per bucket, and a bucket the run did not touch counts 0.', // @translation-check-ignore
        };
    }
}
