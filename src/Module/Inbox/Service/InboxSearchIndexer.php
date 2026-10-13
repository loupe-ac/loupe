<?php

declare(strict_types=1);

namespace App\Module\Inbox\Service;

use App\Doctrine\FullTextSearch;
use App\Module\Inbox\Entity\InboxItem;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Rebuilds the search vector of an item from its title, its body and the
 * titles of its linked cards. A caller runs it after the flush that wrote the row.
 */
final readonly class InboxSearchIndexer
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function index(InboxItem $item): void
    {
        $this->rebuild('id = :id', ['id' => (string) $item->id]);
    }

    /** A rename of the card changes the vector of every item linked to it. */
    public function reindexForCard(Uuid $cardId): void
    {
        $this->rebuild(
            'id IN (SELECT item_id FROM inbox_item_cards WHERE card_id = :card)',
            ['card' => (string) $cardId],
        );
    }

    /** @param array<string, string> $parameters */
    private function rebuild(string $where, array $parameters): void
    {
        // COALESCE, because the body is nullable and a NULL operand turns the
        // whole vector NULL, which leaves the title unsearchable too. The linked
        // card titles go in too, because a wait item names its card only by number.
        $this->connection->executeStatement(
            \sprintf(
                <<<'SQL'
                    UPDATE inbox_items
                    SET search_vector = setweight(to_tsvector(search_language::regconfig, title), '%1$s')
                        || setweight(to_tsvector(search_language::regconfig, COALESCE(body, '')), '%2$s')
                        || setweight(to_tsvector(search_language::regconfig, COALESCE((
                            SELECT string_agg(card.title, ' ')
                            FROM inbox_item_cards link
                            JOIN board_cards card ON card.id = link.card_id
                            WHERE link.item_id = inbox_items.id
                        ), '')), '%2$s')
                    WHERE %3$s
                    SQL,
                FullTextSearch::TITLE_WEIGHT,
                FullTextSearch::BODY_WEIGHT,
                $where,
            ),
            $parameters,
        );
    }
}
