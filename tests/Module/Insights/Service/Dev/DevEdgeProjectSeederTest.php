<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Service\Dev;

use App\DevSeed\DevSeedId;
use App\DevSeed\SeedContext;
use App\Module\Account\Entity\User;
use App\Module\Account\Repository\UserRepository;
use App\Module\Account\Service\Dev\DevAccountSeeder;
use App\Module\Insights\Entity\InsightsSavedGraph;
use App\Module\Insights\Repository\InsightsSavedGraphRepository;
use App\Module\Insights\Service\Dev\DevEdgeProjectSeeder;
use App\Module\Insights\Service\SavedGraphCap;
use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use App\Tests\DevSeed\DevProjectFixture;
use App\Tests\DevSeed\SeedContextFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DevEdgeProjectSeederTest extends KernelTestCase
{
    private const string CAP_GRAPH = 'insights/graph/graph-cap/cost-card';
    private const string BROKEN_GRAPH = 'insights/graph/broken-graph/bucket-time-tests-run';

    protected function setUp(): void
    {
        self::bootKernel();
    }

    /** @return iterable<string, array{string}> */
    public static function edgeProjectNames(): iterable
    {
        yield 'no runs' => [DevEdgeProjectSeeder::NO_RUNS_NAME];
        yield 'graph cap' => [DevEdgeProjectSeeder::AT_CAP_NAME];
        yield 'broken graph' => [DevEdgeProjectSeeder::BROKEN_GRAPH_NAME];
    }

    #[DataProvider('edgeProjectNames')]
    public function test_it_renames_an_old_project_that_holds_an_edge_name(string $name): void
    {
        $context = $this->context();
        self::getContainer()->get(DevAccountSeeder::class)->seed($context);
        $old = new Project($this->devUser(), $name);
        $this->em()->persist($old);
        $this->em()->flush();
        $oldId = $old->id ?? throw new \LogicException('A stored project has an id.');

        $this->seed();

        self::assertSame(\sprintf('%s (old %s)', $name, substr((string) $oldId, 0, 8)), $this->projects()->find($oldId)?->name);
        $seeded = $this->projects()->findOneByOwnerAndName($this->devUser(), $name) ?? throw new \LogicException('The edge project is missing.');
        self::assertNotEquals($oldId, $seeded->id);
    }

    /** @return iterable<string, array{string, int}> */
    public static function fixedGraphs(): iterable
    {
        yield 'a graph at the cap' => [self::CAP_GRAPH, SavedGraphCap::DEFAULT];
        yield 'the broken graph' => [self::BROKEN_GRAPH, 2];
    }

    #[DataProvider('fixedGraphs')]
    public function test_a_later_seed_restores_a_deleted_graph_and_keeps_the_others(string $key, int $count): void
    {
        $this->seed();
        $graph = $this->graph($key);
        $project = $graph->project;
        $this->em()->remove($graph);
        $this->em()->flush();
        $this->em()->clear();

        $this->seed();

        $restored = $this->graph($key);
        self::assertCount($count, $this->graphs()->findActive($this->projects()->find($project->id) ?? throw new \LogicException('The project is missing.')));
        self::assertNull($restored->removedAt);
    }

    #[DataProvider('fixedGraphs')]
    public function test_a_later_seed_restores_a_removed_graph(string $key, int $count): void
    {
        $this->seed();
        $graph = $this->graph($key);
        $graph->removedAt = new \DateTimeImmutable('2026-11-01 11:00:00');
        $project = $graph->project;
        $this->em()->flush();
        $this->em()->clear();

        $this->seed();

        self::assertNull($this->graph($key)->removedAt);
        self::assertCount($count, $this->graphs()->findActive($this->projects()->find($project->id) ?? throw new \LogicException('The project is missing.')));
    }

    public function test_a_later_seed_keeps_an_edited_graph_and_writes_no_row(): void
    {
        $this->seed();
        $graph = $this->graph(self::CAP_GRAPH);
        $graph->name = 'Renamed by hand';
        $this->em()->flush();
        $this->em()->clear();
        $before = \count($this->graphs()->findAll());
        self::assertGreaterThan(0, $before);

        $this->seed();

        self::assertSame('Renamed by hand', $this->graph(self::CAP_GRAPH)->name);
        self::assertCount($before, $this->graphs()->findAll());
    }

    private function seed(): void
    {
        $context = $this->context();
        DevProjectFixture::create(self::getContainer(), $context);
        self::getContainer()->get(DevEdgeProjectSeeder::class)->seed($context);
        $this->em()->clear();
    }

    private function context(): SeedContext
    {
        return SeedContextFactory::create(self::getContainer());
    }

    private function graph(string $key): InsightsSavedGraph
    {
        return $this->graphs()->find(DevSeedId::of($key)) ?? throw new \LogicException('The graph '.$key.' is missing.');
    }

    private function devUser(): User
    {
        return self::getContainer()->get(UserRepository::class)->findOneByEmail(SeedContext::DEV_EMAIL) ?? throw new \LogicException('The dev user is missing.');
    }

    private function graphs(): InsightsSavedGraphRepository
    {
        return self::getContainer()->get(InsightsSavedGraphRepository::class);
    }

    private function projects(): ProjectRepository
    {
        return self::getContainer()->get(ProjectRepository::class);
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
