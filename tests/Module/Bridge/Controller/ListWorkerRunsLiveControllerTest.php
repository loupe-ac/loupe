<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Controller;

use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Tests\Module\Bridge\BridgeScenario;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

/** The live read of the Runs list answers one stream for the list frame and the shown count. */
final class ListWorkerRunsLiveControllerTest extends WebTestCase
{
    use BridgeScenario;

    public function test_it_answers_the_frame_and_the_shown_count_in_one_stream(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'runs-live-owner@example.com');
        $project = $this->project($em, $owner, 'Runs live');
        $run = $this->seedRun($em, $project, exitCode: 1, state: WorkerRunState::Failed);
        $this->seedRun($em, $project, state: WorkerRunState::Running);
        $projectId = (string) $project->id;
        $runId = (string) $run->id;
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/worker-runs/live?outcome=failed');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/vnd.turbo-stream.html; charset=UTF-8');
        $body = (string) $client->getResponse()->getContent();
        self::assertSame(1, substr_count($body, '<turbo-stream action="update" target="worker-runs-frame">'));
        self::assertSame(1, substr_count($body, '<turbo-stream action="update" target="worker-runs-shown">'));
        self::assertStringContainsString('data-worker-run-id="'.$runId.'"', $body);
        self::assertSame(1, substr_count($body, 'data-worker-run-id="'));
        // The new live region carries the filter, so the next signal reads the same list.
        self::assertStringContainsString('/projects/'.$projectId.'/worker-runs/live?page=1&amp;outcome=failed', $body);
    }

    public function test_a_page_past_the_end_reads_the_last_page(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'runs-live-clamp@example.com');
        $project = $this->project($em, $owner, 'Runs live clamp');
        $run = $this->seedRun($em, $project);
        $projectId = (string) $project->id;
        $runId = (string) $run->id;
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/worker-runs/live?page=9');

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('data-worker-run-id="'.$runId.'"', $body);
        self::assertStringContainsString('/worker-runs/live?page=1', $body);
    }

    public function test_the_read_keeps_the_flashes_of_the_session(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'runs-live-flash@example.com');
        $project = $this->project($em, $owner, 'Runs live flash');
        $this->seedRun($em, $project);
        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/worker-runs/live');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('data-worker-run-command-flash', (string) $client->getResponse()->getContent());
    }

    public function test_a_user_outside_the_project_is_refused(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'runs-live-theirs@example.com');
        $stranger = $this->user($em, 'runs-live-stranger@example.com');
        $project = $this->project($em, $owner, 'Runs live private');
        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($stranger);
        $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/worker-runs/live');

        self::assertResponseStatusCodeSame(403);
    }
}
