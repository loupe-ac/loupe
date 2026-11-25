<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Service\Dev;

use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\Repository\InboxItemRepository;
use App\Module\Inbox\Service\Dev\DevDiscoveryReviewSeeder;
use App\Module\Project\Service\Dev\DevProjectSeeder;
use App\Module\Readiness\Entity\DiscoveryRunState;
use App\Module\Readiness\Repository\DiscoveryRunRepository;
use App\Tests\DevSeed\DevProjectFixture;
use App\Tests\DevSeed\SeedContextFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DevDiscoveryReviewSeederTest extends KernelTestCase
{
    public function test_two_runs_seed_one_waiting_report_with_its_open_item(): void
    {
        self::bootKernel();
        $context = SeedContextFactory::create(self::getContainer());
        DevProjectFixture::create(self::getContainer(), $context);
        $seeder = self::getContainer()->get(DevDiscoveryReviewSeeder::class);
        self::assertInstanceOf(DevDiscoveryReviewSeeder::class, $seeder);

        $seeder->seed($context);
        $seeder->seed($context);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $em->clear();

        $runs = self::getContainer()->get(DiscoveryRunRepository::class);
        self::assertInstanceOf(DiscoveryRunRepository::class, $runs);
        $run = $runs->find(DevDiscoveryReviewSeeder::runId()) ?? throw new \LogicException('The run is missing.');
        self::assertSame(DiscoveryRunState::Reported, $run->state);
        self::assertSame((string) DevProjectSeeder::setupDoneProjectId(), (string) $run->project->id);
        $items = self::getContainer()->get(InboxItemRepository::class);
        self::assertInstanceOf(InboxItemRepository::class, $items);
        $item = $items->findOpenForDiscoveryRun($run->project, DevDiscoveryReviewSeeder::runId()) ?? throw new \LogicException('The item is missing.');
        self::assertSame([(string) DevDiscoveryReviewSeeder::itemId(), InboxItemState::Open, false], [(string) $item->id, $item->state, $item->isAutomatic()]);
        self::assertSame(1, $items->count(['project' => $run->project]));
    }
}
