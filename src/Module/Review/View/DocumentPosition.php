<?php

declare(strict_types=1);

namespace App\Module\Review\View;

use App\Api\Pagination\Cursor;
use App\Api\Problem\ApiProblemException;
use Symfony\Component\Uid\Uuid;

/** Where a walk of the document list stands: the last row of the previous page. */
final readonly class DocumentPosition
{
    private const string TIME_FORMAT = 'Y-m-d H:i:s.u';

    public function __construct(
        public Uuid $id,
        public \DateTimeImmutable $createdAt,
        /** The series ordinal, for a walk in series order. */
        public int $ordinal,
        /** The title rank, for a ranked search. */
        public int $titleHit,
    ) {
    }

    public static function of(DocumentPageRow $row): self
    {
        $document = $row->document;

        return new self(
            $document->id ?? throw new \LogicException('A listed document has an id.'),
            $document->createdAt,
            $document->seriesOrdinal ?? 0,
            $row->titleHit,
        );
    }

    public function toCursor(DocumentPageOrder $order, DocumentPageQuery $query): Cursor
    {
        $value = match ($order) {
            DocumentPageOrder::Oldest, DocumentPageOrder::Newest => $this->createdAt->format(self::TIME_FORMAT),
            DocumentPageOrder::Series => (string) $this->ordinal,
            DocumentPageOrder::Relevance => $this->titleHit.'|'.$this->createdAt->format(self::TIME_FORMAT),
        };

        return new Cursor($order->value, $value, (string) $this->id, $query->filters());
    }

    /** @throws ApiProblemException `invalid_cursor` for a cursor whose position does not parse */
    public static function fromCursor(DocumentPageOrder $order, Cursor $cursor): self
    {
        if (!Uuid::isValid($cursor->id)) {
            throw self::broken();
        }
        $id = Uuid::fromString($cursor->id);

        if (DocumentPageOrder::Series === $order) {
            if (1 !== preg_match('/^\d{1,10}$/', $cursor->sortValue)) {
                throw self::broken();
            }

            return new self($id, new \DateTimeImmutable('@0'), (int) $cursor->sortValue, 0);
        }

        $time = $cursor->sortValue;
        $hit = 0;
        if (DocumentPageOrder::Relevance === $order) {
            if (1 !== preg_match('/^([01])\|(.+)$/', $time, $match)) {
                throw self::broken();
            }
            $hit = (int) $match[1];
            $time = $match[2];
        }

        $at = \DateTimeImmutable::createFromFormat(self::TIME_FORMAT, $time);
        if (false === $at || $at->format(self::TIME_FORMAT) !== $time) {
            throw self::broken();
        }

        return new self($id, $at, 0, $hit);
    }

    private static function broken(): ApiProblemException
    {
        return ApiProblemException::invalidCursor('The cursor is broken. Start the list again without it.');
    }
}
