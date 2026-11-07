<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Service;

use App\Module\Insights\Entity\InsightsSavedGraph;
use App\Module\Insights\Entity\PublicLink;
use App\Module\Insights\Repository\InsightsSavedGraphRepository;
use App\Module\Insights\Service\GraphShareStates;
use App\Module\Insights\Service\PublicShareBlock;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Insights\InsightsScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Ubermuda\FeatureFlagsBundle\Repository\FeatureFlagRepository;

final class GraphShareStatesTest extends KernelTestCase
{
    use InsightsScenario;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function test_a_graph_that_was_never_shared_has_no_link_and_is_not_public(): void
    {
        $graph = $this->seedSavedGraph($this->scenarioProject('state-never'));

        $state = $this->states()->for($graph);

        self::assertNull($state->link);
        self::assertTrue($state->isAvailable());
        self::assertFalse($state->isPublic());
        self::assertFalse($state->isEnabled());
    }

    public function test_a_graph_with_its_link_on_is_public_and_one_with_its_link_off_keeps_the_link(): void
    {
        $project = $this->scenarioProject('state-on-off');
        $on = $this->seedSavedGraph($project, 'On', 0);
        $off = $this->seedSavedGraph($project, 'Off', 1);
        $this->seedSavedGraph($project, 'Plain', 2);
        $this->em()->persist(PublicLink::forGraph($on));
        $offLink = PublicLink::forGraph($off);
        $offLink->enabled = false;
        $this->em()->persist($offLink);
        $this->em()->flush();
        $this->em()->clear();

        $graphs = $this->active($project);
        $states = $this->states()->forGraphs($project, $graphs);

        self::assertCount(3, $states);
        self::assertTrue($states[(string) $on->id]->isPublic());
        self::assertFalse($states[(string) $off->id]->isPublic());
        self::assertSame($offLink->code, $states[(string) $off->id]->link?->code);
        self::assertNull($states[(string) $graphs[2]->id]->link);
    }

    public function test_the_flag_off_hides_the_share_and_a_link_that_is_on_is_not_public(): void
    {
        $project = $this->scenarioProject('state-flag');
        $graph = $this->seedSavedGraph($project);
        $this->em()->persist(PublicLink::forGraph($graph));
        $this->em()->flush();
        $flags = self::getContainer()->get(FeatureFlagRepository::class);
        self::assertInstanceOf(FeatureFlagRepository::class, $flags);
        $flags->findAllIndexed()[PublicLink::FLAG]->value = false;
        $this->em()->flush();

        $state = $this->states()->for($graph);

        self::assertSame(PublicShareBlock::FlagOff, $state->block);
        self::assertFalse($state->isAvailable());
        self::assertTrue($state->isEnabled());
        self::assertFalse($state->isPublic());
    }

    public function test_a_takedown_blocks_the_share_but_keeps_the_action_and_the_owner_switch(): void
    {
        $project = $this->scenarioProject('states-takedown');
        $graph = $this->seedSavedGraph($project);
        $this->em()->persist(PublicLink::forGraph($graph));
        $this->em()->flush();
        $this->seedTakedown($project);

        $state = $this->states()->for($graph);
        $all = $this->states()->forGraphs($project, [$graph]);

        self::assertSame(PublicShareBlock::TakenDown, $state->block);
        self::assertSame(PublicShareBlock::TakenDown, $all[(string) $graph->id]->block);
        self::assertTrue($state->isAvailable());
        self::assertTrue($state->isEnabled());
        self::assertFalse($state->isPublic());
    }

    private function states(): GraphShareStates
    {
        $states = self::getContainer()->get(GraphShareStates::class);
        self::assertInstanceOf(GraphShareStates::class, $states);

        return $states;
    }

    /** @return list<InsightsSavedGraph> */
    private function active(Project $project): array
    {
        $repository = self::getContainer()->get(InsightsSavedGraphRepository::class);
        self::assertInstanceOf(InsightsSavedGraphRepository::class, $repository);

        return $repository->findActive($this->em()->find(Project::class, $project->id) ?? throw new \LogicException());
    }
}
