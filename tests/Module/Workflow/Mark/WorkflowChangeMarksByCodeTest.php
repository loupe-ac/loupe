<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Mark;

use App\Module\Bridge\Command\RecordBridgeHeartbeatCommand;
use App\Module\Bridge\Command\RecordBridgeHeartbeatHandler;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Workflow\Repository\WorkflowRuleStateRepository;
use App\Module\Workflow\Service\WorkflowRuleRearmer;
use App\Tests\Module\Bridge\BridgeScenario;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

/** The marks of the code paths that write fact tables in bulk, or that the triggers treat on their own. */
final class WorkflowChangeMarksByCodeTest extends KernelTestCase
{
    use BridgeScenario;

    #[Test]
    public function a_rearm_marks_the_card_unless_the_check_of_that_card_makes_it(): void
    {
        $world = $this->world();
        $rearmer = $this->service(WorkflowRuleRearmer::class);
        $states = $this->service(WorkflowRuleStateRepository::class);
        $card = Uuid::fromString(MarkWorld::id('C1'));

        $world->clearMarks();
        $rearmer->rearmCards([$card], new \DateTimeImmutable('2026-03-01 10:00:00'));
        self::assertSame([['C1'], []], $world->marks(), 'A rearm outside a check marks the card.');

        try {
            $states->declareCheck($card);
            $world->clearMarks();
            $rearmer->rearmCards([$card], new \DateTimeImmutable('2026-03-01 10:01:00'));
            self::assertSame([[], []], $world->marks(), 'The check of the card writes its own rule states.');

            $states->declareCheck(Uuid::fromString(MarkWorld::id('C2')));
            $world->clearMarks();
            $rearmer->rearmCards([$card], new \DateTimeImmutable('2026-03-01 10:02:00'));
            self::assertSame([['C1'], []], $world->marks(), 'The check of another card marks the card whose states it writes.');
        } finally {
            $world->db->executeStatement("SELECT set_config('app.workflow_checking_card', '', true)");
        }
    }

    #[Test]
    public function a_baseline_of_a_project_marks_each_card_of_that_project(): void
    {
        $world = $this->world();
        $world->clearMarks();

        $this->service(WorkflowRuleRearmer::class)->baselineProject(Uuid::fromString(MarkWorld::id('PX')));

        self::assertSame([['B', 'C1', 'C2', 'N1', 'P', 'S', 'U', 'W'], []], $world->marks());
    }

    #[Test]
    public function a_hold_for_a_withdrawn_request_and_the_release_of_the_work_holds_mark_the_card_of_the_state(): void
    {
        $world = $this->world();
        $rearmer = $this->service(WorkflowRuleRearmer::class);
        $world->rows->update('workflow_rule_states', ['card_id' => MarkWorld::id('C1'), 'rule_id' => 'world'], ['work_request_id' => MarkWorld::id('WR0')]);

        $world->clearMarks();
        $rearmer->holdForWithdrawnRequests([Uuid::fromString(MarkWorld::id('WR0'))], new \DateTimeImmutable('2026-03-01 10:00:00'));
        self::assertSame([['C1'], []], $world->marks(), 'A hold marks the card of the rule state.');

        $world->clearMarks();
        $released = $rearmer->releaseWorkHolds(Uuid::fromString(MarkWorld::id('PX')), new \DateTimeImmutable('2026-03-01 10:01:00'));
        self::assertCount(1, $released, 'Guard: the release found the stamp of the hold.');
        self::assertSame([['C1'], []], $world->marks(), 'A release marks the card of the rule state.');
    }

    #[Test]
    public function a_heartbeat_of_a_quiet_bridge_marks_the_projects_of_its_owner_and_a_live_one_marks_nothing(): void
    {
        self::bootKernel();
        self::getContainer()->set('clock', new MockClock('2026-09-23 12:00:00'));
        $em = $this->em();
        $owner = $this->user($em, 'marks-heartbeat@example.com');
        $other = $this->user($em, 'marks-heartbeat-other@example.com');
        $project = $this->project($em, $owner, 'Marks Heartbeat');
        $this->project($em, $other, 'Marks Heartbeat Other');
        $projects = [(string) $project->id];
        $quiet = $this->seedBridge($em, $owner, projects: $projects, lastSeenAt: new \DateTimeImmutable('2026-09-23 11:56:59'));
        $live = $this->seedBridge($em, $owner, projects: $projects, lastSeenAt: new \DateTimeImmutable('2026-09-23 11:57:00'));
        $handler = $this->service(RecordBridgeHeartbeatHandler::class);

        $this->clearMarks();
        $handler(new RecordBridgeHeartbeatCommand($owner, $live->id, $projects, 'b4e39aa7'));
        self::assertSame([[], []], $this->marked(), 'A bridge that is live marks nothing.');

        $handler(new RecordBridgeHeartbeatCommand($owner, $quiet->id, $projects, 'b4e39aa7'));
        self::assertSame([[], [(string) $project->id]], $this->marked(), 'A bridge that came back marks the projects of its owner, and no other.');
    }

    #[Test]
    public function the_reopen_of_a_lapsed_claim_marks_the_card_of_the_request(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'marks-lapsed@example.com'), 'Marks Lapsed');
        $lapsedCard = Uuid::v4();
        $heldCard = Uuid::v4();
        $now = new \DateTimeImmutable('2026-10-01 12:00:00');
        $lapsed = $this->seedWorkRequest($em, $project, $lapsedCard, state: WorkRequestState::Claimed, bridgeId: Uuid::v4(), claimToken: Uuid::v4(), leaseUntil: $now);
        $this->seedWorkRequest($em, $project, $heldCard, state: WorkRequestState::Claimed, bridgeId: Uuid::v4(), claimToken: Uuid::v4(), leaseUntil: $now->modify('+1 second'));

        $this->clearMarks();
        $reopened = $this->service(WorkRequestRepository::class)->reopenLapsed($now, [], new \DateTimeImmutable('2000-01-01'));

        self::assertSame([$lapsed], $reopened, 'Guard: the sweep reopened the lapsed claim.');
        self::assertSame([[$lapsedCard->toRfc4122()], []], $this->marked(), 'The card of the reopened request is marked, and the card of a held claim is not.');
    }

    private function world(): MarkWorld
    {
        self::bootKernel();
        $connection = $this->connection();

        return MarkWorld::seed(new RawRows($connection), $connection);
    }

    /** @return array{list<string>, list<string>} the marked card ids and project ids, sorted */
    private function marked(): array
    {
        $cards = $this->connection()->fetchFirstColumn('SELECT card_id FROM workflow_card_changes');
        $projects = $this->connection()->fetchFirstColumn('SELECT project_id FROM workflow_project_changes');
        sort($cards);
        sort($projects);

        return [$cards, $projects];
    }

    private function clearMarks(): void
    {
        $this->connection()->executeStatement('DELETE FROM workflow_card_changes');
        $this->connection()->executeStatement('DELETE FROM workflow_project_changes');
    }

    private function connection(): Connection
    {
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function service(string $class): object
    {
        $service = self::getContainer()->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }
}
