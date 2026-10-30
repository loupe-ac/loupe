<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\View;

use App\Module\Account\Entity\User;
use App\Module\Inbox\Entity\InboxAsk;
use App\Module\Inbox\Entity\InboxAskOrigin;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\View\InboxOutcomeLabel;
use App\Module\Inbox\View\InboxRow;
use App\Module\Inbox\View\InboxView;
use App\Module\Project\Entity\Project;
use PHPUnit\Framework\TestCase;

final class InboxRowTest extends TestCase
{
    public function test_an_open_ask_takes_its_icon_from_the_first_open_item(): void
    {
        $row = new InboxRow($this->ask(), [
            $this->item(1, InboxItemKind::Question, state: InboxItemState::Resolved),
            $this->item(2, InboxItemKind::Todo),
        ]);

        self::assertSame(InboxItemKind::Todo, $row->kind());
        self::assertSame('First', $row->title());
    }

    public function test_a_closed_ask_takes_its_icon_from_its_first_item(): void
    {
        $ask = $this->ask();
        $ask->closedAt = new \DateTimeImmutable();
        $row = new InboxRow($ask, [
            $this->item(1, InboxItemKind::Question, state: InboxItemState::Resolved),
            $this->item(2, InboxItemKind::Todo),
        ], new InboxOutcomeLabel('inbox.outcome.owner.answered'));

        self::assertSame(InboxItemKind::Question, $row->kind());
        self::assertTrue($row->isClosed());
    }

    public function test_only_an_open_blocking_item_makes_a_row_blocking(): void
    {
        self::assertTrue((new InboxRow($this->ask(), [$this->item(1, InboxItemKind::Question, blocking: true)]))->blocking);
        self::assertFalse((new InboxRow($this->ask(), [$this->item(1, InboxItemKind::Question, blocking: true, state: InboxItemState::Resolved)]))->blocking);
        self::assertFalse((new InboxRow($this->ask(), [$this->item(1, InboxItemKind::Todo)]))->blocking);
    }

    public function test_a_loose_row_links_by_item_number_and_an_ask_row_by_id(): void
    {
        $loose = new InboxRow(null, [$this->item(7, InboxItemKind::Todo)]);

        self::assertTrue($loose->isLoose());
        self::assertSame('item-7', $loose->key());
        self::assertSame(['item' => 7], $loose->pickedIn(new InboxView())->toQuery());
    }

    public function test_a_row_holds_at_least_one_item(): void
    {
        $this->expectException(\LogicException::class);

        new InboxRow($this->ask(), []);
    }

    public function test_a_long_title_is_cut_for_the_list_row_and_a_short_one_is_not(): void
    {
        $long = new InboxItem($this->project(), 1, InboxItemKind::Wait, str_repeat('word ', 30), false, origin: InboxAskOrigin::Loupe);

        self::assertSame(80, mb_strlen(new InboxRow($this->ask(), [$long])->shortTitle()));
        self::assertStringEndsWith('…', new InboxRow($this->ask(), [$long])->shortTitle());
        self::assertSame('First', new InboxRow($this->ask(), [$this->item(1, InboxItemKind::Question)])->shortTitle());
    }

    public function test_a_row_with_no_card_has_no_first_card(): void
    {
        $row = new InboxRow($this->ask(), [$this->item(1, InboxItemKind::Question)]);

        self::assertNull($row->firstCard());
        self::assertSame(0, $row->moreCards());
    }

    private function ask(): InboxAsk
    {
        return new InboxAsk($this->project(), null);
    }

    private function item(int $number, InboxItemKind $kind, bool $blocking = false, InboxItemState $state = InboxItemState::Open): InboxItem
    {
        $item = new InboxItem($this->project(), $number, $kind, 1 === $number ? 'First' : 'Item '.$number, $blocking, origin: InboxAskOrigin::Agent);
        $item->state = $state;

        return $item;
    }

    private function project(): Project
    {
        return new Project(new User(fullName: 'Riley Chen', email: 'riley@example.com', password: 'x'), 'row-test');
    }
}
