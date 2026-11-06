<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Repository;

use App\Module\Inbox\Entity\InboxCloseCause;
use App\Module\Inbox\Entity\InboxCloser;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Repository\InboxItemRepository;
use App\Tests\Module\Inbox\InboxScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class InboxItemRepositoryTest extends KernelTestCase
{
    use InboxScenario;

    private EntityManagerInterface $em;
    private InboxItemRepository $items;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $items = self::getContainer()->get(InboxItemRepository::class);
        self::assertInstanceOf(InboxItemRepository::class, $items);
        $this->items = $items;
    }

    public function test_it_counts_the_rows_the_open_side_of_the_inbox_lists(): void
    {
        $owner = $this->signedUpUser($this->em, 'inbox-rows');
        $project = $this->inboxProject($this->em, $owner);
        $this->askHolding($this->em, $project, [$this->question($this->em, $project, 1), $this->question($this->em, $project, 2)]);
        $this->askHolding($this->em, $project, [$this->todo($this->em, $project, 3)], closedAt: new \DateTimeImmutable());
        $this->askHolding($this->em, $project, [$this->answered($this->em, $this->question($this->em, $project, 4))]);
        $this->todo($this->em, $project, 5);
        $this->answered($this->em, $this->question($this->em, $project, 6));
        $this->askHolding($this->em, $project, []);
        $quiet = $this->inboxProject($this->em, $owner);

        self::assertSame(4, $this->items->countOpenRowsByProject($project));
        self::assertSame([(string) $project->id => 4], $this->items->countOpenRowsByProjects([$project, $quiet]));
        self::assertSame(0, $this->items->countOpenRowsByProject($quiet));
    }

    public function test_a_stale_managed_item_reads_the_stored_close_record_after_the_reload(): void
    {
        $project = $this->inboxProject($this->em, $this->signedUpUser($this->em, 'inbox-repo'));
        $item = $this->question($this->em, $project, 1);
        self::assertNull($item->closedBy);
        $this->em->getConnection()->executeStatement(
            "UPDATE inbox_items SET closed_by = 'owner-through-agent', close_cause = 'withdrawn', note = 'Stored note' WHERE id = ?",
            [(string) $item->id],
        );

        $this->items->reloadChangeableColumns([$item]);

        self::assertSame([InboxCloser::OwnerThroughAgent, InboxCloseCause::Withdrawn, 'Stored note'], [$item->closedBy, $item->closeCause, $item->note]);
        $this->em->flush();
        $this->em->clear();
        $stored = $this->em->find(InboxItem::class, $item->id);
        self::assertInstanceOf(InboxItem::class, $stored);
        self::assertSame(InboxCloser::OwnerThroughAgent, $stored->closedBy);
    }

    public function test_write_response_stores_the_close_record_and_clears_it(): void
    {
        $project = $this->inboxProject($this->em, $this->signedUpUser($this->em, 'inbox-repo'));
        $item = $this->question($this->em, $project, 1);

        $item->closedBy = InboxCloser::OwnerThroughAgent;
        $item->note = 'Because';
        $this->items->writeResponse($item);
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM inbox_items WHERE id = ? AND closed_by = 'owner-through-agent' AND note = 'Because'", [(string) $item->id]));

        $item->closedBy = null;
        $item->note = null;
        $this->items->writeResponse($item);
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM inbox_items WHERE id = ? AND (closed_by IS NOT NULL OR note IS NOT NULL)', [(string) $item->id]));
    }
}
