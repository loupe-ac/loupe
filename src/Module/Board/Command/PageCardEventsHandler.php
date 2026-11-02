<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Api\Pagination\Cursor;
use App\Api\Pagination\Page;
use App\Api\Problem\ApiProblemException;
use App\Module\Board\Entity\CardEvent;
use App\Module\Board\Repository\CardEventRepository;
use Symfony\Component\Uid\Uuid;

/** Reads one keyset page of a card's history, newest first. */
final readonly class PageCardEventsHandler
{
    /** The cursor sort of a history that reads newest first. */
    public const string NEWEST_FIRST = '-occurredAt';

    private const string TIME_FORMAT = 'Y-m-d H:i:s.u';

    public function __construct(
        private CardEventRepository $cardEvents,
    ) {
    }

    /** @throws ApiProblemException `invalid_cursor` for a cursor whose time or id does not parse */
    public function __invoke(PageCardEventsCommand $command): PageCardEventsView
    {
        $rows = $this->cardEvents->findPageForCardAfter(
            $command->card,
            $command->page->fetchSize(),
            null === $command->page->cursor ? null : self::positionOf($command->page->cursor),
        );

        return new PageCardEventsView(Page::fromRows($rows, $command->page, static fn (CardEvent $event): Cursor => new Cursor(
            self::NEWEST_FIRST,
            $event->occurredAt->format(self::TIME_FORMAT),
            (string) $event->id,
        )));
    }

    /** @return array{\DateTimeImmutable, Uuid} */
    private static function positionOf(Cursor $cursor): array
    {
        $at = \DateTimeImmutable::createFromFormat('!'.self::TIME_FORMAT, $cursor->sortValue);
        if (false === $at || !Uuid::isValid($cursor->id)) {
            throw ApiProblemException::invalidCursor('The cursor is broken. Start the list again without it.');
        }

        return [$at, Uuid::fromString($cursor->id)];
    }
}
