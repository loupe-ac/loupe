<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Service;

use App\Exception\DomainErrors;
use App\Module\Insights\Entity\InsightsSavedGraph;
use App\Module\Insights\Repository\InsightsSavedGraphRepository;
use App\Module\Insights\Service\SavedGraphCap;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Insights\InsightsScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Ubermuda\FeatureFlagsBundle\FeatureFlagService;
use Ubermuda\FeatureFlagsBundle\Repository\FeatureFlagRepository;

final class SavedGraphCapTest extends KernelTestCase
{
    use InsightsScenario;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function test_the_limit_is_twelve_by_default(): void
    {
        self::assertSame(12, $this->cap()->limit());
    }

    public function test_a_project_below_the_cap_has_room(): void
    {
        $project = $this->fillProject('cap-below', 11);

        self::assertFalse($this->cap()->isReached($project));
        $this->cap()->assertRoom($project);
    }

    public function test_a_project_at_the_cap_has_no_room(): void
    {
        $project = $this->fillProject('cap-at', 12);

        self::assertTrue($this->cap()->isReached($project));
        try {
            $this->cap()->assertRoom($project);
            self::fail('Expected DomainErrors.');
        } catch (DomainErrors $e) {
            self::assertSame(['name' => SavedGraphCap::ERROR_KEY], $e->errors);
            self::assertSame(['name' => ['%limit%' => 12]], $e->parameters);
        }
    }

    public function test_a_removed_graph_frees_its_slot(): void
    {
        $project = $this->scenarioProject('cap-removed');
        $graphs = $this->seedGraphs($project, 12);
        $this->seedSavedGraph($project, 'Gone', 20, new \DateTimeImmutable('2026-10-01 10:00:00'));
        self::assertTrue($this->cap()->isReached($project));

        $graphs[0]->removedAt = new \DateTimeImmutable('2026-10-02 10:00:00');
        $this->em()->flush();

        self::assertFalse($this->cap()->isReached($project));
    }

    public function test_the_graphs_of_another_project_do_not_count(): void
    {
        $this->fillProject('cap-other', 12);

        self::assertFalse($this->cap()->isReached($this->scenarioProject('cap-empty')));
    }

    public function test_the_flag_can_lower_the_cap(): void
    {
        $project = $this->fillProject('cap-flag-low', 3);
        $this->setCap(3);

        self::assertSame(3, $this->cap()->limit());
        self::assertTrue($this->cap()->isReached($project));
    }

    public function test_the_flag_can_raise_the_cap(): void
    {
        $project = $this->fillProject('cap-flag-high', 12);
        $this->setCap(13);

        self::assertSame(13, $this->cap()->limit());
        self::assertFalse($this->cap()->isReached($project));
    }

    private function cap(): SavedGraphCap
    {
        $savedGraphs = self::getContainer()->get(InsightsSavedGraphRepository::class);
        self::assertInstanceOf(InsightsSavedGraphRepository::class, $savedGraphs);
        $featureFlags = self::getContainer()->get(FeatureFlagService::class);
        self::assertInstanceOf(FeatureFlagService::class, $featureFlags);

        return new SavedGraphCap($savedGraphs, $featureFlags);
    }

    private function setCap(int $value): void
    {
        $flags = self::getContainer()->get(FeatureFlagRepository::class);
        self::assertInstanceOf(FeatureFlagRepository::class, $flags);
        $flags->findAllIndexed()[SavedGraphCap::FLAG]->value = $value;
        $this->em()->flush();
    }

    private function fillProject(string $name, int $count): Project
    {
        $project = $this->scenarioProject($name);
        $this->seedGraphs($project, $count);

        return $project;
    }

    /** @return list<InsightsSavedGraph> */
    private function seedGraphs(Project $project, int $count): array
    {
        $graphs = [];
        for ($i = 0; $i < $count; ++$i) {
            $graphs[] = $this->seedSavedGraph($project, 'Graph '.$i, $i);
        }

        return $graphs;
    }
}
