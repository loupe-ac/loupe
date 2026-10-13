<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Service;

use App\Module\Account\Entity\User;
use App\Module\Board\Command\CardProgress;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Service\CardDigest;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\View\CardRunWarning;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\MarkerKind;
use App\Tests\Support\MarkerMother;
use PHPUnit\Framework\TestCase;

final class CardDigestTest extends TestCase
{
    public function test_the_digest_is_a_twelve_character_hash_that_repeats_for_the_same_face(): void
    {
        $card = $this->makeCard();
        $digest = new CardDigest()->forCard($card, 0, 0, 0, null, null, null);

        self::assertMatchesRegularExpression('/^[0-9a-f]{12}$/', $digest);
        self::assertSame($digest, new CardDigest()->forCard($card, 0, 0, 0, null, null, null));
    }

    public function test_the_digest_ignores_the_position_because_a_renumber_moves_no_card_face(): void
    {
        $card = $this->makeCard();
        $card->position = 2;
        $before = $this->digest($card);

        $card->position = 0;
        self::assertSame($before, $this->digest($card));
    }

    public function test_the_digest_ignores_the_body_because_the_card_face_does_not_show_it(): void
    {
        $card = $this->makeCard();
        $before = $this->digest($card);

        $card->body = 'A new body';
        self::assertSame($before, $this->digest($card));
    }

    public function test_the_digest_changes_with_the_parent_title_and_number(): void
    {
        $card = $this->makeCard();
        $parent = new Card(project: $card->project, column: $card->column, title: 'Epic', body: '', number: 7);
        $none = $this->digest($card);

        $card->parent = $parent;
        $withParent = $this->digest($card);
        self::assertNotSame($none, $withParent);

        $parent->title = 'Renamed epic';
        $renamed = $this->digest($card);
        self::assertNotSame($withParent, $renamed);

        $card->parent = new Card(project: $card->project, column: $card->column, title: 'Renamed epic', body: '', number: 8);
        self::assertNotSame($renamed, $this->digest($card));
    }

    public function test_the_digest_changes_with_the_progress(): void
    {
        $card = $this->makeCard();
        $digest = new CardDigest();

        $none = $digest->forCard($card, 0, 0, 0, null, null, null);
        $one = $digest->forCard($card, 0, 0, 0, new CardProgress(1, 3), null, null);
        $two = $digest->forCard($card, 0, 0, 0, new CardProgress(2, 3), null, null);
        $more = $digest->forCard($card, 0, 0, 0, new CardProgress(2, 4), null, null);

        self::assertCount(4, array_unique([$none, $one, $two, $more]));
    }

    public function test_the_digest_changes_with_the_pull_request_count(): void
    {
        $card = $this->makeCard();

        self::assertNotSame(
            new CardDigest()->forCard($card, 0, 0, 0, null, null, null),
            new CardDigest()->forCard($card, 0, 0, 1, null, null, null),
        );
    }

    public function test_the_digest_changes_with_a_warning(): void
    {
        $card = $this->makeCard();
        $digest = new CardDigest();

        $none = $digest->forCard($card, 0, 0, 0, null, null, null);
        $gaveUp = $digest->forCard($card, 0, 0, 0, null, new CardRunWarning('run-1', WorkerRunState::GaveUp, 'Tests fail.'), null);
        $blocked = $digest->forCard($card, 0, 0, 0, null, new CardRunWarning('run-1', WorkerRunState::Blocked, 'Tests fail.'), null);
        $otherRun = $digest->forCard($card, 0, 0, 0, null, new CardRunWarning('run-2', WorkerRunState::GaveUp, 'Tests fail.'), null);

        self::assertCount(4, array_unique([$none, $gaveUp, $blocked, $otherRun]));
    }

    public function test_the_digest_changes_with_the_markers_of_the_card(): void
    {
        $card = $this->makeCard();
        $digest = new CardDigest();
        $stuck = MarkerMother::markers(MarkerMother::marker('checks_failed', MarkerKind::Stuck, ['%number%' => 5]));
        $working = MarkerMother::markers(MarkerMother::marker('run_open', MarkerKind::Working));
        $both = MarkerMother::markers(MarkerMother::marker('checks_failed', MarkerKind::Stuck, ['%number%' => 5]), MarkerMother::marker('unmanaged', MarkerKind::Chip));
        $later = MarkerMother::markers(MarkerMother::marker('checks_failed', MarkerKind::Stuck, ['%number%' => 5], '2026-10-02 10:00:00'));

        $digests = [
            $digest->forCard($card, 0, 0, 0, null, null, null),
            $digest->forCard($card, 0, 0, 0, null, null, $stuck),
            $digest->forCard($card, 0, 0, 0, null, null, $working),
            $digest->forCard($card, 0, 0, 0, null, null, $both),
            $digest->forCard($card, 0, 0, 0, null, null, $later),
        ];

        self::assertCount(5, array_unique($digests));
        self::assertSame($digests[1], $digest->forCard($card, 0, 0, 0, null, null, $stuck));
    }

    public function test_an_empty_set_of_markers_digests_like_no_markers(): void
    {
        $card = $this->makeCard();
        $digest = new CardDigest();

        self::assertSame($digest->forCard($card, 0, 0, 0, null, null, null), $digest->forCard($card, 0, 0, 0, null, null, MarkerMother::markers()));
    }

    private function digest(Card $card): string
    {
        return new CardDigest()->forCard($card, 0, 0, 0, null, null, null);
    }

    private function makeCard(): Card
    {
        $project = new Project(new User(fullName: 'Owner', email: 'owner@example.com', password: 'hashed'), 'p');

        return new Card(project: $project, column: new BoardColumn(project: $project, label: 'Backlog', slug: 'backlog', position: 0), title: 'Ship the board', body: 'Body', number: 1);
    }
}
