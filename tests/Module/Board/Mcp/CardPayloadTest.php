<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Mcp;

use App\Module\Account\Entity\User;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPause;
use App\Module\Board\Entity\CardPullRequest;
use App\Module\Board\Entity\Forge;
use App\Module\Board\Mcp\CardPayload;
use App\Module\Board\Repository\CardArtifactRepository;
use App\Module\Board\Repository\CardLinkRepository;
use App\Module\Board\Repository\CardPauseRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Repository\CardSiteReviewCommentRepository;
use App\Module\Board\Repository\CardValueRepository;
use App\Module\Board\Service\CardAgentReviews;
use App\Module\Board\Service\CardMarkerText;
use App\Module\Board\Service\CardPullRequestStates;
use App\Module\Board\Service\CardValueReader;
use App\Module\Board\Service\PullRequestReviewView;
use App\Module\Board\Service\PullRequestStates;
use App\Module\Board\Service\PullRequestStateView;
use App\Module\Forge\Entity\PullRequestChecks;
use App\Module\Forge\Entity\PullRequestMergeability;
use App\Module\Forge\Entity\PullRequestState;
use App\Module\Project\Entity\Project;
use App\Module\SiteReview\Repository\SiteReviewScreenshotRepository;
use App\Module\Workflow\Contract\Actor;
use App\Module\Workflow\Contract\CardValueKeys;
use App\Module\Workflow\Contract\PauseKind;
use App\Tests\Support\ShippedCardTypes;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class CardPayloadTest extends KernelTestCase
{
    public function test_the_list_shape_carries_the_eight_summary_keys_and_the_state_and_reads_nothing_else(): void
    {
        $comments = $this->createMock(CardSiteReviewCommentRepository::class);
        $comments->expects($this->never())->method('findForCards');
        $links = $this->createMock(CardLinkRepository::class);
        $links->expects($this->never())->method('findForCards');
        $cards = $this->createMock(CardRepository::class);
        $cards->expects($this->never())->method('findChildrenOfCards');
        $states = $this->createMock(CardPullRequestStates::class);
        // The state of a row is stored on the card, so a row reads no pull request either.
        $states->expects($this->never())->method('forCards');
        $pauses = $this->createMock(CardPauseRepository::class);
        $pauses->expects($this->never())->method('findActiveForCardIds');

        $rows = new CardPayload($comments, $links, $cards, $states, $pauses, new ShippedCardTypes(), $this->createStub(CardAgentReviews::class), $this->markerText(), $this->createStub(CardArtifactRepository::class), $this->createStub(SiteReviewScreenshotRepository::class), $this->values())->forCardList([$this->card()]);

        self::assertCount(1, $rows);
        self::assertSame(
            ['cardId', 'number', 'title', 'type', 'status', 'reporter', 'parentCardId', 'updatedAt', 'state'],
            array_keys($rows[0]),
        );
        self::assertSame('Drag ordering', $rows[0]['title']);
    }

    public function test_the_full_shape_does_query_the_site_review_comments_and_the_card_links(): void
    {
        $comments = $this->createMock(CardSiteReviewCommentRepository::class);
        // Without these the test above passes even on a payload that never ran
        // the queries on either path.
        $comments->expects($this->once())->method('findForCards')->willReturn([]);
        $links = $this->createMock(CardLinkRepository::class);
        $links->expects($this->once())->method('findForCards')->willReturn([]);
        $feature = $this->card();
        $epic = $this->card('epic');
        $otherEpic = $this->card('epic');
        $cards = $this->createMock(CardRepository::class);
        // One call for the whole page, with the epics alone.
        $cards->expects($this->once())->method('findChildrenOfCards')->with([$epic, $otherEpic])->willReturn([]);
        $pauses = $this->createMock(CardPauseRepository::class);
        $pauses->expects($this->once())->method('findActiveForCardIds')->willReturn([]);

        $rows = new CardPayload($comments, $links, $cards, $this->states(), $pauses, new ShippedCardTypes(), $this->createStub(CardAgentReviews::class), $this->markerText(), $this->createStub(CardArtifactRepository::class), $this->createStub(SiteReviewScreenshotRepository::class), $this->values())->forCards([$feature, $epic, $otherEpic]);

        self::assertArrayHasKey('body', $rows[0]);
        self::assertSame([], $rows[0]['siteReviewComments']);
        self::assertSame([], $rows[0]['relatedCards']);
        self::assertNull($rows[0]['progress']);
        self::assertSame(['done' => 0, 'total' => 0], $rows[1]['progress']);
    }

    public function test_a_page_with_no_epic_reads_no_children(): void
    {
        $cards = $this->createMock(CardRepository::class);
        $cards->expects($this->never())->method('findChildrenOfCards');

        $rows = new CardPayload($this->createStub(CardSiteReviewCommentRepository::class), $this->createStub(CardLinkRepository::class), $cards, $this->states(), $this->createStub(CardPauseRepository::class), new ShippedCardTypes(), $this->createStub(CardAgentReviews::class), $this->markerText(), $this->createStub(CardArtifactRepository::class), $this->createStub(SiteReviewScreenshotRepository::class), $this->values())->forCards([$this->card()]);

        self::assertSame([], $rows[0]['children']);
    }

    public function test_the_full_shape_renders_the_stored_pull_request_state(): void
    {
        $card = $this->card();
        $this->setId($card, Uuid::v7());
        $tracked = new CardPullRequest($card, 'https://github.com/loupe-ac/loupe/pull/7', Forge::GitHub, 'loupe-ac/loupe', 7);
        $this->setId($tracked, Uuid::v7());
        $untracked = new CardPullRequest($card, 'https://example.com/pr/1');
        $this->setId($untracked, Uuid::v7());
        $card->pullRequests->add($tracked);
        $card->pullRequests->add($untracked);
        $states = $this->createMock(CardPullRequestStates::class);
        // One read for the whole page, never one per card.
        $states->expects($this->once())->method('forCards')->with([$card])->willReturn(new PullRequestStates(
            [(string) $tracked->id => new PullRequestStateView(
                PullRequestState::Open,
                true,
                PullRequestChecks::Failed,
                ['phpunit'],
                PullRequestMergeability::Conflicting,
                PullRequestReviewView::ChangesRequested,
                false,
                new \DateTimeImmutable('2026-09-27T11:00:00+00:00'),
            )],
        ));

        $rows = new CardPayload($this->createStub(CardSiteReviewCommentRepository::class), $this->createStub(CardLinkRepository::class), $this->createStub(CardRepository::class), $states, $this->createStub(CardPauseRepository::class), new ShippedCardTypes(), $this->createStub(CardAgentReviews::class), $this->markerText(), $this->createStub(CardArtifactRepository::class), $this->createStub(SiteReviewScreenshotRepository::class), $this->values())->forCards([$card]);

        self::assertSame([
            'state' => 'open',
            'draft' => true,
            'checks' => 'failed',
            'failedChecks' => ['phpunit'],
            'mergeability' => 'conflicting',
            'review' => 'changes-requested',
            'readyToMerge' => false,
            'refreshedAt' => '2026-09-27T11:00:00+00:00',
        ], $rows[0]['pullRequests'][0]['state']);
        self::assertNull($rows[0]['pullRequests'][1]['state']);
        self::assertArrayNotHasKey('automation', $rows[0]);
    }

    public function test_a_pull_request_that_loupe_never_read_reads_a_null_state(): void
    {
        $card = $this->card();
        $link = new CardPullRequest($card, 'https://github.com/loupe-ac/loupe/pull/8', Forge::GitHub, 'loupe-ac/loupe', 8);
        $this->setId($link, Uuid::v7());
        $card->pullRequests->add($link);
        $states = $this->createStub(CardPullRequestStates::class);
        $states->method('forCards')->willReturn(new PullRequestStates([(string) $link->id => new PullRequestStateView(
            PullRequestState::Open,
            false,
            PullRequestChecks::Pending,
            [],
            PullRequestMergeability::Unknown,
            PullRequestReviewView::None,
            false,
            null,
        )]));

        $rows = new CardPayload($this->createStub(CardSiteReviewCommentRepository::class), $this->createStub(CardLinkRepository::class), $this->createStub(CardRepository::class), $states, $this->createStub(CardPauseRepository::class), new ShippedCardTypes(), $this->createStub(CardAgentReviews::class), $this->markerText(), $this->createStub(CardArtifactRepository::class), $this->createStub(SiteReviewScreenshotRepository::class), $this->values())->forCards([$card]);

        self::assertNull($rows[0]['pullRequests'][0]['state']);
    }

    public function test_the_full_shape_renders_the_active_pause_of_each_card(): void
    {
        $paused = $this->card();
        $this->setId($paused, Uuid::v7());
        $free = $this->card();
        $this->setId($free, Uuid::v7());
        $pause = new CardPause($paused, $paused->project, 'review-failed', 'fix-on-review', PauseKind::Retries, new \DateTimeImmutable('2026-10-02T10:00:00+00:00'));
        $this->setId($pause, Uuid::v7());
        $pauses = $this->createMock(CardPauseRepository::class);
        // One read for the whole page, never one per card.
        $pauses->expects($this->once())->method('findActiveForCardIds')->with([$paused->id, $free->id])->willReturn([(string) $paused->id => $pause]);

        $rows = new CardPayload($this->createStub(CardSiteReviewCommentRepository::class), $this->createStub(CardLinkRepository::class), $this->createStub(CardRepository::class), $this->states(), $pauses, new ShippedCardTypes(), $this->createStub(CardAgentReviews::class), $this->markerText(), $this->createStub(CardArtifactRepository::class), $this->createStub(SiteReviewScreenshotRepository::class), $this->values())->forCards([$paused, $free]);

        self::assertSame([
            'pauseId' => (string) $pause->id,
            'kind' => 'retries',
            'reason' => 'review-failed',
            'ruleId' => 'fix-on-review',
            'since' => '2026-10-02T10:00:00+00:00',
        ], $rows[0]['pause']);
        self::assertArrayHasKey('pause', $rows[1]);
        self::assertNull($rows[1]['pause']);
    }

    public function test_a_pull_request_carries_a_null_agent_review_when_the_port_finds_none(): void
    {
        $card = $this->cardWithPullRequest();

        $rows = new CardPayload($this->createStub(CardSiteReviewCommentRepository::class), $this->createStub(CardLinkRepository::class), $this->createStub(CardRepository::class), $this->states(), $this->createStub(CardPauseRepository::class), new ShippedCardTypes(), $this->createStub(CardAgentReviews::class), $this->markerText(), $this->createStub(CardArtifactRepository::class), $this->createStub(SiteReviewScreenshotRepository::class), $this->values())->forCards([$card]);

        self::assertArrayHasKey('agentReview', $rows[0]['pullRequests'][0]);
        self::assertNull($rows[0]['pullRequests'][0]['agentReview']);
    }

    public function test_a_pull_request_carries_the_agent_review_the_port_returns_for_its_link(): void
    {
        $card = $this->cardWithPullRequest();
        $link = $card->pullRequests->first();
        self::assertInstanceOf(CardPullRequest::class, $link);
        $review = [
            'reviewId' => 'review-1',
            'headSha' => str_repeat('a', 40),
            'conclusion' => 'failure',
            'summary' => 'One finding.',
            'findings' => [['path' => 'src/Foo.php', 'startLine' => 3, 'endLine' => 5, 'severity' => 'important', 'title' => 'Null read', 'body' => 'Can be null.']],
            'createdAt' => '2026-10-09T10:00:00+00:00',
            'postedAt' => null,
            'carriedTo' => [['headSha' => str_repeat('e', 40), 'postedAt' => '2026-10-09T11:00:00+00:00']],
            'overriddenBy' => null,
            'checkUrl' => null,
        ];
        $reviews = $this->createMock(CardAgentReviews::class);
        $reviews->expects($this->once())->method('forCards')->with([$card])->willReturn([(string) $link->id => $review]);

        $rows = new CardPayload($this->createStub(CardSiteReviewCommentRepository::class), $this->createStub(CardLinkRepository::class), $this->createStub(CardRepository::class), $this->states(), $this->createStub(CardPauseRepository::class), new ShippedCardTypes(), $reviews, $this->markerText(), $this->createStub(CardArtifactRepository::class), $this->createStub(SiteReviewScreenshotRepository::class), $this->values())->forCards([$card]);

        self::assertSame($review, $rows[0]['pullRequests'][0]['agentReview']);
    }

    public function test_the_state_comes_from_the_stored_markers_of_the_card(): void
    {
        $card = $this->card();
        $card->markers = [
            $this->entry('checks_failed', 'stuck', ['%number%' => 5], '2026-10-02T09:45:00+00:00'),
            $this->entry('run_open', 'working', [], '2026-10-02T09:30:00+00:00'),
            $this->entry('unmanaged', 'chip', [], '2026-10-02T09:00:00+00:00'),
        ];

        $payload = $this->payload();
        $row = $payload->forCards([$card])[0];
        $list = $payload->forCardList([$card])[0];

        self::assertSame([
            'kind' => 'stuck',
            'code' => 'checks_failed',
            'since' => '2026-10-02T09:45:00+00:00',
            'reason' => 'The checks of pull request #5 fail, and no fix is under way.',
            'others' => [
                ['kind' => 'working', 'code' => 'run_open', 'reason' => 'A worker runs on this card.', 'since' => '2026-10-02T09:30:00+00:00'],
            ],
        ], $row['state']);
        self::assertSame(['kind' => 'stuck', 'code' => 'checks_failed', 'since' => '2026-10-02T09:45:00+00:00'], $list['state']);
    }

    public function test_a_card_with_only_chips_has_no_state(): void
    {
        $card = $this->card();
        $card->markers = [$this->entry('unmanaged', 'chip', [], '2026-10-02T09:00:00+00:00')];

        $payload = $this->payload();

        self::assertNull($payload->forCards([$card])[0]['state']);
        self::assertNull($payload->forCardList([$card])[0]['state']);
    }

    public function test_a_card_with_no_markers_and_a_card_in_a_terminal_column_have_no_state(): void
    {
        $none = $this->card();
        $done = $this->card();
        $done->column = new BoardColumn(project: $done->project, label: 'board.card.status.done', slug: 'done', position: 1, terminal: true);
        $done->markers = [$this->entry('run_open', 'working', [], '2026-10-02T09:30:00+00:00')];

        $payload = $this->payload();

        self::assertNull($payload->forCards([$none, $done])[0]['state']);
        self::assertNull($payload->forCards([$none, $done])[1]['state']);
        self::assertNull($payload->forCardList([$done])[0]['state']);
    }

    private function payload(): CardPayload
    {
        return new CardPayload($this->createStub(CardSiteReviewCommentRepository::class), $this->createStub(CardLinkRepository::class), $this->createStub(CardRepository::class), $this->states(), $this->createStub(CardPauseRepository::class), new ShippedCardTypes(), $this->createStub(CardAgentReviews::class), $this->markerText(), $this->createStub(CardArtifactRepository::class), $this->createStub(SiteReviewScreenshotRepository::class), $this->values());
    }

    /**
     * @param array<string, string|int> $params
     *
     * @return array{key: string, kind: string, label: string, remedy: ?string, params: array<string, string|int>, since: string}
     */
    private function entry(string $key, string $kind, array $params, string $since): array
    {
        return ['key' => $key, 'kind' => $kind, 'label' => 'marker.'.$key, 'remedy' => 'marker.'.$key.'.remedy', 'params' => $params, 'since' => $since];
    }

    private function cardWithPullRequest(): Card
    {
        $card = $this->card();
        $this->setId($card, Uuid::v7());
        $link = new CardPullRequest($card, 'https://github.com/loupe-ac/loupe/pull/7', Forge::GitHub, 'loupe-ac/loupe', 7);
        $this->setId($link, Uuid::v7());
        $card->pullRequests->add($link);

        return $card;
    }

    private function values(): CardValueReader
    {
        return new CardValueReader($this->createStub(CardValueKeys::class), $this->createStub(CardValueRepository::class));
    }

    private function markerText(): CardMarkerText
    {
        return self::getContainer()->get(CardMarkerText::class);
    }

    private function states(): CardPullRequestStates
    {
        $states = $this->createStub(CardPullRequestStates::class);
        $states->method('forCards')->willReturn(new PullRequestStates());

        return $states;
    }

    private function setId(object $entity, Uuid $id): void
    {
        new \ReflectionProperty($entity, 'id')->setValue($entity, $id);
    }

    private function card(string $type = 'feature'): Card
    {
        $owner = new User(fullName: 'Riley', email: 'riley@example.com', password: 'hashed');

        $project = new Project($owner, 'board');
        $this->setId($project, Uuid::v7());

        $card = new Card(
            project: $project,
            column: new BoardColumn(project: $project, label: 'board.card.status.backlog', slug: 'backlog', position: 0, backlog: true),
            title: 'Drag ordering',
            body: 'Body',
            number: 7,
            type: $type,
            origin: Actor::Agent,
        );
        $this->setId($card, Uuid::v7());

        return $card;
    }
}
