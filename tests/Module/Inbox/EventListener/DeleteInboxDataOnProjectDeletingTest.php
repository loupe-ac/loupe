<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\EventListener;

use App\Module\Account\Entity\User;
use App\Module\Inbox\Entity\InboxAction;
use App\Module\Inbox\Entity\InboxActionKind;
use App\Module\Inbox\Entity\InboxAskItem;
use App\Module\Inbox\Entity\InboxAskOrigin;
use App\Module\Inbox\Entity\InboxBridgeOutage;
use App\Module\Inbox\Entity\InboxCardWait;
use App\Module\Inbox\Entity\InboxCardWaitType;
use App\Module\Inbox\Entity\InboxCardWatch;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemCard;
use App\Module\Inbox\Entity\InboxItemDocument;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Entity\InboxOpenRouterKeyNotice;
use App\Module\Inbox\Entity\InboxRepositoryMisfit;
use App\Module\Inbox\EventListener\DeleteInboxDataOnProjectDeleting;
use App\Module\Project\Entity\Project;
use App\Module\Project\Event\ProjectDeleting;
use App\Module\Project\Service\ProjectDeleter;
use App\Tests\Module\Inbox\InboxFixtures;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class DeleteInboxDataOnProjectDeletingTest extends KernelTestCase
{
    use InboxFixtures;

    private const array TABLES = ['inbox_items', 'inbox_asks', 'inbox_ask_items', 'inbox_item_cards', 'inbox_item_documents', 'inbox_card_watches', 'inbox_card_waits', 'inbox_actions', 'inbox_bridge_outages', 'inbox_openrouter_key_notices', 'inbox_repository_misfits'];

    private EntityManagerInterface $em;
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->connection = $em->getConnection();
    }

    /**
     * The listener runs alone here, so no cascade from a card, a document or
     * the project row can hide a listener that forgot a table.
     */
    public function test_the_listener_deletes_the_inbox_rows_of_its_project_only(): void
    {
        $owner = $this->owner($this->em, 'inbox-listener');
        [$doomed, $doomedRows] = $this->seedInbox($owner, 'doomed');
        [$spared, $sparedRows] = $this->seedInbox($owner, 'spared');
        $this->em->flush();
        $doomedIds = $this->ids($doomedRows);
        $sparedIds = $this->ids($sparedRows);

        self::assertSame(array_fill_keys(self::TABLES, 1), $this->counts($doomedIds));

        $listener = self::getContainer()->get(DeleteInboxDataOnProjectDeleting::class);
        self::assertInstanceOf(DeleteInboxDataOnProjectDeleting::class, $listener);
        $listener(new ProjectDeleting($doomed));

        self::assertSame(array_fill_keys(self::TABLES, 0), $this->counts($doomedIds));
        self::assertSame(array_fill_keys(self::TABLES, 1), $this->counts($sparedIds));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM projects WHERE id = :id', ['id' => (string) $doomed->id]));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM board_cards WHERE project_id = :id', ['id' => (string) $doomed->id]));
    }

    /**
     * Covers the listener order the container uses: Board, Review and Inbox
     * all delete rows the inbox links point at, and the project delete succeeds.
     */
    public function test_deleting_a_project_with_linked_inbox_rows_succeeds(): void
    {
        $owner = $this->owner($this->em, 'inbox-project-delete');
        [$doomed, $doomedRows] = $this->seedInbox($owner, 'doomed');
        [$spared, $sparedRows] = $this->seedInbox($owner, 'spared');
        $this->em->flush();
        $doomedId = (string) $doomed->id;
        $doomedIds = $this->ids($doomedRows);
        $sparedIds = $this->ids($sparedRows);

        self::assertSame(array_fill_keys(self::TABLES, 1), $this->counts($doomedIds));

        $deleter = self::getContainer()->get(ProjectDeleter::class);
        self::assertInstanceOf(ProjectDeleter::class, $deleter);
        $deleter->delete($doomed);

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM projects WHERE id = :id', ['id' => $doomedId]));
        self::assertSame(array_fill_keys(self::TABLES, 0), $this->counts($doomedIds));
        self::assertSame(array_fill_keys(self::TABLES, 1), $this->counts($sparedIds));
    }

    /**
     * @return array{Project, array<string, object>}
     */
    private function seedInbox(User $owner, string $name): array
    {
        $project = $this->project($this->em, $owner, $name);
        $card = $this->card($this->em, $project);
        $document = $this->document($this->em, $project);
        $item = $this->item($this->em, $project);
        $itemCard = new InboxItemCard($item, $card);
        $item->cards->add($itemCard);
        $itemDocument = new InboxItemDocument($item, $document);
        $item->documents->add($itemDocument);
        $ask = $this->ask($this->em, $project);
        $ask->card = $card;
        $askItem = new InboxAskItem($ask, $item);
        $ask->items->add($askItem);
        $this->em->flush();
        // The watch names its project too, so its foreign key must not block the project delete.
        $waitItem = new InboxItem(project: $project, number: 2, kind: InboxItemKind::Wait, title: '#1 Ship it', blocking: true, origin: InboxAskOrigin::Loupe);
        $watch = new InboxCardWatch($waitItem, $card->id ?? throw new \LogicException('A stored card has an id.'), $card->number);
        $wait = new InboxCardWait($watch, 'document-in-review', InboxCardWaitType::Document, 'marker.document_in_review', $document->id, 1);
        $watch->waits->add($wait);
        $this->em->persist($waitItem);
        $this->em->persist($watch);
        $action = new InboxAction($item, InboxActionKind::Dismiss, $owner);
        $this->em->persist($action);
        $notice = new InboxItem(project: $project, number: 3, kind: InboxItemKind::Notice, title: 'No bridge can take work', blocking: false, origin: InboxAskOrigin::Loupe);
        $this->em->persist($notice);
        $outage = new InboxBridgeOutage($project, $notice, new \DateTimeImmutable('2026-10-16 11:00:00'));
        $this->em->persist($outage);
        $keyNoticeItem = new InboxItem(project: $project, number: 5, kind: InboxItemKind::Notice, title: 'OpenRouter refused the key', blocking: false, origin: InboxAskOrigin::Loupe);
        $this->em->persist($keyNoticeItem);
        $keyNotice = new InboxOpenRouterKeyNotice($project, $keyNoticeItem, Uuid::v7(), new \DateTimeImmutable('2026-10-31 11:00:00'));
        $this->em->persist($keyNotice);
        $misfitNotice = new InboxItem(project: $project, number: 4, kind: InboxItemKind::Notice, title: 'acme/widgets does not fit the workflow', blocking: false, origin: InboxAskOrigin::Loupe);
        $this->em->persist($misfitNotice);
        $misfit = new InboxRepositoryMisfit($project, $misfitNotice, new \DateTimeImmutable('2026-10-27 11:00:00'), []);
        $this->em->persist($misfit);

        return [$project, [
            'inbox_items' => $item,
            'inbox_asks' => $ask,
            'inbox_ask_items' => $askItem,
            'inbox_item_cards' => $itemCard,
            'inbox_item_documents' => $itemDocument,
            'inbox_card_watches' => $watch,
            'inbox_card_waits' => $wait,
            'inbox_actions' => $action,
            'inbox_bridge_outages' => $outage,
            'inbox_openrouter_key_notices' => $keyNotice,
            'inbox_repository_misfits' => $misfit,
        ]];
    }

    /**
     * Read after the flush, so a count never joins through a parent row the
     * delete already removed.
     *
     * @param array<string, object> $rows
     *
     * @return array<string, string>
     */
    private function ids(array $rows): array
    {
        return array_map(static function (object $row): string {
            self::assertTrue(property_exists($row, 'id'));

            return (string) $row->id;
        }, $rows);
    }

    /**
     * @param array<string, string> $ids table name to row id
     *
     * @return array<string, int>
     */
    private function counts(array $ids): array
    {
        $counts = [];
        foreach (self::TABLES as $table) {
            $counts[$table] = (int) $this->connection->fetchOne(\sprintf('SELECT COUNT(*) FROM %s WHERE id = :id', $table), ['id' => $ids[$table]]);
        }

        return $counts;
    }
}
