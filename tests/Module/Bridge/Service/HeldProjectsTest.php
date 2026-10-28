<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Service;

use App\Module\Bridge\Service\HeldProjects;
use App\Tests\Module\Bridge\BlockingRepositories;
use App\Tests\Module\Bridge\BridgeScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class HeldProjectsTest extends KernelTestCase
{
    use BlockingRepositories;
    use BridgeScenario;

    public function test_it_names_the_projects_with_a_blocking_gap_alone(): void
    {
        self::bootKernel();
        $owner = $this->user($this->em(), 'held-projects@example.com');
        $held = $this->project($this->em(), $owner, 'Held');
        $fits = $this->project($this->em(), $owner, 'Fits');
        $bare = $this->project($this->em(), $owner, 'Bare');
        $this->repositoryWithGaps($held);
        $this->repositoryWithGaps($fits, []);
        $service = self::getContainer()->get(HeldProjects::class);
        self::assertInstanceOf(HeldProjects::class, $service);

        $ids = [(string) $bare->id, (string) $held->id, (string) $fits->id, (string) Uuid::v7()];

        self::assertSame([(string) $held->id], $service->among($ids));
        self::assertSame([], $service->among([]));
    }
}
