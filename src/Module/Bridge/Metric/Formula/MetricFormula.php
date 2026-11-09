<?php

declare(strict_types=1);

namespace App\Module\Bridge\Metric\Formula;

use App\Module\Bridge\Metric\MetricKey;
use App\Module\Bridge\Metric\MetricShape;
use App\Module\Bridge\Metric\MetricStatistic;
use App\Module\Bridge\Metric\MetricValueType;
use App\Module\Workflow\Contract\TemplateMetricSet;

/**
 * Up to six lettered rows, the formula text and the Format. Three flat strings carry it everywhere:
 * `rows=A:cache-read-tokens:sum,B:cache-write-tokens:sum`, `formula=A / B` and `format=auto`.
 * A formula with a problem has no tree and no type.
 */
final readonly class MetricFormula
{
    public const int MAX_ROWS = 6;
    public const string LETTERS = 'ABCDEF';

    /** @param list<FormulaRow> $rows in the order of the rows text */
    private function __construct(
        public array $rows,
        public string $formula,
        public FormulaFormat $format,
        public ?FormulaProblem $problem = null,
        public ?FormulaNode $tree = null,
        public ?MetricValueType $autoType = null,
    ) {
    }

    /** Never throws. A text that cannot be read gives a formula whose problem says why. */
    public static function parse(string $rows, string $formula, string $format, TemplateMetricSet $templates): self
    {
        $chosen = FormulaFormat::tryFrom($format);
        if (null === $chosen) {
            return new self([], $formula, FormulaFormat::Auto, new FormulaProblem(FormulaProblem::UNKNOWN_FORMAT, 0, ['%format%' => $format]));
        }

        try {
            $parsed = self::parseRows($rows, $templates);
            $tree = FormulaParser::parse($formula);
            $type = FormulaType::of($tree, array_map(static fn (FormulaRow $row): MetricValueType => $row->valueType(), self::byLetter($parsed)));
        } catch (FormulaException $exception) {
            return new self(self::rowsOrNone($rows, $templates), $formula, $chosen, $exception->problem);
        }

        return new self($parsed, $formula, $chosen, null, $tree, $type->auto());
    }

    /** The type that the result is shown in: the chosen Format, or what Auto picks. Null when the formula has a problem. */
    public function valueType(): ?MetricValueType
    {
        return $this->format->valueType() ?? $this->autoType;
    }

    /** The first row of the rows text. A one-metric reader of a formula graph gets this row. */
    public function earliestRow(): ?FormulaRow
    {
        return $this->rows[0] ?? null;
    }

    public function rowsText(): string
    {
        return implode(',', array_map(static fn (FormulaRow $row): string => $row->text(), $this->rows));
    }

    /**
     * @param list<FormulaRow> $rows
     *
     * @return array<string, FormulaRow>
     */
    private static function byLetter(array $rows): array
    {
        $byLetter = [];
        foreach ($rows as $row) {
            $byLetter[$row->letter] = $row;
        }

        return $byLetter;
    }

    /** @return list<FormulaRow> */
    private static function rowsOrNone(string $rows, TemplateMetricSet $templates): array
    {
        try {
            return self::parseRows($rows, $templates);
        } catch (FormulaException) {
            return [];
        }
    }

    /**
     * A row splits at its first and its last colon, because a metric key such as `bucket-time:tests` holds one.
     *
     * @return list<FormulaRow>
     *
     * @throws FormulaException
     */
    private static function parseRows(string $rows, TemplateMetricSet $templates): array
    {
        if ('' === trim($rows)) {
            throw new FormulaException(new FormulaProblem(FormulaProblem::NO_ROWS));
        }
        $items = explode(',', $rows);
        if (\count($items) > self::MAX_ROWS) {
            throw new FormulaException(new FormulaProblem(FormulaProblem::TOO_MANY_ROWS, 0, ['%max%' => self::MAX_ROWS]));
        }

        $parsed = [];
        foreach ($items as $item) {
            $item = trim($item);
            $first = strpos($item, ':');
            $last = strrpos($item, ':');
            $letter = false === $first ? '' : substr($item, 0, $first);
            if (false === $first || $first === $last || 1 !== preg_match('/^['.self::LETTERS.']$/', $letter)) {
                throw new FormulaException(new FormulaProblem(FormulaProblem::BAD_ROW, 0, ['%row%' => $item]));
            }
            if (isset(self::byLetter($parsed)[$letter])) {
                throw new FormulaException(new FormulaProblem(FormulaProblem::DUPLICATE_LETTER, 0, ['%letter%' => $letter]));
            }

            $key = substr($item, $first + 1, $last - $first - 1);
            $shape = null === ($metricKey = MetricKey::tryParse($key, $templates)) ? null : MetricShape::of($metricKey, $templates);
            if (null === $shape) {
                throw new FormulaException(new FormulaProblem(FormulaProblem::UNKNOWN_METRIC, 0, ['%letter%' => $letter, '%metric%' => $key]));
            }
            if (!$shape->isAdditive() || ($shape->key->metric?->isBucketEach() ?? false)) {
                throw new FormulaException(new FormulaProblem(FormulaProblem::METRIC_CANNOT_JOIN, 0, ['%letter%' => $letter, '%metric%' => $key]));
            }

            $statistic = MetricStatistic::tryFrom(substr($item, $last + 1));
            if (null === $statistic || !\in_array($statistic, $shape->statistics(), true)) {
                throw new FormulaException(new FormulaProblem(FormulaProblem::STATISTIC_NOT_ALLOWED, 0, ['%letter%' => $letter, '%metric%' => $key, '%statistic%' => substr($item, $last + 1)]));
            }

            $parsed[] = new FormulaRow($letter, $shape, $statistic);
        }

        return $parsed;
    }
}
