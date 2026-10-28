<?php

declare(strict_types=1);

namespace App\Doctrine;

/**
 * What a person typed into a search field, split into the terms every search of
 * the app matches the same way.
 *
 * A term is a run of letters and digits, lowercased, or a quoted phrase of such
 * runs joined by one space. Punctuation separates words and is never matched.
 * A term matches where its text sits anywhere inside a word or across words.
 * A leading minus excludes a term, and `or` makes the term before it and the
 * term after it alternatives. Every other term is required.
 *
 * The raw text never reaches SQL. Only the normalised term text does, and it
 * holds nothing but letters, digits and spaces.
 */
final readonly class SearchQuery
{
    public const int MAX_TERMS = 8;

    public const int MAX_TERM_LENGTH = 64;

    /**
     * @param list<list<SearchTerm>> $clauses every clause must hold, and a clause holds when any of its terms does
     */
    private function __construct(
        public array $clauses,
    ) {
    }

    public static function parse(string $raw): self
    {
        preg_match_all('/(-?)"([^"]*)"?|(-?)([^\s"]+)/u', $raw, $tokens, \PREG_SET_ORDER);

        /** @var list<list<SearchTerm>> $clauses */
        $clauses = [];
        $count = 0;
        $joinNext = false;
        foreach ($tokens as $token) {
            $phrase = 1 === preg_match('/^-?"/', $token[0]);
            $excluded = '-' === ($phrase ? ($token[1] ?? '') : ($token[3] ?? ''));
            $text = $phrase ? ($token[2] ?? '') : ($token[4] ?? '');

            if (!$phrase && !$excluded && 0 === strcasecmp($text, 'or')) {
                $joinNext = [] !== $clauses;

                continue;
            }

            preg_match_all('/[\p{L}\p{N}]+/u', mb_strtolower($text), $words);
            if ([] === $words[0]) {
                continue;
            }
            $texts = $phrase ? [implode(' ', $words[0])] : $words[0];

            foreach ($texts as $piece) {
                if ($count >= self::MAX_TERMS) {
                    break 2;
                }
                $term = new SearchTerm(mb_substr($piece, 0, self::MAX_TERM_LENGTH), $excluded);
                if ($joinNext) {
                    $clauses[\count($clauses) - 1][] = $term;
                    $joinNext = false;
                } else {
                    $clauses[] = [$term];
                }
                ++$count;
            }
        }

        return new self($clauses);
    }

    /**
     * Whether the query holds any term. Punctuation alone holds none and finds nothing.
     */
    public function hasTerms(): bool
    {
        return [] !== $this->clauses;
    }

    /**
     * The terms a result must hold, as clauses of alternatives. A clause with
     * only excluded terms is left out, because it cannot make a title stand out.
     *
     * @return list<list<SearchTerm>>
     */
    public function wantedClauses(): array
    {
        $wanted = [];
        foreach ($this->clauses as $clause) {
            $positive = array_values(array_filter($clause, static fn (SearchTerm $term): bool => !$term->excluded));
            if ([] !== $positive && \count($positive) === \count($clause)) {
                $wanted[] = $positive;
            }
        }

        return $wanted;
    }

    /**
     * The same rule as the SQL one, for text that is searched in PHP.
     */
    public function matchesText(string $text): bool
    {
        if (!$this->hasTerms()) {
            return false;
        }
        $haystack = mb_strtolower($text);
        foreach ($this->clauses as $clause) {
            $holds = false;
            foreach ($clause as $term) {
                if ($term->excluded !== str_contains($haystack, $term->text)) {
                    $holds = true;

                    break;
                }
            }
            if (!$holds) {
                return false;
            }
        }

        return true;
    }
}
