<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Service;

use App\Module\Account\Entity\User;
use App\Module\Inbox\Entity\InboxCardWait;
use App\Module\Inbox\Entity\InboxCardWaitType;
use App\Module\Inbox\Entity\InboxCardWatch;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Service\WantedCardWait;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\MarkerKind;
use App\Module\Workflow\Contract\MarkerSubject;
use App\Module\Workflow\Contract\MarkerSubjectType;
use App\Module\Workflow\Contract\StoredMarker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class WantedCardWaitTest extends TestCase
{
    public function test_a_stored_entry_gives_a_wait_with_its_marker_text_and_start_time(): void
    {
        $pullRequestId = Uuid::v7();
        $since = new \DateTimeImmutable('2026-10-20 09:00:00');
        $marker = new StoredMarker(
            'waits-for-approval',
            MarkerKind::NeedsYou,
            'marker.waits_for_approval',
            'marker.waits_for_approval.remedy',
            ['%number%' => 12],
            $since,
            subject: new MarkerSubject(MarkerSubjectType::PullRequest, ['pullRequest' => $pullRequestId->toRfc4122(), 'head' => 'abc123']),
        );

        $wait = WantedCardWait::fromMarker($marker);

        self::assertSame('waits-for-approval', $wait->markerKey);
        self::assertSame('marker.waits_for_approval', $wait->label);
        self::assertSame(['%number%' => 12], $wait->params);
        self::assertSame(InboxCardWaitType::PullRequest, $wait->type());
        self::assertSame($since, $wait->since);
        self::assertSame(\sprintf('waits-for-approval:%s:abc123', $pullRequestId->toRfc4122()), $wait->key());
    }

    public function test_new_values_replace_the_values_of_a_wait(): void
    {
        $since = new \DateTimeImmutable('2026-10-20 09:00:00');
        $marker = new StoredMarker('run-needs-you', MarkerKind::Stuck, 'marker.run_needs_you', null, ['%a%' => 1], $since, subject: new MarkerSubject(MarkerSubjectType::WorkerRun, ['run' => Uuid::v7()->toRfc4122()]));
        $wait = WantedCardWait::fromMarker($marker);

        $changed = $wait->withParams(['%headline%' => 'No network']);

        self::assertSame(['%headline%' => 'No network'], $changed->params);
        self::assertSame($wait->key(), $changed->key());
        self::assertSame($since, $changed->since);
        self::assertSame('marker.run_needs_you', $changed->label);
    }

    public function test_an_entry_without_a_subject_gives_no_wait(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        WantedCardWait::fromMarker(new StoredMarker('paused', MarkerKind::Stuck, 'marker.paused', null, [], new \DateTimeImmutable()));
    }

    public function test_a_card_entry_is_keyed_by_the_card_and_the_start_of_its_spell(): void
    {
        $cardId = Uuid::v7();
        $spell = new \DateTimeImmutable('2026-10-20 09:00:00', new \DateTimeZone('UTC'));
        $marker = new StoredMarker('nothing-happened', MarkerKind::Stuck, 'marker.nothing_happened', null, [], $spell, subject: MarkerSubject::card($cardId, $spell));

        $wait = WantedCardWait::fromMarker($marker);

        self::assertSame(InboxCardWaitType::Card, $wait->type());
        self::assertSame(\sprintf('nothing-happened:%s:2026-10-20T09:00:00+00:00', $cardId->toRfc4122()), $wait->key());
    }

    /**
     * A row of the previous release has the marker key that the migration filled from its trigger. The entry
     * of the new engine for the same thing makes the same key, so a dismissal of the old row still holds.
     *
     * @return iterable<string, array{string, string, MarkerSubject, array<string, mixed>}>
     */
    public static function legacyRows(): iterable
    {
        $id = Uuid::v7();
        $other = Uuid::v7();

        yield 'pull request' => ['waits-for-approval', 'pull-request-ready', new MarkerSubject(MarkerSubjectType::PullRequest, ['pullRequest' => $id->toRfc4122(), 'head' => 'abc123']), ['type' => InboxCardWaitType::PullRequest, 'pullRequestId' => $id, 'headSha' => 'abc123']];
        yield 'document' => ['document-in-review', 'document-in-review', new MarkerSubject(MarkerSubjectType::Document, ['document' => $id->toRfc4122(), 'version' => '3']), ['type' => InboxCardWaitType::Document, 'documentId' => $id, 'versionNumber' => 3]];
        yield 'run' => ['run-needs-you', 'run-gave-up', new MarkerSubject(MarkerSubjectType::WorkerRun, ['run' => $other->toRfc4122()]), ['type' => InboxCardWaitType::WorkerRun, 'runId' => $other]];
        yield 'pause' => ['paused', 'card-paused', new MarkerSubject(MarkerSubjectType::CardPause, ['pause' => $other->toRfc4122()]), ['type' => InboxCardWaitType::CardPause, 'pauseId' => $other]];
    }

    /** @param array<string, mixed> $legacy */
    #[DataProvider('legacyRows')]
    public function test_the_key_of_a_row_of_the_previous_release_equals_the_key_of_the_new_entry(string $markerKey, string $trigger, MarkerSubject $subject, array $legacy): void
    {
        $project = new Project(new User(fullName: 'Riley', email: 'riley@example.com', password: 'hashed'), 'Widgets');
        $watch = new InboxCardWatch(new InboxItem(project: $project, number: 1, kind: InboxItemKind::Wait, title: '#1 Ship it', blocking: true), Uuid::v7(), 1);
        $row = new InboxCardWait($watch, $markerKey, $legacy['type'], '', $legacy['documentId'] ?? null, $legacy['versionNumber'] ?? null, $legacy['runId'] ?? null, $legacy['pullRequestId'] ?? null, $legacy['headSha'] ?? null, pauseId: $legacy['pauseId'] ?? null);
        $entry = new StoredMarker($markerKey, MarkerKind::NeedsYou, 'marker.'.str_replace('-', '_', $markerKey), null, [], new \DateTimeImmutable(), subject: $subject);

        self::assertSame($row->key(), WantedCardWait::fromMarker($entry)->key(), $trigger);
    }
}
