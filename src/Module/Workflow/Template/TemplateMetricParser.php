<?php

declare(strict_types=1);

namespace App\Module\Workflow\Template;

use App\Module\Workflow\Contract\CountFilter;
use App\Module\Workflow\Contract\DurationEvent;
use App\Module\Workflow\Contract\MetricType;
use App\Module\Workflow\Contract\ReservedMetricKeys;
use App\Module\Workflow\Contract\SlotKeys;
use App\Module\Workflow\Contract\TemplateMetric;
use App\Module\Workflow\Contract\TemplateOutcome;

/**
 * Reads the `outcomes` and `metrics` lists of a template. A metric that names a value no request or move of the
 * template can produce is refused. A stored copy drops a metric with an error instead, so one bad entry hides nothing else.
 */
final readonly class TemplateMetricParser
{
    private const int LABEL_MAX_LENGTH = 80;
    private const int DESCRIPTION_MAX_LENGTH = 300;
    private const array METRIC_KEYS = ['key', 'label', 'description', 'count', 'rate', 'duration', 'splitBy'];
    private const array TYPE_KEYS = ['count', 'rate', 'duration'];
    private const string REVIEW_KIND = 'review';

    public function __construct(
        private ReservedMetricKeys $reserved,
    ) {
    }

    /**
     * @param list<string> $errors
     *
     * @return list<TemplateOutcome>
     */
    public function outcomes(mixed $given, bool $lenient, array &$errors): array
    {
        if (null === $given) {
            return [];
        }
        if (!\is_array($given) || !array_is_list($given)) {
            if (!$lenient) {
                $errors[] = 'outcomes: must be a list';
            }

            return [];
        }
        $outcomes = [];
        $found = [];
        foreach ($given as $index => $entry) {
            $where = \sprintf('outcomes[%d]', $index);
            $local = [];
            $outcome = self::outcome($entry, $where, $local);
            if (null !== $outcome && isset($found[$outcome->key])) {
                $local[] = \sprintf('%s: duplicate outcome "%s"', $where, $outcome->key);
            }
            if (null !== $outcome && $outcome->delivered && array_any($outcomes, static fn (TemplateOutcome $other): bool => $other->delivered)) {
                $local[] = $where.': only one outcome may be delivered';
            }
            if ([] !== $local) {
                if (!$lenient) {
                    $errors = [...$errors, ...$local];
                }
                continue;
            }
            \assert(null !== $outcome);
            $found[$outcome->key] = true;
            $outcomes[] = $outcome;
        }

        return $outcomes;
    }

    /**
     * @param list<string>          $slotKeys
     * @param list<TemplateOutcome> $outcomes
     * @param list<string>          $errors
     *
     * @return list<TemplateMetric>
     */
    public function metrics(mixed $given, array $slotKeys, array $outcomes, ProducedWork $produced, bool $lenient, array &$errors): array
    {
        if (null === $given) {
            return [];
        }
        if (!\is_array($given) || !array_is_list($given)) {
            if (!$lenient) {
                $errors[] = 'metrics: must be a list';
            }

            return [];
        }
        $metrics = [];
        $found = [];
        foreach ($given as $index => $entry) {
            $where = \sprintf('metrics[%d]', $index);
            $local = [];
            $metric = $this->metric($entry, $where, $slotKeys, $outcomes, $produced, $local);
            if (null !== $metric && isset($found[$metric->key])) {
                $local[] = \sprintf('%s: duplicate metric key "%s"', $where, $metric->key);
            }
            if ([] !== $local || null === $metric) {
                if (!$lenient) {
                    $errors = [...$errors, ...$local];
                }
                continue;
            }
            $found[$metric->key] = true;
            $metrics[] = $metric;
        }

        return $metrics;
    }

    /** @param list<string> $errors */
    private static function outcome(mixed $entry, string $where, array &$errors): ?TemplateOutcome
    {
        $delivered = false;
        if (\is_array($entry) && !array_is_list($entry)) {
            foreach (array_keys($entry) as $name) {
                if (!\in_array($name, ['key', 'delivered'], true)) {
                    $errors[] = \sprintf('%s: unknown key "%s"', $where, $name);
                }
            }
            $delivered = $entry['delivered'] ?? false;
            if (!\is_bool($delivered)) {
                $errors[] = $where.' delivered: must be true or false';
                $delivered = false;
            }
            $entry = $entry['key'] ?? null;
        }
        if (!\is_string($entry) || 1 !== preg_match(Template::KEY_PATTERN, $entry)) {
            $errors[] = $where.': an outcome must be a key of lowercase words joined with hyphens, or a map with a "key"';

            return null;
        }

        return new TemplateOutcome($entry, $delivered);
    }

    /**
     * @param list<string>          $slotKeys
     * @param list<TemplateOutcome> $outcomes
     * @param list<string>          $errors
     */
    private function metric(mixed $entry, string $where, array $slotKeys, array $outcomes, ProducedWork $produced, array &$errors): ?TemplateMetric
    {
        if (!\is_array($entry) || array_is_list($entry)) {
            $errors[] = $where.': must be a map';

            return null;
        }
        $errorCount = \count($errors);
        $key = $entry['key'] ?? null;
        if (!\is_string($key) || 1 !== preg_match(Template::KEY_PATTERN, $key)) {
            $errors[] = $where.' key: must be lowercase words joined with hyphens, at most 64 characters';
            $key = '';
        } else {
            $where .= \sprintf(' (%s)', $key);
            if (\in_array($key, $this->reserved->keys(), true)) {
                $errors[] = \sprintf('%s: the key "%s" belongs to a metric the app computes', $where, $key);
            }
        }
        foreach (array_keys($entry) as $name) {
            if (!\in_array($name, self::METRIC_KEYS, true)) {
                $errors[] = \sprintf('%s: unknown key "%s"', $where, $name);
            }
        }
        $label = self::text($entry, 'label', self::LABEL_MAX_LENGTH, $where, $errors);
        $description = self::text($entry, 'description', self::DESCRIPTION_MAX_LENGTH, $where, $errors);

        $types = array_values(array_filter(self::TYPE_KEYS, static fn (string $type): bool => \array_key_exists($type, $entry)));
        $splitBy = $entry['splitBy'] ?? null;
        if (1 !== \count($types)) {
            $errors[] = $where.': must have exactly one of count, rate and duration';

            return null;
        }
        $type = MetricType::from($types[0]);
        if (null !== $splitBy && (MetricType::Count !== $type || !\in_array($splitBy, CountFilter::FIELDS, true))) {
            $errors[] = $where.' splitBy: only a count takes it, and it must be one of '.implode(', ', CountFilter::FIELDS);
            $splitBy = null;
        }

        $metric = match ($type) {
            MetricType::Count => $this->count($entry['count'], $where, $produced, $errors),
            MetricType::Rate => $this->rate($entry['rate'], $where, $outcomes, $produced, $errors),
            MetricType::Duration => $this->duration($entry['duration'], $where, $slotKeys, $produced, $errors),
        };
        if (\count($errors) > $errorCount || null === $metric) {
            return null;
        }

        return new TemplateMetric($key, $label, $description, $type, $metric['filter'] ?? null, $splitBy, $metric['outcome'] ?? null, $metric['from'] ?? null, $metric['fromLast'] ?? false, $metric['to'] ?? null, $metric['alsoFrom'] ?? []);
    }

    /**
     * @param array<mixed> $entry
     * @param list<string> $errors
     */
    private static function text(array $entry, string $name, int $max, string $where, array &$errors): string
    {
        $value = $entry[$name] ?? null;
        if (!\is_string($value) || '' === trim($value) || mb_strlen($value) > $max) {
            $errors[] = \sprintf('%s %s: must be a non-empty string of at most %d characters', $where, $name, $max);

            return '';
        }

        return $value;
    }

    /**
     * @param list<string> $errors
     *
     * @return ?array{filter: CountFilter}
     */
    private function count(mixed $given, string $where, ProducedWork $produced, array &$errors): ?array
    {
        $filter = self::filter($given, $where.' count', $produced, $errors);

        return null === $filter ? null : ['filter' => $filter];
    }

    /**
     * @param list<TemplateOutcome> $outcomes
     * @param list<string>          $errors
     *
     * @return ?array{filter?: CountFilter, outcome?: string}
     */
    private function rate(mixed $given, string $where, array $outcomes, ProducedWork $produced, array &$errors): ?array
    {
        $where .= ' rate';
        if (!\is_array($given) || array_is_list($given) || 1 !== \count($given) || !\in_array(array_key_first($given), ['outcome', 'any'], true)) {
            $errors[] = $where.': must be a map with exactly one of outcome and any';

            return null;
        }
        if ('any' === array_key_first($given)) {
            $filter = self::filter($given['any'], $where.' any', $produced, $errors);

            return null === $filter ? null : ['filter' => $filter];
        }
        $outcome = $given['outcome'];
        $known = array_map(static fn (TemplateOutcome $declared): string => $declared->key, $outcomes);
        if (!\is_string($outcome) || !\in_array($outcome, $known, true)) {
            $errors[] = \sprintf('%s outcome: unknown outcome "%s"%s', $where, \is_string($outcome) ? $outcome : '', self::known($known, 'outcomes'));

            return null;
        }
        if (!\in_array($outcome, $produced->outcomes, true)) {
            $errors[] = \sprintf('%s outcome: no move sets the outcome "%s"%s', $where, $outcome, [] === $produced->outcomes ? '; no move sets an outcome' : '; moves set: '.implode(', ', $produced->outcomes));

            return null;
        }

        return ['outcome' => $outcome];
    }

    /**
     * @param list<string> $slotKeys
     * @param list<string> $errors
     *
     * @return ?array{from: DurationEvent, fromLast: bool, to: DurationEvent, alsoFrom: list<DurationEvent>}
     */
    private function duration(mixed $given, string $where, array $slotKeys, ProducedWork $produced, array &$errors): ?array
    {
        $where .= ' duration';
        if (!\is_array($given) || array_is_list($given) || ['from', 'to'] !== array_values(array_intersect(['from', 'to'], array_keys($given))) || \count($given) > 2) {
            $errors[] = $where.': must be a map with "from" and "to"';

            return null;
        }
        $from = $given['from'];
        $fromLast = false;
        $starts = null;
        if (\is_array($from) && !array_is_list($from) && ['last'] === array_keys($from)) {
            $fromLast = true;
            $from = $from['last'];
            if (\is_array($from) && !array_is_list($from) && ['earliest'] === array_keys($from)) {
                $errors[] = $where.' from: "earliest" cannot be used with "last"';

                return null;
            }
        } elseif (\is_array($from) && !array_is_list($from) && ['earliest'] === array_keys($from)) {
            $list = $from['earliest'];
            if (!\is_array($list) || !array_is_list($list) || \count($list) < 2) {
                $errors[] = $where.' from: "earliest" must be a list of at least two events';

                return null;
            }
            $starts = [];
            foreach ($list as $index => $item) {
                $event = $this->event($item, $where.' from earliest '.($index + 1), $slotKeys, $produced, $errors);
                if (null !== $event) {
                    $starts[] = $event;
                }
            }
            if (\count($starts) !== \count($list)) {
                return null;
            }
        }
        $start = null === $starts ? $this->event($from, $where.' from', $slotKeys, $produced, $errors) : $starts[0];
        $end = $this->event($given['to'], $where.' to', $slotKeys, $produced, $errors);

        return null === $start || null === $end ? null : ['from' => $start, 'fromLast' => $fromLast, 'to' => $end, 'alsoFrom' => null === $starts ? [] : \array_slice($starts, 1)];
    }

    /**
     * @param list<string> $slotKeys
     * @param list<string> $errors
     */
    private function event(mixed $given, string $where, array $slotKeys, ProducedWork $produced, array &$errors): ?DurationEvent
    {
        if (\is_string($given) && \in_array($given, DurationEvent::NAMED, true)) {
            if (DurationEvent::AGENT_REVIEW_PASSED === $given && !\in_array(self::REVIEW_KIND, $produced->kinds, true)) {
                $errors[] = \sprintf('%s: the event "%s" never happens, because no rule asks for a request of the kind "%s"', $where, $given, self::REVIEW_KIND);

                return null;
            }

            return new DurationEvent($given);
        }
        if (\is_array($given) && !array_is_list($given) && 1 === \count($given) && \in_array(array_key_first($given), DurationEvent::SLOT, true)) {
            $name = (string) array_key_first($given);
            $slot = $given[$name];
            $known = [...$slotKeys, SlotKeys::BACKLOG, SlotKeys::TERMINAL];
            if (!\is_string($slot) || !\in_array($slot, $known, true)) {
                $errors[] = \sprintf('%s: unknown slot "%s"%s', $where, \is_string($slot) ? $slot : '', self::known($known, 'slots'));

                return null;
            }

            return new DurationEvent($name, $slot);
        }
        $errors[] = \sprintf('%s: must be one of %s, or a map with "%s" and a slot', $where, implode(', ', DurationEvent::NAMED), implode('" or "', DurationEvent::SLOT));

        return null;
    }

    /** @param list<string> $errors */
    private static function filter(mixed $given, string $where, ProducedWork $produced, array &$errors): ?CountFilter
    {
        if (!\is_array($given) || array_is_list($given)) {
            $errors[] = $where.': must be a map with at least one of '.implode(', ', CountFilter::FIELDS);

            return null;
        }
        $errorCount = \count($errors);
        $values = [];
        $known = ['role' => $produced->roles, 'kind' => $produced->kinds, 'reason' => $produced->reasons];
        foreach ($given as $field => $value) {
            if (!\in_array($field, CountFilter::FIELDS, true)) {
                $errors[] = \sprintf('%s: unknown key "%s"', $where, $field);
            } elseif (!\is_string($value) || !\in_array($value, $known[$field], true)) {
                $errors[] = \sprintf('%s %s: unknown %s "%s"%s', $where, $field, $field, \is_string($value) ? $value : '', self::known($known[$field], $field.'s'));
            } else {
                $values[$field] = $value;
            }
        }

        if (\count($errors) > $errorCount) {
            return null;
        }
        $filter = new CountFilter($values['role'] ?? null, $values['kind'] ?? null, $values['reason'] ?? null);
        if (!array_any($produced->requests, static fn (ProducedRequest $request): bool => $request->matches($filter->role, $filter->kind, $filter->reason))) {
            $errors[] = \sprintf('%s: no request of the template writes %s together', $where, implode(', ', array_map(static fn (string $field, string $value): string => \sprintf('%s "%s"', $field, $value), array_keys($values), $values)));

            return null;
        }

        return $filter;
    }

    /** @param list<string> $known */
    private static function known(array $known, string $what): string
    {
        return [] === $known ? \sprintf('; the template declares no %s', $what) : \sprintf('; known %s: %s', $what, implode(', ', $known));
    }
}
