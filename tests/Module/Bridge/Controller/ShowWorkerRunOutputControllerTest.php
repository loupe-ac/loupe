<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Controller;

use App\Module\Bridge\ValueObject\WorkerRunOutputLog;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Tests\Module\Bridge\BridgeScenario;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

/** The browser reads the live output log of a run in steps, and the whole log in a new tab. */
final class ShowWorkerRunOutputControllerTest extends WebTestCase
{
    use BridgeScenario;

    public function test_a_viewer_reads_the_new_text_from_an_offset(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'output-read-owner@example.com');
        $project = $this->project($em, $owner, 'Output read');
        $run = $this->seedRun($em, $project, state: WorkerRunState::Running);
        $this->seedOutputChunk($run, "one\ntwo\n");
        $this->seedOutputChunk($run, "three\n");
        $path = '/projects/'.$project->id.'/worker-runs/'.$run->id.'/output';
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, $path);
        self::assertResponseIsSuccessful();
        self::assertSame(['text' => "one\ntwo\nthree\n", 'length' => 14, 'cut' => false], json_decode((string) $client->getResponse()->getContent(), true));
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));

        $client->request(Request::METHOD_GET, $path.'?from=4');
        self::assertSame(['text' => "two\nthree\n", 'length' => 14, 'cut' => false], json_decode((string) $client->getResponse()->getContent(), true));

        $client->request(Request::METHOD_GET, $path.'?from=14');
        self::assertSame(['text' => '', 'length' => 14, 'cut' => false], json_decode((string) $client->getResponse()->getContent(), true));
    }

    public function test_an_answer_carries_at_most_one_read_step_of_text(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'output-step-owner@example.com');
        $project = $this->project($em, $owner, 'Output step');
        $run = $this->seedRun($em, $project, state: WorkerRunState::Running);
        $this->seedOutputChunk($run, str_repeat('a', WorkerRunOutputLog::MAX_READ_BYTES));
        $this->seedOutputChunk($run, str_repeat('b', 100));
        $path = '/projects/'.$project->id.'/worker-runs/'.$run->id.'/output';
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, $path);
        $first = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame(WorkerRunOutputLog::MAX_READ_BYTES, \strlen($first['text']));
        self::assertSame(WorkerRunOutputLog::MAX_READ_BYTES, $first['length']);

        $client->request(Request::METHOD_GET, $path.'?from='.$first['length']);
        $second = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame(str_repeat('b', 100), $second['text']);
        self::assertSame(WorkerRunOutputLog::MAX_READ_BYTES + 100, $second['length']);
    }

    public function test_a_negative_offset_reads_from_the_start_and_a_broken_one_is_refused(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'output-offset-owner@example.com');
        $project = $this->project($em, $owner, 'Output offset');
        $run = $this->seedRun($em, $project, state: WorkerRunState::Running);
        $this->seedOutputChunk($run, "one\n");
        $path = '/projects/'.$project->id.'/worker-runs/'.$run->id.'/output';
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, $path.'?from=-5');
        self::assertSame("one\n", json_decode((string) $client->getResponse()->getContent(), true)['text']);

        $client->request(Request::METHOD_GET, $path.'?from=abc');
        self::assertResponseStatusCodeSame(400);
    }

    public function test_the_answer_says_when_the_log_hit_the_cap(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'output-cut-owner@example.com');
        $project = $this->project($em, $owner, 'Output cut');
        $run = $this->seedRun($em, $project, state: WorkerRunState::Running);
        $this->seedOutputChunk($run, "end\n", cut: true);
        $path = '/projects/'.$project->id.'/worker-runs/'.$run->id.'/output';
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, $path);

        self::assertTrue(json_decode((string) $client->getResponse()->getContent(), true)['cut']);
    }

    public function test_a_stranger_is_refused(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'output-private-owner@example.com');
        $stranger = $this->user($em, 'output-private-stranger@example.com');
        $project = $this->project($em, $owner, 'Output private');
        $run = $this->seedRun($em, $project, state: WorkerRunState::Running);
        $this->seedOutputChunk($run, "secret\n");
        $path = '/projects/'.$project->id.'/worker-runs/'.$run->id.'/output';
        $em->clear();

        $client->loginUser($stranger);
        $client->request(Request::METHOD_GET, $path);

        self::assertResponseStatusCodeSame(403);
        self::assertStringNotContainsString('secret', (string) $client->getResponse()->getContent());
    }

    public function test_a_run_of_another_project_is_not_found(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'output-other-owner@example.com');
        $project = $this->project($em, $owner, 'Output mine');
        $other = $this->project($em, $owner, 'Output other');
        $run = $this->seedRun($em, $other, state: WorkerRunState::Running);
        $this->seedOutputChunk($run, "text\n");
        $path = '/projects/'.$project->id.'/worker-runs/'.$run->id.'/output';
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, $path);
        self::assertResponseStatusCodeSame(404);

        $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/worker-runs/'.Uuid::v7().'/output');
        self::assertResponseStatusCodeSame(404);
    }

    public function test_the_full_log_is_plain_text_with_a_cut_line(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'log-owner@example.com');
        $project = $this->project($em, $owner, 'Log');
        $run = $this->seedRun($em, $project, state: WorkerRunState::Running);
        $this->seedOutputChunk($run, "one\ntwo");
        $this->seedOutputChunk($run, ' <b>end</b>', cut: true);
        $clean = $this->seedRun($em, $project, state: WorkerRunState::Running);
        $this->seedOutputChunk($clean, "only\n");
        $path = '/projects/'.$project->id.'/worker-runs/'.$run->id.'/log';
        $cleanPath = '/projects/'.$project->id.'/worker-runs/'.$clean->id.'/log';
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, $path);
        self::assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertSame('text/plain; charset=utf-8', $response->headers->get('Content-Type'));
        self::assertSame('inline', $response->headers->get('Content-Disposition'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertSame("one\ntwo <b>end</b>\nLoupe stopped storing the log at 1 MiB\n", $response->getContent());

        $client->request(Request::METHOD_GET, $cleanPath);
        self::assertSame("only\n", $client->getResponse()->getContent());
    }

    public function test_the_full_log_is_refused_to_a_stranger_and_hidden_across_projects(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'log-private-owner@example.com');
        $stranger = $this->user($em, 'log-private-stranger@example.com');
        $project = $this->project($em, $owner, 'Log private');
        $other = $this->project($em, $owner, 'Log other');
        $run = $this->seedRun($em, $project, state: WorkerRunState::Running);
        $this->seedOutputChunk($run, "secret\n");
        $path = '/projects/'.$project->id.'/worker-runs/'.$run->id.'/log';
        $crossPath = '/projects/'.$other->id.'/worker-runs/'.$run->id.'/log';
        $em->clear();

        $client->loginUser($stranger);
        $client->request(Request::METHOD_GET, $path);
        self::assertResponseStatusCodeSame(403);

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, $crossPath);
        self::assertResponseStatusCodeSame(404);
    }
}
