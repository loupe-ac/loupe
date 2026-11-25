<?php

declare(strict_types=1);

namespace App\Tests\Module\Readiness\Service\Dev;

use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Project\Service\Dev\DevProjectSeeder;
use App\Module\Readiness\Entity\DiscoveryRun;
use App\Module\Readiness\Entity\DiscoveryRunState;
use App\Module\Readiness\Repository\DiscoveryRunRepository;
use App\Module\Readiness\Service\Dev\DevDiscoveryRunSeeder;
use App\Tests\DevSeed\DevProjectFixture;
use App\Tests\DevSeed\SeedContextFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DevDiscoveryRunSeederTest extends KernelTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function test_it_seeds_a_live_run_with_no_card_and_an_ended_run_with_its_worker(): void
    {
        $context = SeedContextFactory::create(self::getContainer());
        DevProjectFixture::create(self::getContainer(), $context);

        $this->service(DevDiscoveryRunSeeder::class)->seed($context);
        $this->service(EntityManagerInterface::class)->clear();

        $runs = $this->service(DiscoveryRunRepository::class);
        $live = $runs->find(DevDiscoveryRunSeeder::runId()) ?? throw new \LogicException('The live run is missing.');
        self::assertSame(DiscoveryRunState::Requested, $live->state);
        self::assertNull($live->card);
        self::assertSame((string) DevProjectSeeder::lifecycleProjectId(), (string) $live->project->id);
        self::assertSame($live, $runs->latestForProject($live->project));

        $worker = $this->service(WorkerRunRepository::class)->find(DevDiscoveryRunSeeder::workerRunId()) ?? throw new \LogicException('The worker run is missing.');
        self::assertSame(DiscoveryRun::SUBJECT_TYPE, $worker->subjectType);
        self::assertNull($worker->cardNumber);
        self::assertSame($live->project, $worker->project);
        // An open run of a bridge that never beats times out, and that end would fail a live discovery run.
        self::assertSame(WorkerRunState::Succeeded, $worker->state);
        $ended = $runs->find($worker->subjectId) ?? throw new \LogicException('The run of the worker is missing.');
        self::assertSame(DiscoveryRunState::Done, $ended->state);
        self::assertNull($ended->card);
    }

    public function test_a_second_run_writes_no_row_and_restores_a_missing_worker_run(): void
    {
        $context = SeedContextFactory::create(self::getContainer());
        DevProjectFixture::create(self::getContainer(), $context);
        $seeder = $this->service(DevDiscoveryRunSeeder::class);
        $seeder->seed($context);
        $em = $this->service(EntityManagerInterface::class);
        $workers = $this->service(WorkerRunRepository::class);
        $em->remove($workers->find(DevDiscoveryRunSeeder::workerRunId()) ?? throw new \LogicException('The worker run is missing.'));
        $em->flush();
        $em->clear();

        $seeder->seed($context);
        $seeder->seed($context);
        $em->clear();

        self::assertNotNull($workers->find(DevDiscoveryRunSeeder::workerRunId()));
        self::assertSame(1, $workers->count(['subjectType' => DiscoveryRun::SUBJECT_TYPE]));
        self::assertSame(2, $this->service(DiscoveryRunRepository::class)->count([]));
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
