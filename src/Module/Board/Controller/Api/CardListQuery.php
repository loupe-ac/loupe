<?php

declare(strict_types=1);

namespace App\Module\Board\Controller\Api;

use App\Api\Problem\ApiProblemException;
use App\Module\Board\View\CardSort;
use Symfony\Component\HttpFoundation\Request;

/** The filters, the sort and the `full` switch of a card list request, read from its query string. */
final readonly class CardListQuery
{
    private function __construct(
        public CardSort $sort,
        public bool $full,
        public ?string $query,
        public ?string $status,
        public ?string $type,
        public ?string $reporter,
        public ?string $parentCardId,
        public ?int $parentNumber,
        public ?bool $paused,
    ) {
    }

    /** @throws ApiProblemException `validation_failed` for a value that is not of the kind its name takes */
    public static function fromRequest(Request $request): self
    {
        $all = $request->query->all();
        $errors = [];
        $text = static function (string $name) use ($all, &$errors): ?string {
            if (!\array_key_exists($name, $all)) {
                return null;
            }
            if (!\is_string($all[$name]) || '' === trim($all[$name])) {
                $errors[] = ['field' => $name, 'code' => $name.'_invalid', 'message' => \sprintf('%s must be a non-empty text.', $name)];

                return null;
            }

            return trim($all[$name]);
        };
        $flag = static function (string $name) use ($all, &$errors): ?bool {
            if (!\array_key_exists($name, $all)) {
                return null;
            }
            $value = \is_string($all[$name]) ? filter_var($all[$name], \FILTER_VALIDATE_BOOLEAN, \FILTER_NULL_ON_FAILURE) : null;
            if (null === $value) {
                $errors[] = ['field' => $name, 'code' => $name.'_invalid', 'message' => \sprintf('%s must be true or false.', $name)];
            }

            return $value;
        };

        $sort = CardSort::number();
        if (\array_key_exists('sort', $all)) {
            $given = \is_string($all['sort']) ? CardSort::tryFrom($all['sort']) : null;
            if (null === $given) {
                $errors[] = ['field' => 'sort', 'code' => 'sort_invalid', 'message' => 'The sort must be one of: '.implode(', ', CardSort::VALUES).'.'];
            } else {
                $sort = $given;
            }
        }

        $parentNumber = null;
        $parent = $text('parent');
        if (null !== $parent) {
            $parentNumber = filter_var($parent, \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 999_999_999]]);
            if (false === $parentNumber) {
                $parentNumber = null;
                $errors[] = ['field' => 'parent', 'code' => 'parent_invalid', 'message' => 'parent must be a card number.'];
            }
        }

        $query = new self(
            $sort,
            $flag('full') ?? false,
            $text('q'),
            $text('status'),
            $text('type'),
            $text('reporter'),
            $text('parentCardId'),
            $parentNumber,
            $flag('paused'),
        );
        if ([] !== $errors) {
            throw ApiProblemException::validationFailed($errors);
        }

        return $query;
    }

    /**
     * What a cursor remembers, so a walk that changes a filter is refused. `full` shapes the rows
     * and not the walk, so a client may switch it between pages.
     *
     * @return array<string, scalar|null>
     */
    public function filters(): array
    {
        return [
            'q' => $this->query,
            'status' => $this->status,
            'type' => $this->type,
            'reporter' => $this->reporter,
            'parentCardId' => $this->parentCardId,
            'parent' => $this->parentNumber,
            'paused' => $this->paused,
        ];
    }
}
