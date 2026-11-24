<?php

declare(strict_types=1);

namespace App\Module\Workflow\Template;

use App\Module\Workflow\Contract\AskLabelRule;
use App\Module\Workflow\Contract\AskMode;

/** The `asks:` section of a template: how the inbox shapes the asks of a project. */
final readonly class TemplateAsks
{
    public const array KEYS = ['groupByCard', 'defaultHours', 'defaults', 'log', 'fields', 'labels'];
    private const array SWITCHES = ['groupByCard', 'defaults', 'log'];
    public const int MAX_DEFAULT_HOURS = 720;
    public const int MAX_ENTRIES = 50;

    /**
     * @param list<string>           $fields the detail fields that every labelled ask carries
     * @param list<TemplateAskLabel> $labels
     */
    public function __construct(
        public bool $groupByCard = true,
        public int $defaultHours = 24,
        public bool $defaults = true,
        public bool $log = true,
        public array $fields = [],
        public array $labels = [],
    ) {
    }

    /**
     * The mode of the label after the switches. An unlisted label asks.
     */
    public function modeOf(string $label): AskMode
    {
        foreach ($this->labels as $entry) {
            if ($entry->key !== $label) {
                continue;
            }

            return match (true) {
                AskMode::Log === $entry->mode && !$this->log => AskMode::Ask,
                AskMode::Default === $entry->mode && !$this->defaults => AskMode::Ask,
                default => $entry->mode,
            };
        }

        return AskMode::Ask;
    }

    /**
     * A stored copy from before the section has none, and a lenient read takes a bad value as the default.
     *
     * @param list<string> $errors
     */
    public static function parse(mixed $given, bool $lenient, array &$errors): self
    {
        if (null === $given) {
            return new self();
        }
        if (!\is_array($given) || array_is_list($given) && [] !== $given) {
            if (!$lenient) {
                $errors[] = 'asks: must be a map';
            }

            return new self();
        }
        $own = new self();
        $switches = ['groupByCard' => $own->groupByCard, 'defaults' => $own->defaults, 'log' => $own->log];
        $defaultHours = $own->defaultHours;
        $fields = [];
        $labels = [];
        foreach ($given as $key => $value) {
            if (!\in_array($key, self::KEYS, true)) {
                if (!$lenient) {
                    $errors[] = \sprintf('asks: unknown key "%s"', $key);
                }
            } elseif (\in_array($key, self::SWITCHES, true)) {
                if (\is_bool($value)) {
                    $switches[$key] = $value;
                } elseif (!$lenient) {
                    $errors[] = \sprintf('asks.%s: must be true or false', $key);
                }
            } elseif ('defaultHours' === $key) {
                if (\is_int($value) && $value >= 1 && $value <= self::MAX_DEFAULT_HOURS) {
                    $defaultHours = $value;
                } elseif (!$lenient) {
                    $errors[] = \sprintf('asks.defaultHours: must be a whole number from 1 to %d', self::MAX_DEFAULT_HOURS);
                }
            } elseif ('fields' === $key) {
                $fields = self::parseFields($value, $lenient, $errors);
            } else {
                $labels = self::parseLabels($value, $lenient, $errors);
            }
        }

        return new self($switches['groupByCard'], $defaultHours, $switches['defaults'], $switches['log'], $fields, $labels);
    }

    /**
     * @param list<string> $errors
     *
     * @return list<string>
     */
    private static function parseFields(mixed $given, bool $lenient, array &$errors): array
    {
        if (!\is_array($given) || !array_is_list($given) || \count($given) > self::MAX_ENTRIES) {
            if (!$lenient) {
                $errors[] = \sprintf('asks.fields: must be a list of at most %d names', self::MAX_ENTRIES);
            }

            return [];
        }
        $fields = [];
        foreach ($given as $index => $name) {
            if (!self::isName($name)) {
                if (!$lenient) {
                    $errors[] = \sprintf('asks.fields[%d]: must be lowercase words joined by hyphens', $index);
                }
            } elseif (\in_array($name, $fields, true)) {
                if (!$lenient) {
                    $errors[] = \sprintf('asks.fields[%d]: the field "%s" is listed twice', $index, $name);
                }
            } else {
                $fields[] = $name;
            }
        }

        return $fields;
    }

    /**
     * @param list<string> $errors
     *
     * @return list<TemplateAskLabel>
     */
    private static function parseLabels(mixed $given, bool $lenient, array &$errors): array
    {
        if (!\is_array($given) || !array_is_list($given) || \count($given) > self::MAX_ENTRIES) {
            if (!$lenient) {
                $errors[] = \sprintf('asks.labels: must be a list of at most %d entries', self::MAX_ENTRIES);
            }

            return [];
        }
        $labels = [];
        $seen = [];
        foreach ($given as $index => $entry) {
            $where = \sprintf('asks.labels[%d]', $index);
            $key = \is_array($entry) ? ($entry['key'] ?? null) : null;
            $mode = \is_array($entry) && \is_string($entry['mode'] ?? null) ? AskMode::tryFrom($entry['mode']) : null;
            $extra = \is_array($entry) ? array_diff(array_keys($entry), ['key', 'mode']) : [];
            if (!self::isName($key) || null === $mode || [] !== $extra) {
                if (!$lenient) {
                    $errors[] = \sprintf('%s: must be a map with a "key" in lowercase words joined by hyphens and a "mode" of log, default or ask', $where);
                }
            } elseif (isset($seen[$key])) {
                if (!$lenient) {
                    $errors[] = \sprintf('%s: the label "%s" is listed twice', $where, $key);
                }
            } else {
                $seen[$key] = true;
                $labels[] = new TemplateAskLabel($key, $mode);
            }
        }

        return $labels;
    }

    /** @phpstan-assert-if-true non-empty-string $value */
    public static function isName(mixed $value): bool
    {
        return \is_string($value) && \strlen($value) <= AskLabelRule::MAX_NAME_LENGTH && 1 === preg_match(AskLabelRule::NAME_PATTERN, $value);
    }
}
