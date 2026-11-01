<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Service\Dev;

use App\Module\Board\Repository\BoardColumnRepository;
use App\Module\Board\Repository\CardEventRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Service\PullRequestUrlResolver;
use App\Module\Forge\Repository\ForgePullRequestRepository;
use App\Module\Inbox\Repository\InboxCardWatchRepository;
use App\Module\Inbox\Repository\InboxItemRepository;
use App\Module\Inbox\Service\Dev\ProjectShowcaseSeeder;
use App\Module\Inbox\Service\InboxAvailability;
use App\Module\Inbox\Service\InboxSearchIndexer;
use App\Module\Inbox\Service\SessionBridges;
use App\Module\Review\Service\DocumentTagApplier;
use App\Module\SiteReview\Repository\SiteReviewCommentRepository;
use App\Module\Workflow\Contract\CardEvaluations;
use App\Tests\Module\Board\BoardColumnFixtures;
use App\Tests\Module\Bridge\BridgeScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ProjectShowcaseSeederTest extends KernelTestCase
{
    use BoardColumnFixtures;
    use BridgeScenario;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function test_the_showcase_holds_a_live_bridge_that_opens_sessions(): void
    {
        $em = $this->em();
        $owner = $this->user($em, 'showcase-'.uniqid().'@example.com');
        $project = $this->project($em, $owner, 'Showcase');
        $this->seedColumns($project);
        $em->flush();

        self::assertFalse($this->service(SessionBridges::class)->onlineFor([$project])[(string) $project->id]);

        $this->seeder()($project, $owner, $this->user($em, 'showcase-reviewer-'.uniqid().'@example.com'));

        self::assertTrue($this->service(SessionBridges::class)->onlineFor([$project])[(string) $project->id]);
    }

    private function seeder(): ProjectShowcaseSeeder
    {
        return new ProjectShowcaseSeeder(
            $this->em(),
            $this->service(BoardColumnRepository::class),
            $this->service(CardRepository::class),
            $this->service(InboxItemRepository::class),
            $this->service(SiteReviewCommentRepository::class),
            $this->service(PullRequestUrlResolver::class),
            $this->service(ForgePullRequestRepository::class),
            $this->service(InboxSearchIndexer::class),
            $this->service(CardEvaluations::class),
            $this->service(InboxCardWatchRepository::class),
            $this->service(InboxAvailability::class),
            $this->service(DocumentTagApplier::class),
            $this->service(CardEventRepository::class),
        );
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
