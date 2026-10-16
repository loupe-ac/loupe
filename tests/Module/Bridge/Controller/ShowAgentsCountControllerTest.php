<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Controller;

use App\Tests\Module\Bridge\BridgeScenario;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class ShowAgentsCountControllerTest extends WebTestCase
{
    use BridgeScenario;

    public function test_the_frame_adds_up_the_pools_of_the_live_bridges_that_follow_the_project(): void
    {
        $client = static::createClient();
        $em = $this->em();

        $owner = $this->user($em, 'agents-count-owner@example.com');
        $project = $this->project($em, $owner, 'Counted');
        $other = $this->project($em, $owner, 'Elsewhere');
        $projectId = (string) $project->id;
        $this->seedBridge($em, $owner, projects: [$projectId], workerPools: [
            ['name' => 'default', 'size' => 2, 'inUse' => 1, 'queued' => 0],
            ['name' => 'heavy', 'size' => 1, 'inUse' => 1, 'queued' => 3],
        ]);
        $this->seedBridge($em, $owner, projects: [$projectId, (string) $other->id], workerPools: [
            ['name' => 'default', 'size' => 4, 'inUse' => 2, 'queued' => 0],
        ]);
        $this->seedBridge($em, $owner, projects: [(string) $other->id], workerPools: [
            ['name' => 'default', 'size' => 9, 'inUse' => 9, 'queued' => 0],
        ]);
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/agents/count');

        self::assertResponseIsSuccessful();
        self::assertTrue($client->getResponse()->headers->hasCacheControlDirective('no-store'));
        self::assertCount(1, $crawler->filter('turbo-frame#agents-count-'.$projectId));
        self::assertSame('4/7', trim($crawler->filter('[data-agents-count]')->text()));
    }

    public function test_the_frame_leaves_out_a_quiet_bridge_and_a_bridge_with_no_pool_report(): void
    {
        $client = static::createClient();
        $em = $this->em();

        $owner = $this->user($em, 'agents-count-quiet@example.com');
        $project = $this->project($em, $owner, 'Quiet');
        $projectId = (string) $project->id;
        $pools = [['name' => 'default', 'size' => 3, 'inUse' => 3, 'queued' => 0]];
        $this->seedBridge($em, $owner, projects: [$projectId], lastSeenAt: new \DateTimeImmutable('-1 day'), workerPools: $pools);
        $this->seedBridge($em, $owner, projects: [$projectId]);
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/agents/count');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('turbo-frame#agents-count-'.$projectId));
        self::assertCount(0, $crawler->filter('[data-agents-count]'));
    }

    public function test_the_frame_is_refused_to_a_stranger(): void
    {
        $client = static::createClient();
        $em = $this->em();

        $project = $this->project($em, $this->user($em, 'agents-count-host@example.com'), 'Private');
        $stranger = $this->user($em, 'agents-count-stranger@example.com');
        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($stranger);
        $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/agents/count');

        self::assertResponseStatusCodeSame(403);
    }

    public function test_the_sidebar_shows_the_figures_on_the_agents_link_and_listens_for_run_changes(): void
    {
        $client = static::createClient();
        $em = $this->em();

        $owner = $this->user($em, 'agents-count-sidebar@example.com');
        $project = $this->project($em, $owner, 'Sidebar');
        $projectId = (string) $project->id;
        $this->seedBridge($em, $owner, projects: [$projectId], workerPools: [
            ['name' => 'default', 'size' => 3, 'inUse' => 2, 'queued' => 0],
        ]);
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/worker-runs');

        self::assertResponseIsSuccessful();
        $link = $crawler->filter('a.lp-sidebar__link[href="/projects/'.$projectId.'/agents"]');
        self::assertCount(1, $link);
        self::assertSame('live-pill', $link->attr('data-controller'));
        self::assertSame('["worker_run.changed"]', $link->attr('data-live-pill-events-value'));
        self::assertSame('/projects/'.$projectId.'/agents/count', $link->attr('data-live-pill-url-value'));
        self::assertSame('2/3', trim($link->filter('turbo-frame[data-live-pill-target="frame"] [data-agents-count]')->text()));
    }
}
