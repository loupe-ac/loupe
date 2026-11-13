<?php

declare(strict_types=1);

namespace App\Tests\Module\Readiness\Service\Dev;

use App\Module\Account\Service\Dev\DevAccountSeeder;
use App\Module\Project\Repository\ProjectRepository;
use App\Module\Project\Service\Dev\DevProjectSeeder;
use App\Module\Readiness\Command\ProjectSetupState;
use App\Module\Readiness\Command\ShowProjectSetupCommand;
use App\Module\Readiness\Command\ShowProjectSetupHandler;
use App\Module\Readiness\Service\Dev\DevProjectSetupSeeder;
use App\Tests\DevSeed\SeedContextFactory;
use App\Tests\Support\MarkNewProjectsSetUp;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class DevProjectSetupSeederTest extends KernelTestCase
{
    public function test_each_project_shows_its_setup_state_and_stays_not_set_up(): void
    {
        $this->seed();

        self::assertSame(ProjectSetupState::NoRepository, $this->stateOf(DevProjectSeeder::setupNoRepositoryProjectId()));
        self::assertSame(ProjectSetupState::InitRunning, $this->stateOf(DevProjectSeeder::setupInitRunningProjectId()));
        self::assertSame(ProjectSetupState::InitStopped, $this->stateOf(DevProjectSeeder::setupInitStoppedProjectId()));
        self::assertSame(ProjectSetupState::BridgePending, $this->stateOf(DevProjectSeeder::setupBridgePendingProjectId()));
        self::assertSame(ProjectSetupState::Done, $this->stateOf(DevProjectSeeder::devProjectId()));
    }

    public function test_a_second_run_changes_no_state(): void
    {
        $this->seed();
        $this->seed();

        self::assertSame(ProjectSetupState::InitRunning, $this->stateOf(DevProjectSeeder::setupInitRunningProjectId()));
        self::assertSame(ProjectSetupState::InitStopped, $this->stateOf(DevProjectSeeder::setupInitStoppedProjectId()));
        self::assertSame(ProjectSetupState::BridgePending, $this->stateOf(DevProjectSeeder::setupBridgePendingProjectId()));
    }

    private function seed(): void
    {
        self::bootKernel();
        $context = SeedContextFactory::create(self::getContainer());
        MarkNewProjectsSetUp::$enabled = false;
        try {
            self::getContainer()->get(DevAccountSeeder::class)->seed($context);
            self::getContainer()->get(DevProjectSeeder::class)->seed($context);
            self::getContainer()->get(DevProjectSetupSeeder::class)->seed($context);
        } finally {
            MarkNewProjectsSetUp::$enabled = true;
        }
        self::getContainer()->get(EntityManagerInterface::class)->clear();
    }

    private function stateOf(Uuid $projectId): ProjectSetupState
    {
        $project = self::getContainer()->get(ProjectRepository::class)->find($projectId);
        self::assertNotNull($project);

        return (self::getContainer()->get(ShowProjectSetupHandler::class))(new ShowProjectSetupCommand($project, true))->state;
    }
}
