<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Service;

use App\Module\Insights\Entity\PublicLink;
use App\Module\Insights\Service\PublicLinkVisibility;
use App\Tests\Module\Insights\InsightsScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Ubermuda\FeatureFlagsBundle\Repository\FeatureFlagRepository;

final class PublicLinkVisibilityTest extends KernelTestCase
{
    use InsightsScenario;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function test_an_enabled_link_of_a_graph_on_show_resolves_to_the_link(): void
    {
        $link = $this->sharedGraph('vis-ok');

        self::assertSame($link->code, $this->visibility()->resolve($link->code)?->code);
    }

    public function test_an_unknown_code_resolves_to_nothing(): void
    {
        $this->sharedGraph('vis-known');

        self::assertNull($this->visibility()->resolve('aaaaaaaaaaaa'));
    }

    public function test_a_switched_off_link_resolves_to_nothing_and_back_on_it_shows_again(): void
    {
        $link = $this->sharedGraph('vis-off');

        $link->enabled = false;
        $this->em()->flush();
        self::assertNull($this->visibility()->resolve($link->code));

        $link->enabled = true;
        $this->em()->flush();
        self::assertNotNull($this->visibility()->resolve($link->code));
    }

    public function test_a_removed_graph_resolves_to_nothing_and_an_undo_brings_the_link_back(): void
    {
        $link = $this->sharedGraph('vis-removed');
        $graph = $link->savedGraph ?? throw new \LogicException();

        $graph->removedAt = new \DateTimeImmutable('-1 hour');
        $this->em()->flush();
        self::assertNull($this->visibility()->resolve($link->code));

        $graph->removedAt = null;
        $this->em()->flush();
        self::assertNotNull($this->visibility()->resolve($link->code));
    }

    public function test_the_instance_flag_hides_every_link(): void
    {
        $link = $this->sharedGraph('vis-flag');
        $flags = self::getContainer()->get(FeatureFlagRepository::class);
        self::assertInstanceOf(FeatureFlagRepository::class, $flags);
        $flags->findAllIndexed()[PublicLink::FLAG]->value = false;
        $this->em()->flush();

        self::assertNull($this->visibility()->resolve($link->code));
    }

    public function test_a_takedown_hides_every_link_of_the_project_and_a_lift_brings_them_back(): void
    {
        $link = $this->sharedGraph('vis-takedown');
        $other = $this->sharedGraph('vis-takedown-other');
        $takedown = $this->seedTakedown($link->project);

        self::assertNull($this->visibility()->resolve($link->code));
        self::assertNotNull($this->visibility()->resolve($other->code));

        $this->em()->remove($takedown);
        $this->em()->flush();
        self::assertSame($link->code, $this->visibility()->resolve($link->code)?->code);
    }

    private function sharedGraph(string $name): PublicLink
    {
        $graph = $this->seedSavedGraph($this->scenarioProject($name));
        $link = PublicLink::forGraph($graph);
        $this->em()->persist($link);
        $this->em()->flush();

        return $link;
    }

    private function visibility(): PublicLinkVisibility
    {
        $visibility = self::getContainer()->get(PublicLinkVisibility::class);
        self::assertInstanceOf(PublicLinkVisibility::class, $visibility);

        return $visibility;
    }
}
