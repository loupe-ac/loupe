<?php

declare(strict_types=1);

namespace App\Module\Inbox\View;

use App\Module\Inbox\Entity\InboxItemKind;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\Uid\Uuid;

/**
 * The view of the inbox page that the address describes. A value that does not
 * parse falls back to its default, so a crafted address cannot break the page.
 */
final readonly class InboxView
{
    public const int MAX_PAGE = 100000;

    /** The item number column is a 32-bit integer. */
    public const int MAX_ITEM_NUMBER = 2147483647;

    /** Kinds the type filter offers. A notice has no button, because Loupe opens none. */
    public const array FILTER_KINDS = [
        InboxItemKind::Question,
        InboxItemKind::Review,
        InboxItemKind::Todo,
        InboxItemKind::Wait,
        InboxItemKind::Workflow,
    ];

    public string $query;

    /**
     * @param ?InboxItemKind $type       null shows every kind
     * @param string         $query      the search as typed, blank for none
     * @param ?int           $itemNumber the number of the selected item, for an old link or a loose item
     * @param int            $page       the page of the closed asks, or of the search results, from 1
     */
    public function __construct(
        public InboxSide $side = InboxSide::Open,
        public ?InboxItemKind $type = null,
        string $query = '',
        public ?Uuid $askId = null,
        public ?int $itemNumber = null,
        public int $page = 1,
    ) {
        $this->query = trim($query);
    }

    /** @param InputBag<string> $query */
    public static function fromQuery(InputBag $query): self
    {
        return self::fromValues($query->all());
    }

    /**
     * @param array<array-key, mixed> $values the query values, of which only a string or an int reads
     */
    public static function fromValues(array $values): self
    {
        // A forward hands over the query it built, so a number can arrive as an int.
        $text = static fn (string $key): string => isset($values[$key]) && (\is_string($values[$key]) || \is_int($values[$key])) ? (string) $values[$key] : '';

        $side = 'completed' === $text('queue') ? InboxSide::Closed : InboxSide::Open;
        $side = InboxSide::tryFrom($text('side')) ?? $side;

        $type = InboxItemKind::tryFrom($text('type'));
        if (!\in_array($type, self::FILTER_KINDS, true)) {
            $type = null;
        }

        $rawAsk = $text('ask');
        $askId = Uuid::isValid($rawAsk) ? Uuid::fromString($rawAsk) : null;

        $itemNumber = null === $askId ? self::positive($text('item'), self::MAX_ITEM_NUMBER) : null;

        return new self(
            side: $side,
            type: $type,
            query: $text('q'),
            askId: $askId,
            itemNumber: $itemNumber,
            page: self::positive($text('page'), self::MAX_PAGE) ?? 1,
        );
    }

    /**
     * What a link or a form puts in the query string. The side always rides a
     * search, so clearing the search returns to the side the owner was on.
     *
     * @return array<string, int|string>
     */
    public function toQuery(): array
    {
        return [
            ...(InboxSide::Closed === $this->side || $this->isSearch() ? ['side' => $this->side->value] : []),
            ...(null === $this->type ? [] : ['type' => $this->type->value]),
            ...($this->isSearch() ? ['q' => $this->query] : []),
            ...(null === $this->askId ? [] : ['ask' => $this->askId->toRfc4122()]),
            ...(null === $this->itemNumber ? [] : ['item' => $this->itemNumber]),
            ...($this->page > 1 ? ['page' => $this->page] : []),
        ];
    }

    public function isSearch(): bool
    {
        return '' !== $this->query;
    }

    public function isPicked(): bool
    {
        return null !== $this->askId || null !== $this->itemNumber;
    }

    /** The side with no search, no pick and no page, as the switch links to it. The type filter stays. */
    public function onSide(InboxSide $side): self
    {
        return new self($side, $this->type);
    }

    public function withPage(int $page): self
    {
        return new self($this->side, $this->type, $this->query, $this->askId, $this->itemNumber, max(1, $page));
    }

    public function withType(?InboxItemKind $type): self
    {
        return new self($this->side, $type, $this->query, null, null, 1);
    }

    public function withQuery(string $query): self
    {
        return new self($this->side, $this->type, $query, null, null, 1);
    }

    public function withAsk(Uuid $askId): self
    {
        return new self($this->side, $this->type, $this->query, $askId, null, $this->page);
    }

    public function withItem(int $itemNumber): self
    {
        return new self($this->side, $this->type, $this->query, null, $itemNumber, $this->page);
    }

    public function withoutSelection(): self
    {
        return new self($this->side, $this->type, $this->query, null, null, $this->page);
    }

    private static function positive(string $value, int $max): ?int
    {
        if (!ctype_digit($value) || \strlen($value) > 18) {
            return null;
        }

        $number = (int) $value;

        return $number >= 1 && $number <= $max ? $number : null;
    }
}
