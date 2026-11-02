<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Service;

use App\Module\Inbox\Entity\InboxAskOrigin;
use App\Module\Inbox\Entity\InboxCardWatch;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Service\InboxWatchLookup;
use App\Tests\Module\Inbox\InboxFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class InboxWatchLookupTest extends KernelTestCase
{
    use InboxFixtures;

    private EntityManagerInterface $em;
    private InboxWatchLookup $lookup;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $lookup = self::getContainer()->get(InboxWatchLookup::class);
        self::assertInstanceOf(InboxWatchLookup::class, $lookup);
        $this->lookup = $lookup;
    }

    public function test_a_preload_reads_the_watches_of_all_items_in_one_query(): void
    {
        $items = $this->waitItems(3);

        $this->lookup->preload($items);
        $reads = $this->countReads(function () use ($items): void {
            foreach ($items as $item) {
                self::assertInstanceOf(InboxCardWatch::class, $this->lookup->forItem($item));
            }
            self::assertCount(3, $this->lookup->forItems($items));
        });

        self::assertSame(0, $reads);
    }

    public function test_an_item_that_was_not_preloaded_is_read_and_not_kept(): void
    {
        [$item] = $this->waitItems(1);

        $first = $this->countReads(fn () => self::assertInstanceOf(InboxCardWatch::class, $this->lookup->forItem($item)));
        $second = $this->countReads(fn () => self::assertInstanceOf(InboxCardWatch::class, $this->lookup->forItem($item)));

        self::assertSame(1, $first);
        self::assertSame(1, $second);
    }

    public function test_an_item_that_is_not_a_wait_item_has_no_watch_and_asks_nothing(): void
    {
        $owner = $this->owner($this->em, 'watch-lookup-plain');
        $project = $this->project($this->em, $owner, 'watch-lookup-plain');
        $item = new InboxItem(project: $project, number: 1, kind: InboxItemKind::Question, title: 'Which?', blocking: true, origin: InboxAskOrigin::Agent);
        $this->em->persist($item);
        $this->em->flush();

        $reads = $this->countReads(function () use ($item): void {
            self::assertNull($this->lookup->forItem($item));
            self::assertSame([], $this->lookup->forItems([$item]));
        });

        self::assertSame(0, $reads);
    }

    public function test_reset_forgets_the_preload(): void
    {
        [$item] = $this->waitItems(1);
        $this->lookup->preload([$item]);
        $this->lookup->reset();

        $reads = $this->countReads(fn () => $this->lookup->forItem($item));

        self::assertSame(1, $reads);
    }

    /** @return list<InboxItem> */
    private function waitItems(int $count): array
    {
        $owner = $this->owner($this->em, 'watch-lookup');
        $project = $this->project($this->em, $owner, 'watch-lookup');
        $items = [];
        for ($number = 1; $number <= $count; ++$number) {
            $item = new InboxItem(project: $project, number: $number, kind: InboxItemKind::Wait, title: '#'.$number.' Ship it', blocking: true, origin: InboxAskOrigin::Loupe);
            $this->em->persist($item);
            $this->em->persist(new InboxCardWatch($item, Uuid::v7(), $number));
            $items[] = $item;
        }
        $this->em->flush();
        $this->em->clear();

        return array_map(function (InboxItem $item): InboxItem {
            $found = $this->em->find(InboxItem::class, $item->id);
            self::assertInstanceOf(InboxItem::class, $found);

            return $found;
        }, $items);
    }

    private function countReads(callable $work): int
    {
        $holder = self::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(DebugDataHolder::class, $holder);
        $holder->reset();
        $work();

        $reads = 0;
        foreach ($holder->getData() as $queries) {
            foreach ($queries as $query) {
                if (str_starts_with((string) $query['sql'], 'SELECT') && str_contains((string) $query['sql'], 'FROM inbox_card_watch')) {
                    ++$reads;
                }
            }
        }

        return $reads;
    }
}
