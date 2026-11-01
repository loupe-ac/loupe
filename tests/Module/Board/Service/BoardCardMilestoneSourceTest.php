<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Service;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardEvent;
use App\Module\Board\Entity\CardEventKind;
use App\Module\Board\Entity\CardPullRequest;
use App\Module\Board\Entity\Forge;
use App\Module\Board\Repository\CardEventRepository;
use App\Module\Board\Repository\CardPullRequestRepository;
use App\Module\Board\Service\BoardCardMilestoneSource;
use App\Module\Bridge\Metric\CardMilestone;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Repository\ForgePullRequestRepository;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\Actor;
use App\Module\Workflow\Contract\DurationEvent;
use App\Module\Workflow\Contract\TemplateMetricSet;
use App\Tests\Module\Board\Mcp\BoardToolScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class BoardCardMilestoneSourceTest extends KernelTestCase
{
    use BoardToolScenario;

    private EntityManagerInterface $em;
    private BoardCardMilestoneSource $source;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $source = self::getContainer()->get(BoardCardMilestoneSource::class);
        self::assertInstanceOf(BoardCardMilestoneSource::class, $source);
        $this->source = $source;
    }

    public function test_it_answers_the_pull_request_times_the_ready_row_and_the_moves_of_each_card(): void
    {
        $project = $this->makeProject('milestones');
        $other = $this->makeProject('milestones-other');
        $review = $this->column($project, 'in-progress');
        $done = $this->column($project, 'done');
        $templates = new TemplateMetricSet(slotColumns: ['review' => $review->id, '@terminal' => $done->id, 'no-column' => null]);
        $card = $this->card($project, 1, 'done');
        $quiet = $this->card($project, 2, 'in-progress');
        $foreign = $this->card($other, 1, 'done');

        $at = static fn (string $time): \DateTimeImmutable => new \DateTimeImmutable('2026-09-01 '.$time);
        $this->event($card, CardEventKind::Moved, ['from' => ['id' => 'x'], 'to' => CardEvent::columnDetail($review), 'cause' => null], $at('09:00:00'));
        $this->event($card, CardEventKind::ReadyToMerge, ['pullRequest' => 7], $at('11:00:00'));
        $this->event($card, CardEventKind::Moved, ['from' => CardEvent::columnDetail($review), 'to' => CardEvent::columnDetail($done), 'cause' => null], $at('12:00:00'));
        $this->event($card, CardEventKind::FixRequested, ['reason' => 'conflict'], $at('10:00:00'));
        $this->event($foreign, CardEventKind::ReadyToMerge, ['pullRequest' => 7], $at('11:00:00'));
        $this->pullRequest($project, $card, 7, $at('10:00:00'), null);
        $this->pullRequest($project, $card, 8, $at('08:00:00'), $at('12:30:00'));
        $this->pullRequest($other, $foreign, 7, $at('07:00:00'), $at('07:30:00'));
        $this->em->clear();

        $milestones = $this->source->milestonesOf($project, [$card, $quiet, $foreign, Uuid::v7()], $templates);

        self::assertSame([(string) $card], array_keys($milestones));
        $found = $this->found($milestones[(string) $card]);
        $expected = [
            'arrived|review|09:00',
            'arrived|@terminal|12:00',
            'left|review|12:00',
            DurationEvent::PR_MERGED.'||12:30',
            DurationEvent::PR_OPENED.'||08:00',
            DurationEvent::PR_OPENED.'||10:00',
            DurationEvent::READY_TO_MERGE.'||11:00',
            DurationEvent::READY_TO_MERGE.'||12:30',
        ];
        sort($expected);
        self::assertSame($expected, $found);
        self::assertSame([], $this->source->milestonesOf($project, [], $templates));
    }

    public function test_an_approval_check_pass_is_no_ready_time_because_the_branch_can_be_behind(): void
    {
        $project = $this->makeProject('milestones-approval');
        $card = $this->card($project, 1, 'in-progress');
        $read = $this->card($project, 2, 'in-progress');
        $at = static fn (string $time): \DateTimeImmutable => new \DateTimeImmutable('2026-09-01 '.$time);
        $this->event($card, CardEventKind::AutoApproved, ['pullRequest' => 7, 'headSha' => 'abc123', 'rule' => 'approval-check-auto'], $at('10:30:00'));
        $this->event($read, CardEventKind::AutoApproved, ['pullRequest' => 8, 'headSha' => 'abc123', 'rule' => 'approval-check-auto'], $at('10:30:00'));
        $this->event($read, CardEventKind::ReadyToMerge, ['pullRequest' => 8], $at('12:00:00'));
        $this->em->clear();

        $milestones = $this->source->milestonesOf($project, [$card, $read], new TemplateMetricSet(slotColumns: []));

        self::assertSame([(string) $read], array_keys($milestones));
        self::assertSame([DurationEvent::READY_TO_MERGE.'||12:00'], $this->found($milestones[(string) $read]));
    }

    public function test_a_pull_request_that_merged_with_no_ready_read_was_ready_at_its_merge(): void
    {
        $project = $this->makeProject('milestones-merged-unread');
        $card = $this->card($project, 1, 'in-progress');
        $at = static fn (string $time): \DateTimeImmutable => new \DateTimeImmutable('2026-09-01 '.$time);
        $this->event($card, CardEventKind::AutoApproved, ['pullRequest' => 7, 'headSha' => 'abc123', 'rule' => 'approval-check-auto'], $at('10:30:00'));
        $this->pullRequest($project, $card, 7, $at('08:00:00'), $at('10:45:00'));
        $this->em->clear();

        $milestones = $this->source->milestonesOf($project, [$card], new TemplateMetricSet(slotColumns: []));

        self::assertSame([
            DurationEvent::PR_MERGED.'||10:45',
            DurationEvent::PR_OPENED.'||08:00',
            DurationEvent::READY_TO_MERGE.'||10:45',
        ], $this->found($milestones[(string) $card] ?? []));
    }

    public function test_a_ready_read_of_one_repository_leaves_the_merge_fallback_of_the_same_number_in_another(): void
    {
        $project = $this->makeProject('milestones-two-repositories');
        $card = $this->card($project, 1, 'in-progress');
        $at = static fn (string $time): \DateTimeImmutable => new \DateTimeImmutable('2026-09-01 '.$time);
        $widgets = $this->pullRequest($project, $card, 7, $at('08:00:00'), $at('12:00:00'));
        $this->pullRequest($project, $card, 7, $at('08:30:00'), $at('15:00:00'), 'Acme/Gears');
        $this->event($card, CardEventKind::ReadyToMerge, ['pullRequest' => 7, 'pullRequestId' => $widgets->id?->toRfc4122()], $at('10:00:00'));
        $this->em->clear();

        $milestones = $this->source->milestonesOf($project, [$card], new TemplateMetricSet(slotColumns: []));

        self::assertSame([
            DurationEvent::PR_MERGED.'||12:00',
            DurationEvent::PR_MERGED.'||15:00',
            DurationEvent::PR_OPENED.'||08:00',
            DurationEvent::PR_OPENED.'||08:30',
            DurationEvent::READY_TO_MERGE.'||10:00',
            DurationEvent::READY_TO_MERGE.'||15:00',
        ], $this->found($milestones[(string) $card] ?? []));
    }

    public function test_a_ready_read_still_matches_its_pull_request_after_the_repository_moves(): void
    {
        $project = $this->makeProject('milestones-moved');
        $card = $this->card($project, 1, 'in-progress');
        $at = static fn (string $time): \DateTimeImmutable => new \DateTimeImmutable('2026-09-01 '.$time);
        $row = $this->pullRequest($project, $card, 7, $at('08:00:00'), $at('15:00:00'));
        $this->event($card, CardEventKind::ReadyToMerge, ['pullRequest' => 7, 'pullRequestId' => $row->id?->toRfc4122()], $at('10:00:00'));
        $projectId = $project->id ?? throw new \LogicException('A flushed project has an id.');
        $forgePullRequests = self::getContainer()->get(ForgePullRequestRepository::class);
        self::assertInstanceOf(ForgePullRequestRepository::class, $forgePullRequests);
        $forgePullRequests->repoint($projectId, Forge::GitHub->value, 'Acme/Widgets', 'Acme/Moved');
        $cardPullRequests = self::getContainer()->get(CardPullRequestRepository::class);
        self::assertInstanceOf(CardPullRequestRepository::class, $cardPullRequests);
        $cardPullRequests->repoint($projectId, Forge::GitHub, 'Acme/Widgets', 'Acme/Moved');
        $this->em->clear();

        $milestones = $this->source->milestonesOf($project, [$card], new TemplateMetricSet(slotColumns: []));

        self::assertSame([
            DurationEvent::PR_MERGED.'||15:00',
            DurationEvent::PR_OPENED.'||08:00',
            DurationEvent::READY_TO_MERGE.'||10:00',
        ], $this->found($milestones[(string) $card] ?? []));
    }

    public function test_an_older_ready_read_with_only_a_number_keeps_its_time_when_the_pull_request_merges_later(): void
    {
        $project = $this->makeProject('milestones-merged-read');
        $card = $this->card($project, 1, 'in-progress');
        $at = static fn (string $time): \DateTimeImmutable => new \DateTimeImmutable('2026-09-01 '.$time);
        $this->event($card, CardEventKind::ReadyToMerge, ['pullRequest' => 7], $at('10:00:00'));
        $this->pullRequest($project, $card, 7, $at('08:00:00'), $at('15:00:00'));
        $this->em->clear();

        $milestones = $this->source->milestonesOf($project, [$card], new TemplateMetricSet(slotColumns: []));

        self::assertSame([
            DurationEvent::PR_MERGED.'||15:00',
            DurationEvent::PR_OPENED.'||08:00',
            DurationEvent::READY_TO_MERGE.'||10:00',
        ], $this->found($milestones[(string) $card] ?? []));
    }

    public function test_it_counts_a_creation_as_an_arrival_in_each_slot_of_its_column_and_never_as_a_departure(): void
    {
        $project = $this->makeProject('milestones-created');
        $review = $this->column($project, 'in-progress');
        $templates = new TemplateMetricSet(slotColumns: ['review' => $review->id, 'also-review' => $review->id, 'other' => $this->column($project, 'done')->id]);
        $card = $this->card($project, 1, 'in-progress');
        $this->event($card, CardEventKind::Created, ['column' => CardEvent::columnDetail($review), 'type' => 'feature'], new \DateTimeImmutable('2026-09-01 08:30:00'));
        $this->em->clear();

        $milestones = $this->source->milestonesOf($project, [$card], $templates);

        self::assertSame(['arrived|also-review|08:30', 'arrived|review|08:30'], $this->found($milestones[(string) $card] ?? []));
    }

    public function test_a_creation_with_no_known_column_gives_no_arrival(): void
    {
        $project = $this->makeProject('milestones-no-column');
        $review = $this->column($project, 'in-progress');
        $templates = new TemplateMetricSet(slotColumns: ['review' => $review->id]);
        $at = new \DateTimeImmutable('2026-09-01 08:30:00');
        $placed = $this->card($project, 1, 'in-progress');
        $this->event($placed, CardEventKind::Created, ['column' => CardEvent::columnDetail($review)], $at);
        $cards = [$placed];
        foreach ([[], ['column' => []], ['column' => ['slug' => 'in-progress']], ['column' => 'in-progress'], ['column' => ['id' => 'a-column-with-no-slot']]] as $index => $detail) {
            $cards[] = $card = $this->card($project, 2 + $index, 'in-progress');
            $this->event($card, CardEventKind::Created, $detail, $at);
        }
        $this->em->clear();

        $milestones = $this->source->milestonesOf($project, $cards, $templates);

        self::assertSame([(string) $placed], array_keys($milestones));
    }

    /**
     * @param list<CardMilestone> $milestones
     *
     * @return list<string>
     */
    private function found(array $milestones): array
    {
        $found = array_map(static fn (CardMilestone $milestone): string => $milestone->event.'|'.($milestone->slot ?? '').'|'.$milestone->at->format('H:i'), $milestones);
        sort($found);

        return $found;
    }

    /** @param array<string, mixed> $detail */
    private function event(Uuid $cardId, CardEventKind $kind, array $detail, \DateTimeImmutable $at): void
    {
        $card = $this->em->find(Card::class, $cardId) ?? throw new \LogicException('The card exists.');
        $events = self::getContainer()->get(CardEventRepository::class);
        self::assertInstanceOf(CardEventRepository::class, $events);
        $events->record($card, $kind, Actor::System, null, $detail, $at);
        $this->em->flush();
    }

    private function pullRequest(Project $project, Uuid $cardId, int $number, \DateTimeImmutable $openedAt, ?\DateTimeImmutable $mergedAt, string $repository = 'Acme/Widgets'): ForgePullRequest
    {
        $card = $this->em->find(Card::class, $cardId) ?? throw new \LogicException('The card exists.');
        $this->em->persist(new CardPullRequest($card, 'https://github.com/'.$repository.'/pull/'.$number, Forge::GitHub, $repository, $number));
        $row = new ForgePullRequest($project, Forge::GitHub->value, $repository, $number);
        $row->openedAt = $openedAt;
        $row->mergedAt = $mergedAt;
        $this->em->persist($row);
        $this->em->flush();

        return $row;
    }

    private function card(Project $project, int $number, string $column): Uuid
    {
        $card = new Card(project: $project, column: $this->column($project, $column), title: 'Card '.$number, body: '', number: $number, type: 'feature');
        $this->em->persist($card);
        $this->em->flush();

        return $card->id ?? throw new \LogicException('The card has no id after a flush.');
    }
}
