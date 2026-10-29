<?php

declare(strict_types=1);

namespace App\Module\Bridge\Experiment;

/** Judges the controls of an experiment against the value of each finished card of a variant. */
final class ControlEvaluator
{
    private const string EXPECT_PATTERN = '/^(>=|<=|>|<|==) *(-?[0-9]+(?:\.[0-9]+)?)$/D';

    /**
     * A card passes when its value meets the expectation. The control passes when the share of passing cards among the cards with data reaches its share.
     *
     * @param list<array{metric: string, variant: string, expect: string, share: float}> $specs
     * @param array<string, array<string, list<int|float>>|null>                         $values         metric key => variant => the values of the finished kept cards that have one. Null when the key is no metric
     * @param array<string, int>                                                         $finished       variant => finished kept cards
     * @param array<string, string>                                                      $templateLabels metric key => plain text name of a template metric
     *
     * @return list<ExperimentControl>
     */
    public static function evaluate(array $specs, array $values, array $finished, array $templateLabels = []): array
    {
        $controls = [];
        foreach ($specs as $spec) {
            $key = $spec['metric'];
            $variant = $spec['variant'];
            $cards = $finished[$variant] ?? 0;
            $label = $templateLabels[$key] ?? null;
            $make = static fn (ControlResult $result, ?float $passingShare = null, int $withData = 0): ExperimentControl => new ExperimentControl($key, $variant, $spec['expect'], $spec['share'], $passingShare, $withData, $cards, $result, $label);

            if (null === ($values[$key] ?? null)) {
                $controls[] = $make(ControlResult::UnknownMetric);
                continue;
            }

            $own = $values[$key][$variant] ?? [];
            if ([] === $own) {
                $controls[] = $make(ControlResult::NoData);
                continue;
            }

            $passing = \count(array_filter($own, static fn (int|float $value): bool => self::meets($value, $spec['expect'])));
            $share = $passing / \count($own);
            $controls[] = $make($share >= $spec['share'] ? ControlResult::Passed : ControlResult::Failed, $share, \count($own));
        }

        return $controls;
    }

    /**
     * The controls on the variant or the baseline that hold the comparison back, for the first kind of result in this order: failed, unknown metric, no data. Null when each of those controls passes. A control on another variant is not read.
     *
     * @param list<ExperimentControl> $controls
     */
    public static function hold(array $controls, string $variant, string $baseline): ?GoalHold
    {
        $read = array_values(array_filter($controls, static fn (ExperimentControl $control): bool => $variant === $control->variant || $baseline === $control->variant));
        foreach ([ControlResult::Failed, ControlResult::UnknownMetric, ControlResult::NoData] as $reason) {
            $held = array_values(array_filter($read, static fn (ExperimentControl $control): bool => $reason === $control->result));
            if ([] !== $held) {
                return new GoalHold($reason, $held, \count($read));
            }
        }

        return null;
    }

    private static function meets(int|float $value, string $expect): bool
    {
        if (1 !== preg_match(self::EXPECT_PATTERN, $expect, $parts)) {
            return false;
        }
        $limit = (float) $parts[2];

        return match ($parts[1]) {
            '>' => $value > $limit,
            '>=' => $value >= $limit,
            '<' => $value < $limit,
            '<=' => $value <= $limit,
            default => (float) $value === $limit,
        };
    }
}
