<?php

declare(strict_types=1);

namespace App\Module\Bridge\Metric\Formula;

use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Why a formula or its rows cannot be read: a translation key, the 1-based character where the mistake
 * starts (0 when it has no place in the formula text), and the values the message names.
 */
final readonly class FormulaProblem
{
    public const string EMPTY_FORMULA = 'bridge.metric.formula.problem.empty_formula';
    public const string TOO_LONG = 'bridge.metric.formula.problem.too_long';
    public const string UNEXPECTED_CHARACTER = 'bridge.metric.formula.problem.unexpected_character';
    public const string UNEXPECTED_TOKEN = 'bridge.metric.formula.problem.unexpected_token';
    public const string UNEXPECTED_END = 'bridge.metric.formula.problem.unexpected_end';
    public const string UNCLOSED_PARENTHESIS = 'bridge.metric.formula.problem.unclosed_parenthesis';
    public const string INVALID_NUMBER = 'bridge.metric.formula.problem.invalid_number';
    public const string UNKNOWN_LETTER = 'bridge.metric.formula.problem.unknown_letter';
    public const string MIXED_TYPES = 'bridge.metric.formula.problem.mixed_types';
    public const string NO_ROWS = 'bridge.metric.formula.problem.no_rows';
    public const string TOO_MANY_ROWS = 'bridge.metric.formula.problem.too_many_rows';
    public const string BAD_ROW = 'bridge.metric.formula.problem.bad_row';
    public const string DUPLICATE_LETTER = 'bridge.metric.formula.problem.duplicate_letter';
    public const string UNKNOWN_METRIC = 'bridge.metric.formula.problem.unknown_metric';
    public const string METRIC_CANNOT_JOIN = 'bridge.metric.formula.problem.metric_cannot_join';
    public const string STATISTIC_NOT_ALLOWED = 'bridge.metric.formula.problem.statistic_not_allowed';
    public const string UNKNOWN_FORMAT = 'bridge.metric.formula.problem.unknown_format';

    /** @param array<string, string|int> $parameters */
    public function __construct(
        public string $key,
        public int $position = 0,
        public array $parameters = [],
    ) {
    }

    /** The message in English for an agent, with the character where the mistake starts when it has a place. */
    public function english(TranslatorInterface $translator): string
    {
        $text = $translator->trans($this->key, $this->parameters, 'messages', 'en');

        return $this->position > 0 ? \sprintf('%s At character %d.', $text, $this->position) : $text;
    }
}
