<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Controller;

use App\Module\Bridge\ValueObject\WorkerRunKind;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Tests\Module\Bridge\BridgeScenario;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class ShowActivityOpenCountControllerTest extends WebTestCase
{
    use BridgeScenario;

    public function test_the_frame_counts_the_open_runs_of_both_kinds_and_leaves_out_other_projects(): void
    {
        $client = static::createClient();
        $em = $this->em();

        $owner = $this->user($em, 'activity-count-owner@example.com');
        $project = $this->project($em, $owner, 'Counted');
        $other = $this->project($em, $owner, 'Elsewhere');
        $this->seedRun($em, $project, cardNumber: 1, state: WorkerRunState::Queued);
        $this->seedRun($em, $project, cardNumber: 2, state: WorkerRunState::Running);
        $this->seedRun($em, $project, cardNumber: 3, state: WorkerRunState::Running, kind: WorkerRunKind::Interactive);
        $this->seedRun($em, $project, cardNumber: 4, state: WorkerRunState::Blocked);
        $this->seedRun($em, $other, cardNumber: 5, state: WorkerRunState::Running);
        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/activity/open-count');

        self::assertResponseIsSuccessful();
        self::assertTrue($client->getResponse()->headers->hasCacheControlDirective('no-store'));
        self::assertCount(1, $crawler->filter('turbo-frame#activity-open-count-'.$projectId));
        self::assertSame('3', trim($crawler->filter('[data-activity-open-count]')->text()));
    }

    public function test_the_frame_is_empty_when_no_run_is_open(): void
    {
        $client = static::createClient();
        $em = $this->em();

        $owner = $this->user($em, 'activity-count-empty@example.com');
        $project = $this->project($em, $owner, 'Quiet');
        $this->seedRun($em, $project, cardNumber: 1, state: WorkerRunState::Blocked);
        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/activity/open-count');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('turbo-frame#activity-open-count-'.$projectId));
        self::assertCount(0, $crawler->filter('[data-activity-open-count]'));
    }

    public function test_the_frame_is_refused_to_a_stranger(): void
    {
        $client = static::createClient();
        $em = $this->em();

        $project = $this->project($em, $this->user($em, 'activity-count-host@example.com'), 'Private');
        $stranger = $this->user($em, 'activity-count-stranger@example.com');
        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($stranger);
        $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/activity/open-count');

        self::assertResponseStatusCodeSame(403);
    }

    public function test_the_sidebar_shows_the_open_runs_on_the_activity_link_and_listens_for_run_changes(): void
    {
        $client = static::createClient();
        $em = $this->em();

        $owner = $this->user($em, 'activity-count-sidebar@example.com');
        $project = $this->project($em, $owner, 'Sidebar');
        $this->seedRun($em, $project, cardNumber: 1, state: WorkerRunState::Running);
        $this->seedRun($em, $project, cardNumber: 2, state: WorkerRunState::Resumed);
        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/worker-runs');

        self::assertResponseIsSuccessful();
        $link = $crawler->filter('a.lp-sidebar__link[href="/projects/'.$projectId.'/worker-runs?outcome=open"]');
        self::assertCount(1, $link);
        self::assertSame('live-pill', $link->attr('data-controller'));
        self::assertSame('["worker_run.changed"]', $link->attr('data-live-pill-events-value'));
        self::assertSame('/projects/'.$projectId.'/activity/open-count', $link->attr('data-live-pill-url-value'));
        self::assertSame('2', trim($link->filter('turbo-frame[data-live-pill-target="frame"] [data-activity-open-count]')->text()));
    }
}
