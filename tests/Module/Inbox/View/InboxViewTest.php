<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\View;

use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\View\InboxSide;
use App\Module\Inbox\View\InboxView;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

final class InboxViewTest extends TestCase
{
    public function test_an_empty_address_is_the_default_view(): void
    {
        $view = InboxView::fromValues([]);

        self::assertSame(InboxSide::Open, $view->side);
        self::assertNull($view->type);
        self::assertSame('', $view->query);
        self::assertNull($view->askId);
        self::assertNull($view->itemNumber);
        self::assertSame(1, $view->page);
        self::assertFalse($view->isPicked());
        self::assertFalse($view->isSearch());
        self::assertSame([], $view->toQuery());
    }

    public function test_every_value_parses(): void
    {
        $id = Uuid::v7()->toRfc4122();
        $view = InboxView::fromValues(['side' => 'closed', 'type' => 'workflow', 'q' => '  checkout ', 'ask' => $id, 'page' => '3']);

        self::assertSame(InboxSide::Closed, $view->side);
        self::assertSame(InboxItemKind::Workflow, $view->type);
        self::assertSame('checkout', $view->query);
        self::assertSame($id, $view->askId?->toRfc4122());
        self::assertSame(3, $view->page);
        self::assertTrue($view->isPicked());
        self::assertTrue($view->isSearch());
        self::assertSame(['side' => 'closed', 'type' => 'workflow', 'q' => 'checkout', 'ask' => $id, 'page' => 3], $view->toQuery());
    }

    public function test_the_completed_queue_of_an_old_link_reads_as_the_closed_side(): void
    {
        self::assertSame(InboxSide::Closed, InboxView::fromValues(['queue' => 'completed'])->side);
        self::assertSame(InboxSide::Open, InboxView::fromValues(['queue' => 'completed', 'side' => 'open'])->side);
    }

    #[DataProvider('badValues')]
    public function test_a_bad_value_falls_back_to_its_default(string $key, mixed $value): void
    {
        $view = InboxView::fromValues([$key => $value]);

        self::assertSame([], $view->toQuery());
        self::assertFalse($view->isPicked());
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function badValues(): iterable
    {
        yield 'unknown side' => ['side', 'sideways'];
        yield 'array side' => ['side', ['closed']];
        yield 'unknown type' => ['type', 'banana'];
        yield 'notice type' => ['type', 'notice'];
        yield 'all type' => ['type', 'all'];
        yield 'array query' => ['q', ['a']];
        yield 'bad ask' => ['ask', 'not-a-uuid'];
        yield 'zero item' => ['item', '0'];
        yield 'negative item' => ['item', '-4'];
        yield 'word item' => ['item', 'four'];
        yield 'huge item' => ['item', '99999999999'];
        yield 'zero page' => ['page', '0'];
        yield 'word page' => ['page', 'two'];
        yield 'huge page' => ['page', '999999999999999999999'];
    }

    public function test_an_ask_wins_over_an_item(): void
    {
        $id = Uuid::v7()->toRfc4122();
        $view = InboxView::fromValues(['ask' => $id, 'item' => '4']);

        self::assertSame($id, $view->askId?->toRfc4122());
        self::assertNull($view->itemNumber);
    }

    public function test_an_item_is_picked(): void
    {
        $view = InboxView::fromValues(['item' => '12']);

        self::assertSame(12, $view->itemNumber);
        self::assertTrue($view->isPicked());
        self::assertSame(['item' => 12], $view->toQuery());
    }

    public function test_the_side_rides_a_search_so_clearing_it_returns_to_that_side(): void
    {
        $view = new InboxView(query: 'checkout');

        self::assertSame(['side' => 'open', 'q' => 'checkout'], $view->toQuery());
        self::assertSame([], $view->withQuery('')->toQuery());
    }

    public function test_the_withers_reset_what_the_change_makes_stale(): void
    {
        $id = Uuid::v7();
        $view = new InboxView(askId: $id, page: 4);

        self::assertSame(['type' => 'todo'], $view->withType(InboxItemKind::Todo)->toQuery());
        self::assertSame(['ask' => $id->toRfc4122(), 'page' => 2], $view->withPage(2)->toQuery());
        self::assertSame(['page' => 4], $view->withoutSelection()->toQuery());
        self::assertSame(['ask' => $id->toRfc4122(), 'page' => 4], $view->withAsk($id)->toQuery());
        self::assertSame(['item' => 9, 'page' => 4], $view->withItem(9)->toQuery());
    }

    public function test_the_switch_keeps_the_type_and_drops_the_rest(): void
    {
        $view = new InboxView(InboxSide::Open, InboxItemKind::Review, 'checkout', Uuid::v7(), null, 3);

        self::assertSame(['side' => 'closed', 'type' => 'review'], $view->onSide(InboxSide::Closed)->toQuery());
        self::assertSame(['type' => 'review'], $view->onSide(InboxSide::Open)->toQuery());
    }

    public function test_it_reads_the_query_bag_of_a_request(): void
    {
        $view = InboxView::fromQuery(Request::create('/inbox', Request::METHOD_GET, ['side' => 'closed', 'page' => '2'])->query);

        self::assertSame(['side' => 'closed', 'page' => 2], $view->toQuery());
    }

    public function test_a_view_round_trips_through_its_query(): void
    {
        $view = new InboxView(InboxSide::Closed, InboxItemKind::Review, 'a b', null, 7, 2);

        self::assertEquals($view, InboxView::fromValues($view->toQuery()));
    }
}
