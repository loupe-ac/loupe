<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Command;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Command\ClaimWorkRequestCommand;
use App\Module\Bridge\Command\ClaimWorkRequestHandler;
use App\Module\Bridge\Command\ClaimWorkRequestResult;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\ValueObject\WorkRequestRefusal;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Bridge\BlockingRepositories;
use App\Tests\Module\Bridge\BridgeScenario;
use DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A second session writes a blocking gap under the project lock, as a summary
 * write does. The rows commit, because a second session cannot see or lock
 * rows that another transaction holds, so tearDown deletes them.
 */
#[SkipDatabaseRollback]
final class ClaimWorkRequestProjectHoldLockTest extends KernelTestCase
{
    use BlockingRepositories;
    use BridgeScenario;

    private const string GAP = '[{"key":"approval-gate","mark":"blocking","state":"gap","gaps":["no_pull_request_rule"],"parameters":[]}]';

    private ?Connection $other = null;
    private User $owner;
    private Project $project;
    private Bridge $bridge;
    private WorkRequest $first;
    private WorkRequest $second;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = $this->em();
        $this->owner = $this->user($em, 'claim-hold-lock-'.uniqid().'@example.com');
        $this->project = $this->project($em, $this->owner, 'Project claim hold lock');
        $this->bridge = $this->seedBridge($em, $this->owner, projects: [(string) $this->project->id?->toRfc4122()]);
        $this->bridge->capabilities = [Bridge::CAPABILITY_WORK_REQUESTS];
        $this->bridge->accounts = [['name' => 'work', 'harness' => 'claude', 'state' => 'ready', 'reason' => null, 'kinds' => ['implement', 'fix']]];
        $em->flush();
        $this->first = $this->seedWorkRequest($em, $this->project);
        $this->second = $this->seedWorkRequest($em, $this->project, kind: 'fix');
        $this->repositoryWithGaps($this->project, []);
        $this->other = DriverManager::getConnection($em->getConnection()->getParams());
    }

    #[\Override]
    protected function tearDown(): void
    {
        if (null !== $this->other) {
            if ($this->other->isTransactionActive()) {
                $this->other->rollBack();
            }
            $this->other->close();
        }
        $connection = $this->em()->getConnection();
        if ($connection->isTransactionActive()) {
            $connection->rollBack();
        }
        $connection->executeStatement('RESET lock_timeout');
        $projectId = (string) $this->project->id;
        $connection->executeStatement("DELETE FROM audit_log WHERE subject_type = 'work_request' AND subject_id IN (SELECT id::text FROM work_requests WHERE project_id = ?)", [$projectId]);
        $connection->executeStatement('DELETE FROM outbox_events WHERE project_id = ?', [$projectId]);
        $connection->executeStatement('DELETE FROM work_requests WHERE project_id = ?', [$projectId]);
        $connection->executeStatement('DELETE FROM bridges WHERE owner_id = ?', [(string) $this->owner->id]);
        $connection->executeStatement('DELETE FROM projects WHERE id = ?', [$projectId]);
        $connection->executeStatement('DELETE FROM users WHERE id = ?', [(string) $this->owner->id]);

        parent::tearDown();
    }

    public function test_a_claim_waits_for_a_summary_write_and_is_then_refused(): void
    {
        $other = $this->other();
        $other->beginTransaction();
        $this->writeGap($other);
        $this->em()->getConnection()->executeStatement("SET lock_timeout = '300ms'");

        try {
            $this->claim($this->first);
            self::fail('The claim did not wait for the summary write another session holds.');
        } catch (DriverException $e) {
            self::assertStringContainsString('lock timeout', $e->getMessage());
            // The foreign key check of the outbox insert also waits, but only after the claim read the checks.
            self::assertStringEndsWith('FOR KEY SHARE', trim((string) $e->getQuery()?->getSQL()));
        }

        $other->commit();
        $doctrine = self::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);
        $doctrine->resetManager();

        self::assertSame(WorkRequestRefusal::ProjectHeld, $this->claim($this->first)->refusal);
        self::assertSame(WorkRequestState::Open, $this->stateOf($this->first));
    }

    public function test_a_claim_that_commits_first_stays_claimed_and_the_summary_write_waits_for_it(): void
    {
        $connection = $this->em()->getConnection();
        $connection->beginTransaction();
        $claimed = $this->claim($this->first);
        self::assertNull($claimed->refusal);

        $other = $this->other();
        $other->executeStatement("SET lock_timeout = '300ms'");
        $other->beginTransaction();
        try {
            $this->writeGap($other);
            self::fail('The summary write did not wait for the claim another session holds.');
        } catch (\Doctrine\DBAL\Exception $e) {
            self::assertStringContainsString('lock timeout', $e->getMessage());
        }
        $other->rollBack();

        $connection->commit();
        $other->beginTransaction();
        $this->writeGap($other);
        $other->commit();

        self::assertSame(WorkRequestState::Claimed, $this->stateOf($this->first));
        self::assertSame(WorkRequestRefusal::ProjectHeld, $this->claim($this->second)->refusal);
    }

    /** Takes the project lock of a summary write, then stores a blocking gap. */
    private function writeGap(Connection $connection): void
    {
        $projectId = (string) $this->project->id;
        $connection->executeStatement('SELECT id FROM projects WHERE id = ? FOR UPDATE', [$projectId]);
        $connection->executeStatement('UPDATE forge_repository_checks SET items = ? WHERE project_id = ?', [self::GAP, $projectId]);
    }

    private function other(): Connection
    {
        return $this->other ?? throw new \LogicException('setUp opens the second session.');
    }

    private function stateOf(WorkRequest $request): ?WorkRequestState
    {
        $state = $this->other()->fetchOne('SELECT state FROM work_requests WHERE id = ?', [(string) $request->id]);

        return \is_string($state) ? WorkRequestState::from($state) : null;
    }

    private function claim(WorkRequest $request): ClaimWorkRequestResult
    {
        $handler = self::getContainer()->get(ClaimWorkRequestHandler::class);
        self::assertInstanceOf(ClaimWorkRequestHandler::class, $handler);

        return $handler(new ClaimWorkRequestCommand($this->owner, $this->bridge->id, $request->id ?? throw new \LogicException('A flushed request has an id.'), 'work'));
    }
}
