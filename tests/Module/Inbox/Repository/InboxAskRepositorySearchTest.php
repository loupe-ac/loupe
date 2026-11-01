<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Repository;

use App\Doctrine\SearchLanguage;
use App\Module\Inbox\Entity\InboxAsk;
use App\Module\Inbox\Entity\InboxAskOrigin;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\Repository\InboxAskRepository;
use App\Module\Inbox\Service\InboxSearchIndexer;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Inbox\InboxScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class InboxAskRepositorySearchTest extends KernelTestCase
{
    use InboxScenario;

    private EntityManagerInterface $em;
    private InboxAskRepository $asks;
    private Project $project;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $asks = self::getContainer()->get(InboxAskRepository::class);
        self::assertInstanceOf(InboxAskRepository::class, $asks);
        $this->asks = $asks;
        $this->project = $this->inboxProject($this->em, $this->signedUpUser($this->em, 'inbox-search-rows'));
    }

    public function test_an_ask_is_one_row_however_many_of_its_items_match(): void
    {
        $first = $this->indexed($this->question($this->em, $this->project, 1, title: 'Checkout retries'));
        $second = $this->indexed($this->question($this->em, $this->project, 2, title: 'Checkout timeouts'));
        $ask = $this->askHolding($this->em, $this->project, [$first, $second]);

        $page = $this->search('checkout');

        self::assertSame(1, $page->total);
        self::assertSame([(string) $ask->id], $this->ids($page->rows));
    }

    public function test_an_ask_stays_when_only_one_of_its_items_matches(): void
    {
        $match = $this->indexed($this->question($this->em, $this->project, 1, title: 'Checkout retries'));
        $other = $this->indexed($this->todo($this->em, $this->project, 2, 'Rotate the keys'));
        $ask = $this->askHolding($this->em, $this->project, [$other, $match]);

        $page = $this->search('checkout');

        self::assertSame([(string) $ask->id], $this->ids($page->rows));
        $row = $page->rows[0];
        self::assertInstanceOf(InboxAsk::class, $row);
        self::assertCount(2, $row->items);
    }

    public function test_the_best_item_of_an_ask_decides_its_place(): void
    {
        $weak = new InboxItem(project: $this->project, number: 1, kind: InboxItemKind::Question, title: 'Retries', blocking: true, origin: InboxAskOrigin::Agent, body: 'The checkout path retries', options: ['a']);
        $strong = $this->question($this->em, $this->project, 2, title: 'Checkout');
        $this->em->persist($weak);
        $this->em->flush();
        $this->indexed($weak);
        $this->indexed($strong);
        $weakAsk = $this->askHolding($this->em, $this->project, [$weak]);
        $strongAsk = $this->askHolding($this->em, $this->project, [$strong]);

        $page = $this->search('checkout');

        self::assertSame([(string) $strongAsk->id, (string) $weakAsk->id], $this->ids($page->rows));
    }

    public function test_it_pages_asks_and_clamps_a_page_past_the_end(): void
    {
        $ids = [];
        foreach ([1, 2, 3] as $number) {
            $ids[] = (string) $this->askHolding($this->em, $this->project, [$this->indexed($this->question($this->em, $this->project, $number, title: 'Checkout '.$number))])->id;
        }

        $first = $this->search('checkout', null, 1, 2);
        $second = $this->search('checkout', null, 2, 2);
        $past = $this->search('checkout', null, 9, 2);

        self::assertSame(3, $first->total);
        self::assertCount(2, $first->rows);
        self::assertCount(1, $second->rows);
        self::assertSame(2, $past->page);
        self::assertCount(1, $past->rows);
        self::assertEqualsCanonicalizing($ids, [...$this->ids($first->rows), ...$this->ids($second->rows)]);
    }

    public function test_one_page_cuts_asks_and_loose_items_in_one_order(): void
    {
        $first = $this->askHolding($this->em, $this->project, [$this->indexed($this->question($this->em, $this->project, 1, title: 'Checkout retries'))]);
        $loose = $this->answered($this->em, $this->indexed($this->question($this->em, $this->project, 2, title: 'Checkout retries')));
        $third = $this->askHolding($this->em, $this->project, [$this->indexed($this->question($this->em, $this->project, 3, title: 'Checkout retries'))]);

        $firstPage = $this->search('checkout', null, 1, 2);
        $secondPage = $this->search('checkout', null, 2, 2);

        self::assertSame(3, $firstPage->total);
        self::assertSame([(string) $third->id, (string) $loose->id], $this->ids($firstPage->rows));
        self::assertSame([(string) $first->id], $this->ids($secondPage->rows));
    }

    public function test_no_hit_gives_an_empty_first_page(): void
    {
        $page = $this->search('checkout', null, 3);

        self::assertSame(0, $page->total);
        self::assertSame(1, $page->page);
        self::assertSame([], $page->rows);
    }

    public function test_the_type_filter_keeps_an_ask_when_any_of_its_items_has_the_kind(): void
    {
        $question = $this->indexed($this->question($this->em, $this->project, 1, title: 'Checkout retries'));
        $todo = $this->indexed($this->todo($this->em, $this->project, 2, 'Rotate the keys'));
        $both = $this->askHolding($this->em, $this->project, [$question, $todo]);
        $this->askHolding($this->em, $this->project, [$this->indexed($this->question($this->em, $this->project, 3, title: 'Checkout limits'))]);

        $page = $this->search('checkout', InboxItemKind::Todo);

        self::assertSame([(string) $both->id], $this->ids($page->rows));
        self::assertSame(2, $this->search('checkout', InboxItemKind::Question)->total);
    }

    public function test_a_closed_ask_is_found_and_a_match_in_a_closed_item_does_not_add_a_loose_row(): void
    {
        $item = $this->answered($this->em, $this->indexed($this->question($this->em, $this->project, 1, title: 'Checkout retries')));
        $ask = $this->askHolding($this->em, $this->project, [$item], new \DateTimeImmutable('-1 hour'));

        $page = $this->search('checkout');

        self::assertSame([(string) $ask->id], $this->ids($page->rows));
        self::assertSame(InboxItemState::Resolved, $item->state);
    }

    public function test_an_open_item_of_a_closed_ask_comes_back_as_its_own_row_too(): void
    {
        $loose = $this->indexed($this->todo($this->em, $this->project, 1, 'Checkout cleanup'));
        $ask = $this->askHolding($this->em, $this->project, [$loose], new \DateTimeImmutable('-1 hour'));
        $held = $this->indexed($this->question($this->em, $this->project, 2, title: 'Checkout held'));
        $this->askHolding($this->em, $this->project, [$held]);

        $page = $this->search('checkout');

        self::assertSame(3, $page->total);
        self::assertContains((string) $ask->id, $this->ids($page->rows));
        self::assertContains((string) $loose->id, $this->ids($page->rows));
        self::assertNotContains((string) $held->id, $this->ids($page->rows));
    }

    public function test_a_matching_item_that_no_ask_holds_is_found_even_when_closed(): void
    {
        $orphan = $this->answered($this->em, $this->indexed($this->question($this->em, $this->project, 1, title: 'Checkout retries')));

        $page = $this->search('checkout');

        self::assertSame([(string) $orphan->id], $this->ids($page->rows));
    }

    public function test_the_type_filter_applies_to_loose_items(): void
    {
        $loose = $this->indexed($this->todo($this->em, $this->project, 1, 'Checkout cleanup'));
        $this->askHolding($this->em, $this->project, [$loose], new \DateTimeImmutable('-1 hour'));

        self::assertSame(2, $this->search('checkout', InboxItemKind::Todo)->total);
        self::assertSame(0, $this->search('checkout', InboxItemKind::Review)->total);
    }

    public function test_another_project_is_never_searched(): void
    {
        $other = $this->inboxProject($this->em, $this->signedUpUser($this->em, 'inbox-search-rows-other'));
        $this->askHolding($this->em, $other, [$this->indexed($this->question($this->em, $other, 1, title: 'Checkout retries'))]);

        self::assertSame(0, $this->search('checkout')->total);
    }

    private function search(string $query, ?InboxItemKind $kind = null, int $page = 1, int $perPage = 25): \App\Module\Inbox\Repository\InboxSearchPage
    {
        $this->em->clear();

        return $this->asks->searchRows($this->project, $query, $kind, [SearchLanguage::DEFAULT], $page, $perPage);
    }

    private function indexed(InboxItem $item): InboxItem
    {
        $indexer = self::getContainer()->get(InboxSearchIndexer::class);
        self::assertInstanceOf(InboxSearchIndexer::class, $indexer);
        $indexer->index($item);

        return $item;
    }

    /**
     * @param list<InboxAsk|InboxItem> $rows
     *
     * @return list<string>
     */
    private function ids(array $rows): array
    {
        return array_map(static fn (InboxAsk|InboxItem $row): string => (string) $row->id, $rows);
    }
}
