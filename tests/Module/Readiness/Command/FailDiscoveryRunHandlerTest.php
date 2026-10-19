<?php

declare(strict_types=1);

namespace App\Tests\Module\Readiness\Command;

use App\Module\Readiness\Command\FailDiscoveryRunCommand;
use App\Module\Readiness\Command\FailDiscoveryRunHandler;
use App\Module\Readiness\Entity\DiscoveryRunState;
use App\Tests\Module\Readiness\DiscoveryScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class FailDiscoveryRunHandlerTest extends KernelTestCase
{
    use DiscoveryScenario;

    public function test_a_revision_that_ends_after_the_approval_committed_leaves_the_run_done(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('discovery-fail-late');
        $this->bindLifecycle($project);
        $card = $this->discoveryCard($project);
        $run = $this->discoveryRun($card, DiscoveryRunState::Revising);
        // Another request approved the report, so the row is Done while this request still holds a Revising run.
        $this->em()->getConnection()->executeStatement('UPDATE discovery_runs SET state = ? WHERE id = ?', [DiscoveryRunState::Done->value, $run->id?->toRfc4122()]);

        $handler = self::getContainer()->get(FailDiscoveryRunHandler::class);
        self::assertInstanceOf(FailDiscoveryRunHandler::class, $handler);
        $failed = $handler(new FailDiscoveryRunCommand($card->id ?? throw new \LogicException('A stored card has an id.'), 'worker-failed', FailDiscoveryRunHandler::REVISE_RULE_ID));

        self::assertFalse($failed);
        self::assertSame(DiscoveryRunState::Done, $run->state);
        self::assertSame(DiscoveryRunState::Done->value, $this->em()->getConnection()->fetchOne('SELECT state FROM discovery_runs WHERE id = ?', [$run->id?->toRfc4122()]));
    }

    public function test_a_revising_run_fails_when_its_worker_ends(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('discovery-fail-revising');
        $this->bindLifecycle($project);
        $card = $this->discoveryCard($project);
        $run = $this->discoveryRun($card, DiscoveryRunState::Revising);

        $handler = self::getContainer()->get(FailDiscoveryRunHandler::class);
        self::assertInstanceOf(FailDiscoveryRunHandler::class, $handler);

        self::assertTrue($handler(new FailDiscoveryRunCommand($card->id ?? throw new \LogicException('A stored card has an id.'), 'worker-failed', FailDiscoveryRunHandler::REVISE_RULE_ID)));
        self::assertSame([DiscoveryRunState::Failed, 'worker-failed'], [$run->state, $run->failureReason]);
    }
}
