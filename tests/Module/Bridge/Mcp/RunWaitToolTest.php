<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Mcp;

use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkerWait;
use App\Module\Bridge\Mcp\RunWaitTool;
use App\Module\Bridge\Repository\WorkerWaitRepository;
use App\Module\Bridge\ValueObject\WorkerRunKind;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkerWaitEndReason;
use App\Module\OAuth\Scope\ApiScope;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\WaitConditionCheck;
use App\Module\Workflow\Contract\WaitConditionRefusal;
use App\Module\Workflow\Contract\WaitConditions;
use App\Module\Workflow\Contract\WaitSettings;
use App\Security\AuthenticatedCredential;
use App\Tests\Module\Bridge\BridgeScenario;
use App\Tests\Support\McpTokenScenario;
use App\Tests\Support\RecordingAuditor;
use Mcp\Exception\ToolCallException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;
use Symfony\Component\Uid\Uuid;

final class RunWaitToolTest extends KernelTestCase
{
    use BridgeScenario;
    use McpTokenScenario;

    private const string NOW = '2026-09-23 10:00:00';

    private Project $project;
    private Bridge $bridge;
    private Uuid $cardId;
    private bool $autoCredential = false;
    private RecordingAuditor $audit;

    /** @var \ArrayObject<string, WaitConditionCheck> */
    private \ArrayObject $checks;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->checks = new \ArrayObject([
            'pr.checks_passed' => new WaitConditionCheck(pullRequestId: Uuid::fromString('0199a1b2-0000-7000-8000-000000000001'), pullRequestToken: 'pr:acme/app#12'),
            'pr.checks_failed' => new WaitConditionCheck(pullRequestId: Uuid::fromString('0199a1b2-0000-7000-8000-000000000001'), pullRequestToken: 'pr:acme/app#12'),
            'card.in_review' => new WaitConditionCheck(),
        ]);
        $this->audit = RecordingAuditor::installedIn(self::getContainer());
        self::getContainer()->set(WaitConditions::class, new readonly class($this->checks) implements WaitConditions {
            /** @param \ArrayObject<string, WaitConditionCheck> $checks */
            public function __construct(
                private \ArrayObject $checks,
            ) {
            }

            #[\Override]
            public function settingsOf(Uuid $projectId): WaitSettings
            {
                return new WaitSettings(120, 3);
            }

            #[\Override]
            public function check(Uuid $cardId, string $key, array $params, ?string $pullRequestUrl): WaitConditionCheck
            {
                return $this->checks[$key] ?? new WaitConditionCheck(WaitConditionRefusal::UnknownCondition, \sprintf('"%s" is not a condition or a marker of the workflow.', $key));
            }
        });
        self::getContainer()->set('clock', new MockClock(new \DateTimeImmutable(self::NOW)));

        $em = $this->em();
        $owner = $this->user($em, 'run-wait-'.uniqid().'@example.com');
        $this->project = $this->project($em, $owner, 'Waits');
        $this->bridge = $this->seedBridge($em, $owner, projects: [(string) $this->project->id]);
        $this->cardId = $this->seedCard($em, $this->project)->id ?? throw new \LogicException('Seeded.');
        $this->actAsMcpTokenBoundTo($this->project);
    }

    public function test_an_accepted_wait_is_stored_and_answered(): void
    {
        $run = $this->workerRun();

        $result = $this->wait((string) $run->sessionId, [['key' => 'pr.checks_passed'], ['key' => 'pr.checks_failed', 'pullRequestUrl' => 'https://github.com/acme/app/pull/12']], 45);

        $wait = $this->onlyWait();
        self::assertSame((string) $wait->id, $result['waitId']);
        self::assertSame($wait->limitAt->format(\DATE_ATOM), $result['limitAt']);
        self::assertSame([['key' => 'pr.checks_passed', 'pullRequest' => 'pr:acme/app#12'], ['key' => 'pr.checks_failed', 'pullRequest' => 'pr:acme/app#12']], $result['conditions']);
        self::assertStringStartsWith('Wait accepted. End your turn now.', $result['message']);

        self::assertSame((string) $this->cardId, (string) $wait->cardId);
        self::assertSame((string) $run->id, (string) $wait->runId);
        self::assertSame((string) $run->id, (string) $wait->chainRootRunId);
        self::assertSame((string) $this->bridge->id, (string) $wait->bridgeId);
        self::assertSame((string) $run->sessionId, (string) $wait->sessionId);
        self::assertTrue($wait->isOpen());
        self::assertSame(self::NOW, $wait->openedAt->format('Y-m-d H:i:s'));
        self::assertSame('2026-09-23 10:45:00', $wait->limitAt->format('Y-m-d H:i:s'));
        // jsonb keeps no key order, so the entries compare as sets of keys.
        self::assertEquals(
            [
                ['key' => 'pr.checks_passed', 'params' => [], 'pullRequestId' => '0199a1b2-0000-7000-8000-000000000001', 'token' => 'pr:acme/app#12'],
                ['key' => 'pr.checks_failed', 'params' => [], 'pullRequestId' => '0199a1b2-0000-7000-8000-000000000001', 'token' => 'pr:acme/app#12'],
            ],
            $wait->conditions,
        );
        $record = $this->audit->record('bridge.worker_wait_opened');
        self::assertSame('pr.checks_passed,pr.checks_failed', $record->context['conditionKeys'] ?? null);
    }

    public function test_a_condition_that_reads_no_pull_request_stores_none(): void
    {
        $run = $this->workerRun();

        $this->wait((string) $run->sessionId, [['key' => 'card.in_review']], 5);

        self::assertEquals([['key' => 'card.in_review', 'params' => [], 'pullRequestId' => null, 'token' => null]], $this->onlyWait()->conditions);
    }

    public function test_the_chain_root_is_the_first_run_of_the_chain(): void
    {
        $first = $this->workerRun();
        $second = $this->workerRun(continues: $first);
        $third = $this->workerRun(continues: $second);

        $this->wait((string) $third->sessionId, [['key' => 'card.in_review']], 5);

        $wait = $this->onlyWait();
        self::assertSame((string) $first->id, (string) $wait->chainRootRunId);
        self::assertSame((string) $third->id, (string) $wait->runId);
    }

    public function test_the_newest_run_of_the_session_is_the_one_that_waits(): void
    {
        $first = $this->workerRun();
        $resumed = $this->workerRun(continues: $first, sessionId: $first->sessionId);

        $this->wait((string) $first->sessionId, [['key' => 'card.in_review']], 5);

        self::assertSame((string) $resumed->id, (string) $this->onlyWait()->runId);
    }

    public function test_a_session_with_no_worker_run_is_refused(): void
    {
        $this->refused((string) Uuid::v4(), [['key' => 'card.in_review']], 5, 'Loupe has no bridge worker run for this session. Only a worker that a bridge runs can wait.');
    }

    public function test_an_interactive_session_is_refused(): void
    {
        $run = $this->seedRun($this->em(), $this->project, bridgeId: $this->bridge->id, cardId: $this->cardId, state: WorkerRunState::Running, kind: WorkerRunKind::Interactive);

        $this->refused((string) $run->sessionId, [['key' => 'card.in_review']], 5, 'Loupe has no bridge worker run for this session. Only a worker that a bridge runs can wait.');
    }

    public function test_a_run_that_ended_is_refused(): void
    {
        $run = $this->workerRun(WorkerRunState::Succeeded);

        $this->refused((string) $run->sessionId, [['key' => 'card.in_review']], 5, 'The run of this session has ended, so it cannot start a wait.');
    }

    public function test_a_session_of_another_project_is_refused(): void
    {
        $other = $this->project($this->em(), $this->project->owner, 'Other');
        $run = $this->seedRun($this->em(), $other, bridgeId: $this->bridge->id, cardId: $this->cardId, state: WorkerRunState::Running, runKey: Uuid::v7());

        $this->refused((string) $run->sessionId, [['key' => 'card.in_review']], 5, 'Loupe has no bridge worker run for this session. Only a worker that a bridge runs can wait.');
    }

    public function test_a_run_with_no_card_is_refused(): void
    {
        $run = $this->seedRun($this->em(), $this->project, bridgeId: $this->bridge->id, state: WorkerRunState::Running, runKey: Uuid::v7(), subjectType: 'project');

        $this->refused((string) $run->sessionId, [['key' => 'card.in_review']], 5, 'The run of this session is not about a card, so it cannot wait for the conditions of one.');
    }

    public function test_a_card_that_no_longer_exists_is_refused(): void
    {
        $run = $this->seedRun($this->em(), $this->project, bridgeId: $this->bridge->id, cardId: Uuid::v7(), state: WorkerRunState::Running, runKey: Uuid::v7());

        $this->refused((string) $run->sessionId, [['key' => 'card.in_review']], 5, 'The card of this run no longer exists.');
    }

    public function test_a_limit_over_the_longest_wait_is_refused_with_the_maximum(): void
    {
        $run = $this->workerRun();

        $this->refused((string) $run->sessionId, [['key' => 'card.in_review']], 121, 'limitMinutes is over the longest wait of this workflow, which is 120 minutes.');
        self::assertSame(0, $this->waitCount());
    }

    public function test_a_limit_below_one_minute_is_refused(): void
    {
        $run = $this->workerRun();

        $this->refused((string) $run->sessionId, [['key' => 'card.in_review']], 0, 'limitMinutes must be at least 1.');
    }

    public function test_the_cap_of_a_chain_refuses_the_next_wait(): void
    {
        $first = $this->workerRun();
        $run = $first;
        for ($i = 0; $i < 3; ++$i) {
            $this->wait((string) $run->sessionId, [['key' => 'card.in_review']], 5);
            $wait = $this->onlyOpenWait();
            $wait->end(WorkerWaitEndReason::Condition, new \DateTimeImmutable(self::NOW));
            $this->em()->flush();
            $run = $this->workerRun(continues: $run);
        }

        $this->refused((string) $run->sessionId, [['key' => 'card.in_review']], 5, 'The chain cap was reached: this run and the runs it resumes already held 3 waits. Stop and report what you have.');
        self::assertSame(3, $this->waitCount());
    }

    public function test_a_card_with_an_open_wait_is_refused(): void
    {
        $first = $this->workerRun();
        $this->wait((string) $first->sessionId, [['key' => 'card.in_review']], 5);
        $second = $this->workerRun();

        $this->refused((string) $second->sessionId, [['key' => 'card.in_review']], 5, 'This card already has an open wait. A card holds one wait at a time.');
        self::assertSame(1, $this->waitCount());
    }

    public function test_a_race_for_the_open_wait_is_a_refusal_and_not_an_error(): void
    {
        $run = $this->workerRun();
        $rival = new WorkerWait($this->project, $this->cardId, Uuid::v7(), Uuid::v7(), Uuid::v7(), Uuid::v4(), [], new \DateTimeImmutable(self::NOW), new \DateTimeImmutable('2026-09-23 11:00:00'));
        $this->em()->persist($rival);
        $this->em()->flush();
        // The handler reads no open wait, as it does when the rival commits after that read.
        $this->em()->clear();
        $stub = $this->createStub(WorkerWaitRepository::class);
        $stub->method('findOpenOfCard')->willReturn(null);
        $stub->method('countOfChain')->willReturn(0);
        self::getContainer()->set(WorkerWaitRepository::class, $stub);
        $this->actAsMcpTokenBoundTo($this->em()->find(Project::class, $this->project->id) ?? throw new \LogicException('Seeded.'));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('This card already has an open wait. A card holds one wait at a time.');
        $this->wait((string) $run->sessionId, [['key' => 'card.in_review']], 5);
    }

    public function test_a_condition_that_the_workflow_refuses_names_its_key_and_reason(): void
    {
        $run = $this->workerRun();
        $this->checks['pr.checks_passed'] = new WaitConditionCheck(WaitConditionRefusal::PullRequestRequired, 'The condition "pr.checks_passed" reads a pull request, and the card links no open pull request.');

        $this->refused(
            (string) $run->sessionId,
            [['key' => 'card.in_review'], ['key' => 'pr.checks_passed'], ['key' => 'pr.unheard_of']],
            5,
            "\"pr.checks_passed\": The condition \"pr.checks_passed\" reads a pull request, and the card links no open pull request.\n\"pr.unheard_of\": \"pr.unheard_of\" is not a condition or a marker of the workflow.",
        );
        self::assertSame(0, $this->waitCount());
    }

    public function test_a_condition_that_is_true_already_is_refused_and_listed(): void
    {
        $run = $this->workerRun();
        $this->checks['pr.checks_failed'] = new WaitConditionCheck(isTrue: true, pullRequestId: Uuid::v7(), pullRequestToken: 'pr:acme/app#12');
        $this->checks['card.in_review'] = new WaitConditionCheck(isTrue: true);

        $this->refused((string) $run->sessionId, [['key' => 'pr.checks_passed'], ['key' => 'pr.checks_failed'], ['key' => 'card.in_review']], 5, 'These conditions are true already: pr.checks_failed (pr:acme/app#12), card.in_review. Read the result now, and do not wait for it.');
        self::assertSame(0, $this->waitCount());
    }

    public function test_the_same_condition_twice_for_one_pull_request_is_refused(): void
    {
        $run = $this->workerRun();

        $this->refused((string) $run->sessionId, [['key' => 'pr.checks_passed'], ['key' => 'pr.checks_passed']], 5, '"pr.checks_passed": the conditions name it twice for pr:acme/app#12.');
    }

    public function test_the_same_key_for_two_pull_requests_is_two_conditions(): void
    {
        $run = $this->workerRun();
        $this->checks['pr.checks_passed'] = new WaitConditionCheck(pullRequestId: Uuid::fromString('0199a1b2-0000-7000-8000-000000000001'), pullRequestToken: 'pr:acme/app#12');

        $result = $this->wait((string) $run->sessionId, [['key' => 'pr.checks_passed'], ['key' => 'card.in_review']], 5);

        self::assertCount(2, $result['conditions']);
    }

    public function test_a_session_id_that_is_not_a_uuid_is_refused(): void
    {
        $this->refused('session-7', [['key' => 'card.in_review']], 5, '"session-7" is not a valid session ID. Pass your own session id.');
    }

    public function test_a_condition_with_no_key_is_refused(): void
    {
        $run = $this->workerRun();

        $this->refused((string) $run->sessionId, [['params' => []]], 5, 'conditions[0] must be an object with a key.');
        $this->refused((string) $run->sessionId, [], 5, 'Pass from 1 to 10 conditions.');
        $this->refused((string) $run->sessionId, array_fill(0, 11, ['key' => 'card.in_review']), 5, 'Pass from 1 to 10 conditions.');
    }

    public function test_a_run_credential_waits_only_for_its_own_session(): void
    {
        $run = $this->workerRun();
        $other = $this->workerRun();
        $this->actAsWorker($run->sessionId ?? throw new \LogicException('Seeded.'));
        $this->autoCredential = false;

        $this->refused((string) $other->sessionId, [['key' => 'card.in_review']], 5, 'sessionId must be the session of your own run.');
        $this->wait((string) $run->sessionId, [['key' => 'card.in_review']], 5);

        self::assertSame((string) $run->id, (string) $this->onlyWait()->runId);
    }

    public function test_a_run_that_a_report_closed_before_the_insert_is_refused(): void
    {
        $run = $this->workerRun();
        // The entity still reads Running, as it does when a report commits after the handler read the run.
        $this->em()->getConnection()->executeStatement('UPDATE bridge_worker_runs SET state = ? WHERE id = ?', [WorkerRunState::Succeeded->value, (string) $run->id]);

        $this->refused((string) $run->sessionId, [['key' => 'card.in_review']], 5, 'The run of this session has ended, so it cannot start a wait.');
        self::assertSame(0, $this->waitCount());
    }

    public function test_a_call_with_no_run_credential_is_refused(): void
    {
        $run = $this->workerRun();

        try {
            $this->tool()((string) $run->sessionId, [['key' => 'card.in_review']], 5);
            self::fail('Expected a refusal.');
        } catch (ToolCallException $e) {
            self::assertStringStartsWith('Only the bridge worker of a session can open its wait.', $e->getMessage());
        }
        self::assertSame(0, $this->waitCount());
    }

    public function test_a_token_of_a_run_credential_with_the_worker_scopes_reaches_the_tool(): void
    {
        $run = $this->workerRun();
        $this->actAsWorker($run->sessionId ?? throw new \LogicException('Seeded.'));

        $result = $this->wait((string) $run->sessionId, [['key' => 'card.in_review']], 5);

        self::assertSame((string) $run->id, (string) $this->onlyWait()->runId);
    }

    /**
     * @param array<mixed>[] $conditions
     *
     * @return array<string, mixed>
     */
    private function wait(string $sessionId, array $conditions, int $limit): array
    {
        $storage = self::getContainer()->get('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $storage);
        $held = AuthenticatedCredential::of($storage->getToken())?->workerSessionId;
        if ((null === $held || $this->autoCredential) && Uuid::isValid($sessionId)) {
            $this->actAsWorker(Uuid::fromString($sessionId));
            $this->autoCredential = true;
        }

        return $this->tool()($sessionId, $conditions, $limit);
    }

    private function tool(): RunWaitTool
    {
        $tool = self::getContainer()->get(RunWaitTool::class);
        self::assertInstanceOf(RunWaitTool::class, $tool);

        return $tool;
    }

    /** @param array<mixed>[] $conditions */
    private function refused(string $sessionId, array $conditions, int $limit, string $message): void
    {
        try {
            $this->wait($sessionId, $conditions, $limit);
            self::fail('Expected a refusal.');
        } catch (ToolCallException $e) {
            self::assertSame($message, $e->getMessage());
        }
    }

    private function workerRun(WorkerRunState $state = WorkerRunState::Running, ?WorkerRun $continues = null, ?Uuid $sessionId = null): WorkerRun
    {
        $run = $this->seedRun($this->em(), $this->project, bridgeId: $this->bridge->id, cardId: $this->cardId, state: $state, runKey: Uuid::v7());
        $run->continuesRun = $continues;
        if (null !== $sessionId) {
            $run->sessionId = $sessionId;
        }
        $this->em()->flush();

        return $run;
    }

    private function actAsWorker(Uuid $sessionId): void
    {
        $owner = $this->project->owner;
        $token = new PostAuthenticationToken($owner, 'mcp', [...$owner->getRoles(), ApiScope::Mcp->role()]);
        $token->setAttribute(AuthenticatedCredential::ATTRIBUTE, new AuthenticatedCredential(
            'oauth:test:worker',
            [ApiScope::Mcp->role(), ApiScope::WorkerRun->role()],
            $this->project->id,
            workerBridgeId: $this->bridge->id,
            workerRunKey: Uuid::v7(),
            workerSessionId: $sessionId,
        ));
        $storage = self::getContainer()->get('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $storage);
        $storage->setToken($token);
    }

    private function waitCount(): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM bridge_worker_waits WHERE project_id = ?', [$this->project->requireId()->toRfc4122()]);
    }

    private function onlyWait(): WorkerWait
    {
        $this->em()->clear();
        $waits = $this->em()->createQueryBuilder()->select('w')->from(WorkerWait::class, 'w')->andWhere('w.project = :project')->setParameter('project', $this->project)->getQuery()->getResult();
        self::assertCount(1, $waits);

        return $waits[0];
    }

    private function onlyOpenWait(): WorkerWait
    {
        $wait = $this->em()->createQueryBuilder()->select('w')->from(WorkerWait::class, 'w')->andWhere('w.project = :project AND w.endedAt IS NULL')->setParameter('project', $this->project)->getQuery()->getOneOrNullResult();
        self::assertNotNull($wait);

        return $wait;
    }
}
