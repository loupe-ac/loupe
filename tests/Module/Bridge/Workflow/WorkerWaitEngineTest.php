<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Workflow;

use App\Module\Board\Command\SaveBoardAutomationSettingsCommand;
use App\Module\Board\Command\SaveBoardAutomationSettingsHandler;
use App\Module\Board\Entity\Card;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkerWait;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\Repository\WorkerWaitRepository;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkerWaitEndReason;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Entity\PullRequestChecks;
use App\Module\Forge\Entity\PullRequestState;
use App\Module\Workflow\Contract\CardMarkerStore;
use App\Module\Workflow\Engine\Engine;
use App\Tests\Module\Workflow\Action\ActionScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/** The wait step of the engine ends the wait of a worker, with the automation on and off. */
final class WorkerWaitEngineTest extends KernelTestCase
{
    use ActionScenario;

    private const string NOW = '2026-10-08 12:00:00';

    public function test_a_wait_stays_open_while_no_condition_is_true_and_the_limit_is_ahead(): void
    {
        [$card, $pullRequest] = $this->cardWithPullRequest();
        $wait = $this->openWait($card, $pullRequest, 'pr.checks_passed', limit: '+30 minutes');

        $this->evaluate($card);

        self::assertTrue($this->wait($wait)->isOpen());
    }

    public function test_a_condition_that_turns_true_ends_the_wait_with_the_met_condition(): void
    {
        [$card, $pullRequest] = $this->cardWithPullRequest();
        $wait = $this->openWait($card, $pullRequest, 'pr.checks_passed', limit: '+30 minutes');
        $pullRequest->checks = PullRequestChecks::Passed;
        $this->em()->flush();

        $this->evaluate($card);

        $stored = $this->wait($wait);
        self::assertSame(WorkerWaitEndReason::Condition, $stored->endReason);
        self::assertSame(['pr.checks_passed'], array_column($stored->metConditions ?? [], 'key'));
        self::assertNotNull($stored->endedAt);
    }

    public function test_the_condition_reads_the_pull_request_that_it_is_bound_to(): void
    {
        [$card, $bound] = $this->cardWithPullRequest();
        $other = $this->pullRequest($card);
        $wait = $this->openWait($card, $bound, 'pr.checks_passed', limit: '+30 minutes');
        $other->checks = PullRequestChecks::Passed;
        $this->em()->flush();

        $this->evaluate($card);

        self::assertTrue($this->wait($wait)->isOpen());
    }

    public function test_the_time_limit_ends_the_wait(): void
    {
        [$card, $pullRequest] = $this->cardWithPullRequest();
        $wait = $this->openWait($card, $pullRequest, 'pr.checks_passed', limit: '-1 minute');

        $this->evaluate($card);

        $stored = $this->wait($wait);
        self::assertSame(WorkerWaitEndReason::TimedOut, $stored->endReason);
        self::assertNull($stored->metConditions);
    }

    public function test_a_pull_request_that_is_no_longer_open_ends_the_wait_as_subject_gone(): void
    {
        [$card, $pullRequest] = $this->cardWithPullRequest();
        $wait = $this->openWait($card, $pullRequest, 'pr.checks_passed', limit: '+30 minutes');
        $pullRequest->state = PullRequestState::Closed;
        $this->em()->flush();

        $this->evaluate($card);

        self::assertSame(WorkerWaitEndReason::SubjectGone, $this->wait($wait)->endReason);
    }

    public function test_a_pull_request_that_the_card_no_longer_links_ends_the_wait_as_subject_gone(): void
    {
        [$card, $pullRequest] = $this->cardWithPullRequest();
        $wait = $this->openWait($card, $pullRequest, 'pr.checks_passed', limit: '+30 minutes');
        $wait->conditions = [['key' => 'pr.checks_passed', 'params' => [], 'pullRequestId' => Uuid::v7()->toRfc4122(), 'token' => 'pr:acme/widgets#99']];
        $this->em()->flush();

        $this->evaluate($card);

        self::assertSame(WorkerWaitEndReason::SubjectGone, $this->wait($wait)->endReason);
    }

    public function test_the_wait_ends_with_the_automation_off_too(): void
    {
        [$card, $pullRequest] = $this->cardWithPullRequest();
        $wait = $this->openWait($card, $pullRequest, 'pr.checks_passed', limit: '-1 minute');
        $this->service(SaveBoardAutomationSettingsHandler::class)(new SaveBoardAutomationSettingsCommand($card->project, false));

        $this->evaluate($card);

        self::assertSame(WorkerWaitEndReason::TimedOut, $this->wait($wait)->endReason);
    }

    public function test_an_open_wait_shows_the_waiting_marker_in_place_of_working(): void
    {
        [$card, $pullRequest] = $this->cardWithPullRequest();
        $this->openWait($card, $pullRequest, 'pr.checks_passed', limit: '+30 minutes');

        $this->evaluate($card);

        $keys = array_map(static fn ($marker): string => $marker->key, $this->service(CardMarkerStore::class)->read($card->id ?? throw new \LogicException('Stored.')));
        self::assertContains('worker-waiting', $keys);
        self::assertNotContains('run-open', $keys);
    }

    public function test_the_limit_of_an_open_wait_is_the_due_time_of_the_card(): void
    {
        [$card, $pullRequest] = $this->cardWithPullRequest();
        $this->openWait($card, $pullRequest, 'pr.checks_passed', limit: '+30 minutes');

        $this->evaluate($card);

        $this->em()->clear();
        $stored = $this->em()->find(Card::class, $card->id);
        self::assertEquals(new \DateTimeImmutable(self::NOW.' +30 minutes'), $stored?->markersDueAt);
    }

    public function test_a_parked_run_counts_as_active_until_a_run_resumes_its_ended_wait(): void
    {
        [$card, $pullRequest] = $this->cardWithPullRequest();
        $wait = $this->openWait($card, $pullRequest, 'pr.checks_passed', limit: '+30 minutes');
        $runs = $this->service(WorkerRunRepository::class);
        $cardId = $card->id ?? throw new \LogicException('Stored.');

        self::assertSame(['implementation'], array_map(strval(...), array_keys($runs->findOpenWorkSinceOfCard($cardId)['all'])));

        $wait->end(WorkerWaitEndReason::Condition, new \DateTimeImmutable(self::NOW));
        $this->em()->flush();
        self::assertNotSame([], $runs->findOpenWorkSinceOfCard($cardId)['all']);

        $wait->resumeRunId = Uuid::v7();
        $this->em()->flush();
        self::assertSame([], $runs->findOpenWorkSinceOfCard($cardId)['all']);
    }

    public function test_a_parked_run_of_a_cancelled_wait_is_not_active(): void
    {
        [$card, $pullRequest] = $this->cardWithPullRequest();
        $wait = $this->openWait($card, $pullRequest, 'pr.checks_passed', limit: '+30 minutes');
        $wait->end(WorkerWaitEndReason::CancelledByPerson, new \DateTimeImmutable(self::NOW));
        $this->em()->flush();

        self::assertSame([], $this->service(WorkerRunRepository::class)->findOpenWorkSinceOfCard($card->id ?? throw new \LogicException('Stored.'))['all']);
    }

    public function test_a_wait_that_ended_while_its_bridge_is_offline_shows_the_stuck_marker_until_a_run_resumes_it(): void
    {
        [$card, $pullRequest] = $this->cardWithPullRequest();
        $wait = $this->openWait($card, $pullRequest, 'pr.checks_passed', limit: '+30 minutes');
        // No bridge is stored for the run, so no heartbeat reaches the card.
        $wait->end(WorkerWaitEndReason::Condition, new \DateTimeImmutable(self::NOW.' -1 minute'));
        $this->em()->flush();

        $this->evaluate($card);
        self::assertContains('wait-bridge-offline', $this->markerKeys($card));

        $wait->resumeRunId = Uuid::v7();
        $this->em()->flush();
        $this->evaluate($card);
        self::assertNotContains('wait-bridge-offline', $this->markerKeys($card));
    }

    /** @return list<string> */
    private function markerKeys(Card $card): array
    {
        return array_values(array_map(static fn ($marker): string => $marker->key, $this->service(CardMarkerStore::class)->read($card->id ?? throw new \LogicException('Stored.'))));
    }

    /** @return array{Card, ForgePullRequest} */
    private function cardWithPullRequest(): array
    {
        self::bootKernel();
        $project = $this->workflowProject('wait-engine-'.uniqid());
        $this->bindLifecycle($project);
        $card = $this->card($project, 'in-progress');

        return [$card, $this->pullRequest($card)];
    }

    private function openWait(Card $card, ForgePullRequest $pullRequest, string $key, string $limit): WorkerWait
    {
        $run = new WorkerRun(
            project: $card->project,
            bridgeId: Uuid::v7(),
            subjectType: WorkSubject::CARD,
            subjectId: $card->id ?? throw new \LogicException('Stored.'),
            cardNumber: $card->number,
            workKind: 'implementation',
            state: WorkerRunState::Waiting,
            runKey: Uuid::v7(),
            sessionId: Uuid::v7(),
            startedAt: new \DateTimeImmutable(self::NOW.' -10 minutes'),
            endedAt: new \DateTimeImmutable(self::NOW.' -5 minutes'),
            exitCode: 0,
            hasResult: true,
            output: 'parked',
            receivedAt: new \DateTimeImmutable(self::NOW.' -10 minutes'),
        );
        $this->em()->persist($run);
        $this->em()->flush();
        $opened = new \DateTimeImmutable(self::NOW.' -5 minutes');
        $wait = new WorkerWait(
            $card->project,
            $card->id,
            $run->id ?? throw new \LogicException('Stored.'),
            $run->id,
            $run->bridgeId ?? throw new \LogicException('Stored.'),
            $run->sessionId ?? throw new \LogicException('Stored.'),
            [['key' => $key, 'params' => [], 'pullRequestId' => $pullRequest->id?->toRfc4122(), 'token' => 'pr:acme/widgets#'.$pullRequest->number]],
            $opened,
            new \DateTimeImmutable(self::NOW.' '.$limit),
        );
        $this->em()->persist($wait);
        $this->em()->flush();

        return $wait;
    }

    private function wait(WorkerWait $wait): WorkerWait
    {
        $this->em()->clear();

        return $this->service(WorkerWaitRepository::class)->find($wait->id) ?? throw new \LogicException('The wait exists.');
    }

    private function evaluate(Card $card): void
    {
        $this->service(Engine::class)->evaluate($card->id ?? throw new \LogicException('Stored.'), new \DateTimeImmutable(self::NOW));
    }
}
