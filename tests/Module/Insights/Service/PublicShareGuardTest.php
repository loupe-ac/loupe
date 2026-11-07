<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Service;

use App\Module\Insights\Entity\PublicLink;
use App\Module\Insights\Service\PublicShareBlock;
use App\Module\Insights\Service\PublicShareGuard;
use App\Tests\Module\Insights\InsightsScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Ubermuda\FeatureFlagsBundle\Repository\FeatureFlagRepository;

final class PublicShareGuardTest extends KernelTestCase
{
    use InsightsScenario;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function test_a_share_is_open_while_the_instance_flag_is_on(): void
    {
        self::assertNull($this->guard()->blockedBy($this->scenarioProject('guard-open')));
    }

    public function test_the_instance_flag_blocks_a_share_with_its_reason(): void
    {
        $project = $this->scenarioProject('guard-flag-off');
        $this->setFlag(false);

        $block = $this->guard()->blockedBy($project);

        self::assertSame(PublicShareBlock::FlagOff, $block);
        self::assertSame('insights.public_link.block.flag_off', $block->translationKey());
    }

    public function test_a_takedown_blocks_a_share_with_its_reason_and_only_for_its_project(): void
    {
        $taken = $this->scenarioProject('guard-taken');
        $spared = $this->scenarioProject('guard-spared');
        $this->seedTakedown($taken);

        $block = $this->guard()->blockedBy($taken);

        self::assertSame(PublicShareBlock::TakenDown, $block);
        self::assertSame('insights.public_link.block.taken_down', $block->translationKey());
        self::assertNull($this->guard()->blockedBy($spared));
    }

    public function test_the_flag_wins_over_a_takedown(): void
    {
        $project = $this->scenarioProject('guard-both');
        $this->seedTakedown($project);
        $this->setFlag(false);

        self::assertSame(PublicShareBlock::FlagOff, $this->guard()->blockedBy($project));
    }

    private function guard(): PublicShareGuard
    {
        $guard = self::getContainer()->get(PublicShareGuard::class);
        self::assertInstanceOf(PublicShareGuard::class, $guard);

        return $guard;
    }

    private function setFlag(bool $value): void
    {
        $flags = self::getContainer()->get(FeatureFlagRepository::class);
        self::assertInstanceOf(FeatureFlagRepository::class, $flags);
        $flags->findAllIndexed()[PublicLink::FLAG]->value = $value;
        $this->em()->flush();
    }
}
