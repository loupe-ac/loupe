<?php

declare(strict_types=1);

namespace App\Module\Bridge\Metric\Formula;

/**
 * Reads the formula language and nothing else: capital letters, decimal numbers, `+ - * /` and parentheses.
 * Multiplication and division bind tighter than addition and subtraction, and a leading sign binds tightest.
 */
final class FormulaParser
{
    public const int MAX_LENGTH = 200;

    /** @var list<array{string, string, int}> kind (number, letter, operator, open, close), text and 1-based position */
    private array $tokens = [];
    private int $cursor = 0;

    /** @throws FormulaException */
    public static function parse(string $formula): FormulaNode
    {
        if ('' === trim($formula)) {
            throw new FormulaException(new FormulaProblem(FormulaProblem::EMPTY_FORMULA));
        }
        if (mb_strlen($formula) > self::MAX_LENGTH) {
            throw new FormulaException(new FormulaProblem(FormulaProblem::TOO_LONG, 0, ['%max%' => self::MAX_LENGTH]));
        }

        $parser = new self();
        $parser->tokens = self::tokenize($formula);
        $node = $parser->expression();
        if ($parser->cursor < \count($parser->tokens)) {
            [, $text, $position] = $parser->tokens[$parser->cursor];

            throw new FormulaException(new FormulaProblem(FormulaProblem::UNEXPECTED_TOKEN, $position, ['%token%' => $text]));
        }

        return $node;
    }

    /** @return list<array{string, string, int}> */
    private static function tokenize(string $formula): array
    {
        $tokens = [];
        $characters = mb_str_split($formula);
        $count = \count($characters);
        for ($index = 0; $index < $count; ++$index) {
            $character = $characters[$index];
            $position = $index + 1;
            if (\in_array($character, [' ', "\t"], true)) {
                continue;
            }
            if (1 === preg_match('/^[0-9]$/', $character)) {
                $text = $character;
                while ($index + 1 < $count && 1 === preg_match('/^[0-9]$/', $characters[$index + 1])) {
                    $text .= $characters[++$index];
                }
                if ($index + 1 < $count && '.' === $characters[$index + 1]) {
                    $text .= $characters[++$index];
                    $digits = 0;
                    while ($index + 1 < $count && 1 === preg_match('/^[0-9]$/', $characters[$index + 1])) {
                        $text .= $characters[++$index];
                        ++$digits;
                    }
                    if (0 === $digits) {
                        throw new FormulaException(new FormulaProblem(FormulaProblem::INVALID_NUMBER, $position, ['%number%' => $text]));
                    }
                }
                $tokens[] = ['number', $text, $position];

                continue;
            }
            $tokens[] = match (true) {
                1 === preg_match('/^[A-Z]$/', $character) => ['letter', $character, $position],
                \in_array($character, ['+', '-', '*', '/'], true) => ['operator', $character, $position],
                '(' === $character => ['open', $character, $position],
                ')' === $character => ['close', $character, $position],
                default => throw new FormulaException(new FormulaProblem(FormulaProblem::UNEXPECTED_CHARACTER, $position, ['%character%' => $character])),
            };
        }

        return $tokens;
    }

    private function expression(): FormulaNode
    {
        $left = $this->term();
        while (null !== ($operator = $this->operator(['+', '-']))) {
            [$symbol, $position] = $operator;
            $left = new BinaryNode(FormulaOperator::fromSymbol($symbol), $left, $this->term(), $position);
        }

        return $left;
    }

    private function term(): FormulaNode
    {
        $left = $this->unary();
        while (null !== ($operator = $this->operator(['*', '/']))) {
            [$symbol, $position] = $operator;
            $left = new BinaryNode(FormulaOperator::fromSymbol($symbol), $left, $this->unary(), $position);
        }

        return $left;
    }

    private function unary(): FormulaNode
    {
        $operator = $this->operator(['+', '-']);
        if (null !== $operator) {
            [$symbol, $position] = $operator;

            return new SignNode('-' === $symbol, $this->unary(), $position);
        }

        return $this->primary();
    }

    private function primary(): FormulaNode
    {
        $token = $this->tokens[$this->cursor] ?? null;
        if (null === $token) {
            throw new FormulaException(new FormulaProblem(FormulaProblem::UNEXPECTED_END, $this->endPosition()));
        }
        [$kind, $text, $position] = $token;
        ++$this->cursor;

        switch ($kind) {
            case 'number':
                return new NumberNode((float) $text, $position);
            case 'letter':
                return new LetterNode($text, $position);
            case 'open':
                $inner = $this->expression();
                if ('close' !== ($this->tokens[$this->cursor][0] ?? null)) {
                    throw new FormulaException(new FormulaProblem(FormulaProblem::UNCLOSED_PARENTHESIS, $position));
                }
                ++$this->cursor;

                return $inner;
            default:
                throw new FormulaException(new FormulaProblem(FormulaProblem::UNEXPECTED_TOKEN, $position, ['%token%' => $text]));
        }
    }

    /**
     * @param list<string> $symbols
     *
     * @return ?array{string, int} the operator and its position, consumed
     */
    private function operator(array $symbols): ?array
    {
        $token = $this->tokens[$this->cursor] ?? null;
        if (null === $token || 'operator' !== $token[0] || !\in_array($token[1], $symbols, true)) {
            return null;
        }
        ++$this->cursor;

        return [$token[1], $token[2]];
    }

    private function endPosition(): int
    {
        $last = $this->tokens[array_key_last($this->tokens) ?? 0] ?? null;

        return null === $last ? 1 : $last[2] + mb_strlen($last[1]);
    }
}
