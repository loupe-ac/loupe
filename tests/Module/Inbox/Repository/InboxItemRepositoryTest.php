<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Repository;

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

    public function test_a_stale_managed_item_reads_the_stored_mark_after_the_reload(): void
    {
        $project = $this->inboxProject($this->em, $this->signedUpUser($this->em, 'inbox-repo'));
        $item = $this->question($this->em, $project, 1);
        self::assertFalse($item->respondedThroughAgent);
        $this->em->getConnection()->executeStatement(
            'UPDATE inbox_items SET responded_through_agent = true WHERE id = ?',
            [(string) $item->id],
        );

        $this->items->reloadChangeableColumns([$item]);

        self::assertTrue($item->respondedThroughAgent);
        $this->em->flush();
        $this->em->clear();
        $stored = $this->em->find(InboxItem::class, $item->id);
        self::assertInstanceOf(InboxItem::class, $stored);
        self::assertTrue($stored->respondedThroughAgent);
    }

    public function test_write_response_stores_the_mark_and_clears_it(): void
    {
        $project = $this->inboxProject($this->em, $this->signedUpUser($this->em, 'inbox-repo'));
        $item = $this->question($this->em, $project, 1);

        $item->respondedThroughAgent = true;
        $this->items->writeResponse($item);
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM inbox_items WHERE id = ? AND responded_through_agent', [(string) $item->id]));

        $item->respondedThroughAgent = false;
        $this->items->writeResponse($item);
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM inbox_items WHERE id = ? AND responded_through_agent', [(string) $item->id]));
    }
}
