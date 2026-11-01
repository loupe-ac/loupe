<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Repository;

use App\Module\Board\Repository\PullRequestCommentRepository;
use App\Module\Bridge\Repository\BridgeRepository;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Tests\Module\Bridge\BridgeScenario;
use Doctrine\Deprecations\Deprecation;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class JoinConditionDeprecationTest extends KernelTestCase
{
    use BridgeScenario;

    private const string LINK = 'https://github.com/doctrine/orm/issues/12192';

    public function test_no_query_joins_with_the_deprecated_keyword(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $em = $this->em();
        $owner = $this->user($em, 'join-deprecation@example.com');
        $project = $this->project($em, $owner, 'Join Deprecation');

        Deprecation::enableTrackingDeprecations();
        $before = Deprecation::getTriggeredDeprecations()[self::LINK] ?? 0;

        $container->get(PullRequestCommentRepository::class)->findUncommentedRecentRuns(Uuid::v4(), 'implementation', new \DateTimeImmutable());
        $container->get(BridgeRepository::class)->findOneFollowingProject((string) $project->id, (string) Uuid::v4());
        $container->get(WorkerRunRepository::class)->distinctValuesOf($project, 'model');

        self::assertSame($before, Deprecation::getTriggeredDeprecations()[self::LINK] ?? 0);
    }
}
