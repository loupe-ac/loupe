<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Entity\WorkerRun;
use OpenApi\Attributes as OA;

/**
 * The variants a rule offers for one experiment, and the one it drew for the
 * card. The fields carry no constraints and accept any JSON value, because a
 * validation failure answers without the error code that the bridge reads.
 * choice() checks them instead.
 */
#[OA\Schema(required: ['candidate', 'variants', 'baseline'])]
final class ResolveExperimentPinRequest
{
    public const int MAX_VARIANTS = 32;

    public const int MAX_WEIGHT = 1_000_000;

    public const int MAX_METRICS = 16;

    public const int MAX_CONTROLS = 16;

    public const string EXPECT_PATTERN = '/^(>=|<=|>|<|==) *-?[0-9]+(\.[0-9]+)?$/D';

    public const int MAX_COVERS = 32;

    private const string METRIC_KEY_PATTERN = '/^[a-z][a-z0-9_:-]{0,127}$/D';

    public function __construct(
        #[OA\Property(description: 'The variant drawn for the card. It must be one of `variants`.', type: 'string')]
        public mixed $candidate = null,

        #[OA\Property(type: 'array', items: new OA\Items(type: 'string', pattern: '^[a-z0-9][a-z0-9_-]{0,63}$'), maxItems: 32, minItems: 1, uniqueItems: true)]
        public mixed $variants = null,

        #[OA\Property(description: 'One weight for each variant, in order. A bad list never refuses the pin.', type: 'array', items: new OA\Items(type: 'integer', maximum: 1000000, minimum: 1), nullable: true)]
        public mixed $weights = null,

        #[OA\Property(type: 'array', items: new OA\Items(type: 'string', pattern: '^[a-z][a-z0-9_:-]{0,127}$'), nullable: true, maxItems: 16)]
        public mixed $metrics = null,

        #[OA\Property(ref: '#/components/schemas/ExperimentHeadline')]
        public mixed $headline = null,

        #[OA\Property(description: 'The work kinds the experiment covers.', type: 'array', items: new OA\Items(type: 'string', pattern: '^(?:[a-z][a-z0-9-]{0,39}:)?[a-z][a-z0-9-]{0,39}$'), nullable: true, maxItems: 32)]
        public mixed $covers = null,

        #[OA\Property(description: 'The variant the others are compared against. It must be one of `variants`.', type: 'string')]
        public mixed $baseline = null,
    ) {
    }

    /**
     * Null when the variants are not a list of unique names, or the candidate
     * is not one of them.
     *
     * @return array{candidate: string, variants: non-empty-list<string>}|null
     */
    public function choice(): ?array
    {
        $variants = $this->variants;
        if (!\is_array($variants) || [] === $variants || !array_is_list($variants) || \count($variants) > self::MAX_VARIANTS) {
            return null;
        }

        $names = [];
        foreach ($variants as $variant) {
            if (!\is_string($variant) || !self::isName($variant) || \in_array($variant, $names, true)) {
                return null;
            }
            $names[] = $variant;
        }

        if (!\is_string($this->candidate) || !\in_array($this->candidate, $names, true)) {
            return null;
        }

        return ['candidate' => $this->candidate, 'variants' => $names];
    }

    /**
     * The weight of each variant, in the order of the variants. Null when the
     * choice is invalid, or the weights are not one int from 1 to MAX_WEIGHT for
     * each variant. A bad list never refuses the pin, so an older bridge that
     * sends none still runs. A list, because PHP turns a key such as "0" into an int.
     *
     * @return non-empty-list<array{name: string, weight: int<1, max>}>|null
     */
    public function weights(): ?array
    {
        $choice = $this->choice();
        $weights = $this->weights;
        if (null === $choice || !\is_array($weights) || !array_is_list($weights) || \count($weights) !== \count($choice['variants'])) {
            return null;
        }

        $named = [];
        foreach ($choice['variants'] as $i => $name) {
            $weight = $weights[$i];
            if (!\is_int($weight) || $weight < 1 || $weight > self::MAX_WEIGHT) {
                return null;
            }
            $named[] = ['name' => $name, 'weight' => $weight];
        }

        return $named;
    }

    /**
     * The metric keys the experiment declares, in their order. Null when they
     * are not a list of 1 to MAX_METRICS unique keys of the right shape. A key
     * the registry does not know stays, so the page can name it.
     *
     * @return non-empty-list<string>|null
     */
    public function metrics(): ?array
    {
        $metrics = $this->metrics;
        if (!\is_array($metrics) || [] === $metrics || !array_is_list($metrics) || \count($metrics) > self::MAX_METRICS) {
            return null;
        }

        $keys = [];
        foreach ($metrics as $key) {
            if (!\is_string($key) || 1 !== preg_match(self::METRIC_KEY_PATTERN, $key) || \in_array($key, $keys, true)) {
                return null;
            }
            $keys[] = $key;
        }

        return $keys;
    }

    /**
     * The goal, the guards and the controls of the experiment page. Null when
     * the headline is not an object, or one of its parts is malformed, or it
     * holds no part. Keys it does not know drop out. An empty guards list
     * stays, and means no guards. A goal with no better way stays, so the page
     * can say so. A control names a declared variant, a comparison with a
     * number, and a share above 0 up to 1.
     *
     * @return array{goal?: array{metric: string, better?: string}, guards?: list<string>, controls?: list<array{metric: string, variant: string, expect: string, share: float}>}|null
     */
    public function headline(): ?array
    {
        $headline = $this->headline;
        if (!\is_array($headline) || [] === $headline || array_is_list($headline)) {
            return null;
        }

        $normalised = [];
        if (\array_key_exists('goal', $headline)) {
            $goal = $headline['goal'];
            if (!\is_array($goal) || !\is_string($goal['metric'] ?? null) || 1 !== preg_match(self::METRIC_KEY_PATTERN, $goal['metric'])
                || !\in_array($better = $goal['better'] ?? null, [null, 'lower', 'higher'], true)) {
                return null;
            }
            $normalised['goal'] = null === $better ? ['metric' => $goal['metric']] : ['metric' => $goal['metric'], 'better' => $better];
        }

        if (\array_key_exists('guards', $headline)) {
            $guards = $headline['guards'];
            if (!\is_array($guards) || !array_is_list($guards) || \count($guards) > self::MAX_METRICS) {
                return null;
            }
            $keys = [];
            foreach ($guards as $key) {
                if (!\is_string($key) || 1 !== preg_match(self::METRIC_KEY_PATTERN, $key) || \in_array($key, $keys, true)) {
                    return null;
                }
                $keys[] = $key;
            }
            if (isset($normalised['goal']) && \in_array($normalised['goal']['metric'], $keys, true)) {
                return null;
            }
            $normalised['guards'] = $keys;
        }

        if (\array_key_exists('controls', $headline)) {
            $controls = $this->controls($headline['controls']);
            if (null === $controls) {
                return null;
            }
            if ([] !== $controls) {
                $normalised['controls'] = $controls;
            }
        }

        return [] === $normalised ? null : $normalised;
    }

    /**
     * Null when the controls are not a list of at most MAX_CONTROLS well formed entries with no repeat of a metric and a variant.
     *
     * @return list<array{metric: string, variant: string, expect: string, share: float}>|null
     */
    private function controls(mixed $controls): ?array
    {
        $variants = $this->choice()['variants'] ?? null;
        if (!\is_array($controls) || !array_is_list($controls) || \count($controls) > self::MAX_CONTROLS || null === $variants) {
            return null;
        }

        $normalised = [];
        $seen = [];
        foreach ($controls as $control) {
            if (!\is_array($control) || array_is_list($control)) {
                return null;
            }
            $metric = $control['metric'] ?? null;
            $variant = $control['variant'] ?? null;
            $expect = $control['expect'] ?? null;
            $share = $control['share'] ?? null;
            if (!\is_string($metric) || 1 !== preg_match(self::METRIC_KEY_PATTERN, $metric)
                || !\is_string($variant) || !\in_array($variant, $variants, true)
                || !\is_string($expect) || 1 !== preg_match(self::EXPECT_PATTERN, $expect)
                || !(\is_int($share) || \is_float($share)) || !($share > 0 && $share <= 1)
                || isset($seen[$metric."\0".$variant])) {
                return null;
            }
            $seen[$metric."\0".$variant] = true;
            $normalised[] = ['metric' => $metric, 'variant' => $variant, 'expect' => $expect, 'share' => (float) $share];
        }

        return $normalised;
    }

    /**
     * The work kinds the experiment covers, sorted. Null when they are not a
     * list of 1 to MAX_COVERS unique kinds of the right shape. A bad list never
     * refuses the pin, so an older bridge that sends none still runs.
     *
     * @return non-empty-list<string>|null
     */
    public function covers(): ?array
    {
        $covers = $this->covers;
        if (!\is_array($covers) || [] === $covers || !array_is_list($covers) || \count($covers) > self::MAX_COVERS) {
            return null;
        }

        $kinds = [];
        foreach ($covers as $kind) {
            if (!\is_string($kind) || 1 !== preg_match(Bridge::WORK_KIND_PATTERN, $kind) || \in_array($kind, $kinds, true)) {
                return null;
            }
            $kinds[] = $kind;
        }
        sort($kinds);

        return $kinds;
    }

    /**
     * The variant the others are compared against. Null when it is not one of
     * the variants of the request, and the controller then refuses the pin.
     */
    public function baseline(): ?string
    {
        $variants = $this->choice()['variants'] ?? null;
        if (null === $variants || !\is_string($this->baseline) || !\in_array($this->baseline, $variants, true)) {
            return null;
        }

        return $this->baseline;
    }

    public static function isName(string $name): bool
    {
        return 1 === preg_match(WorkerRun::EXPERIMENT_NAME_PATTERN, $name);
    }
}
