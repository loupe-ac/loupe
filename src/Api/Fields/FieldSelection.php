<?php

declare(strict_types=1);

namespace App\Api\Fields;

use App\Api\Problem\ApiProblemException;
use Symfony\Component\HttpFoundation\Request;

/**
 * The `fields` of a read request: no value, the word `all`, or a comma list of
 * top-level names of the resource. A name is checked against the names the
 * route accepts, so a typing error is refused and never read as an empty value.
 */
final readonly class FieldSelection
{
    public const string ALL = 'all';

    /**
     * @param list<string> $names the named fields in the order of the resource, empty for the default and for `all`
     */
    private function __construct(
        public array $names,
        private bool $all,
        private bool $default,
    ) {
    }

    /**
     * @param list<string> $allowed the top-level names of the resource, in the order of its row
     * @param list<string> $refused the names this route refuses, `all` included when it refuses the whole resource
     *
     * @throws ApiProblemException validation_failed with one error for each bad name
     */
    public static function fromRequest(Request $request, array $allowed, array $refused = []): self
    {
        $query = $request->query->all();
        if (!\array_key_exists('fields', $query)) {
            return new self([], false, true);
        }

        $accepted = array_values(array_diff([...$allowed, self::ALL], $refused));
        $listing = implode(', ', $accepted);
        $raw = $query['fields'];
        if (!\is_string($raw)) {
            throw ApiProblemException::validationFailed([self::invalid('fields must be one comma-separated value, such as fields=a,b. Allowed: '.$listing.'.')]);
        }

        $errors = [];
        $seen = [];
        foreach (explode(',', $raw) as $name) {
            if (isset($seen[$name])) {
                continue;
            }
            $seen[$name] = true;
            if ('' === $name) {
                $errors[] = self::invalid('A field name is empty. Allowed: '.$listing.'.');
            } elseif (\in_array($name, $refused, true)) {
                $errors[] = ['field' => 'fields', 'code' => 'fields_refused', 'message' => \sprintf('The field "%s" is not returned in a list. Read one resource to get it.', $name)];
            } elseif (self::ALL !== $name && !\in_array($name, $allowed, true)) {
                $errors[] = self::invalid(\sprintf('Unknown field "%s". Allowed: %s.', $name, $listing));
            }
        }
        if (isset($seen[self::ALL]) && \count($seen) > 1 && !\in_array(self::ALL, $refused, true)) {
            $errors[] = self::invalid('The field "all" cannot be combined with another name.');
        }
        if ([] !== $errors) {
            throw ApiProblemException::validationFailed($errors);
        }

        if (isset($seen[self::ALL])) {
            return new self([], true, false);
        }

        return new self(array_values(array_filter($allowed, static fn (string $field): bool => isset($seen[$field]))), false, false);
    }

    /** True when the request sent no `fields`, so the route answers its default row. */
    public function isDefault(): bool
    {
        return $this->default;
    }

    public function isAll(): bool
    {
        return $this->all;
    }

    /** True when the row keeps the field: named by the request, or the request asked for `all`. */
    public function has(string $field): bool
    {
        return $this->all || \in_array($field, $this->names, true);
    }

    /**
     * The row cut to the named fields, in the order of the row. A default row and an `all` row pass unchanged.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    public function keep(array $row): array
    {
        if ($this->default || $this->all) {
            return $row;
        }

        return array_intersect_key($row, array_flip($this->names));
    }

    /** @return array{field: string, code: string, message: string} */
    private static function invalid(string $message): array
    {
        return ['field' => 'fields', 'code' => 'fields_invalid', 'message' => $message];
    }
}
