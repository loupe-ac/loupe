<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Service;

use App\Module\Account\Entity\User;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Service\StoredCardMarkers;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\MarkerKind;
use App\Module\Workflow\Contract\StoredMarker;
use App\Tests\Support\MarkerMother;
use PHPUnit\Framework\TestCase;

final class StoredCardMarkersTest extends TestCase
{
    public function test_a_card_with_no_stored_markers_has_an_empty_set(): void
    {
        $markers = StoredCardMarkers::of($this->card(null));

        self::assertTrue($markers->isEmpty());
        self::assertNull($markers->state());
        self::assertSame([], $markers->others());
    }

    public function test_the_first_marker_that_is_not_a_chip_is_the_state_and_the_rest_keep_their_order(): void
    {
        $stuck = MarkerMother::marker('checks_failed', MarkerKind::Stuck, ['%number%' => 5]);
        $waiting = MarkerMother::marker('held_by_blocker', MarkerKind::Waiting);
        $chip = MarkerMother::marker('unmanaged', MarkerKind::Chip);

        $markers = MarkerMother::markers($stuck, $waiting, $chip);

        self::assertSame($stuck, $markers->state());
        self::assertSame([$waiting, $chip], $markers->others());
        self::assertFalse($markers->isEmpty());
    }

    public function test_a_card_with_only_chips_has_no_state_and_lists_every_chip_as_other(): void
    {
        $paused = MarkerMother::marker('a_note', MarkerKind::Chip);
        $unmanaged = MarkerMother::marker('unmanaged', MarkerKind::Chip);

        $markers = MarkerMother::markers($paused, $unmanaged);

        self::assertNull($markers->state());
        self::assertSame([$paused, $unmanaged], $markers->others());
    }

    public function test_it_reads_the_stored_entries_of_a_card(): void
    {
        $markers = StoredCardMarkers::of($this->card([
            ['key' => 'checks_failed', 'kind' => 'stuck', 'label' => 'marker.checks_failed', 'remedy' => 'marker.checks_failed.remedy', 'params' => ['%number%' => 5], 'since' => '2026-10-02T09:45:00+00:00'],
            ['key' => 'unmanaged', 'kind' => 'chip', 'label' => 'marker.unmanaged', 'remedy' => null, 'params' => [], 'since' => '2026-10-02T09:00:00+00:00'],
        ]));

        $state = $markers->state();
        self::assertNotNull($state);
        self::assertSame('checks_failed', $state->key);
        self::assertSame(MarkerKind::Stuck, $state->kind);
        self::assertSame(['%number%' => 5], $state->params);
        self::assertSame('2026-10-02T09:45:00+00:00', $state->since->format(\DATE_ATOM));
        self::assertSame(['unmanaged'], array_map(static fn ($marker): string => $marker->key, $markers->others()));
        self::assertNull($markers->others()[0]->remedy);
    }

    public function test_the_digest_repeats_for_the_same_markers_and_changes_with_each_part(): void
    {
        $base = MarkerMother::markers(MarkerMother::marker('checks_failed', MarkerKind::Stuck, ['%number%' => 5]));

        $digests = [
            $base->digest(),
            MarkerMother::markers(MarkerMother::marker('conflicting', MarkerKind::Stuck, ['%number%' => 5]))->digest(),
            MarkerMother::markers(MarkerMother::marker('checks_failed', MarkerKind::Stuck, ['%number%' => 6]))->digest(),
            MarkerMother::markers(MarkerMother::marker('checks_failed', MarkerKind::Stuck, ['%number%' => 5], '2026-10-02 10:00:00'))->digest(),
            MarkerMother::markers(MarkerMother::marker('checks_failed', MarkerKind::Stuck, ['%number%' => 5]), MarkerMother::marker('unmanaged', MarkerKind::Chip))->digest(),
            MarkerMother::markers(MarkerMother::marker('checks_failed', MarkerKind::NeedsYou, ['%number%' => 5]))->digest(),
            MarkerMother::markers(MarkerMother::marker('checks_failed', MarkerKind::Stuck, ['%number%' => 5], remedy: 'marker.other.remedy'))->digest(),
            MarkerMother::markers(new StoredMarker('checks_failed', MarkerKind::Stuck, 'marker.other', 'marker.checks_failed.remedy', ['%number%' => 5], new \DateTimeImmutable('2026-10-02 09:45:00')))->digest(),
        ];

        self::assertMatchesRegularExpression('/^[0-9a-f]{12}$/', $digests[0]);
        self::assertSame($digests[0], MarkerMother::markers(MarkerMother::marker('checks_failed', MarkerKind::Stuck, ['%number%' => 5]))->digest());
        self::assertCount(8, array_unique($digests));
    }

    public function test_the_digest_ignores_the_order_of_the_params(): void
    {
        $one = MarkerMother::markers(MarkerMother::marker('waits_for_parent_card', MarkerKind::Waiting, ['%number%' => 1, '%title%' => 'x']));
        $two = MarkerMother::markers(MarkerMother::marker('waits_for_parent_card', MarkerKind::Waiting, ['%title%' => 'x', '%number%' => 1]));

        self::assertSame($one->digest(), $two->digest());
    }

    /** @param ?list<array{key: string, kind: string, label: string, remedy: ?string, params: array<string, string|int>, since: string}> $entries */
    private function card(?array $entries): Card
    {
        $project = new Project(new User(fullName: 'Owner', email: 'owner@example.com', password: 'hashed'), 'p');
        $card = new Card(project: $project, column: new BoardColumn(project: $project, label: 'Backlog', slug: 'backlog', position: 0), title: 'Ship the board', body: 'Body', number: 1);
        $card->markers = $entries;

        return $card;
    }
}
