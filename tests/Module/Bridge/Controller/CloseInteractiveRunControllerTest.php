<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Controller;

use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\ValueObject\WorkerRunKind;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Tests\Module\Bridge\BridgeScenario;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

/** A person closes an interactive session from its card, the Runs list or the run drawer. */
final class CloseInteractiveRunControllerTest extends WebTestCase
{
    use BridgeScenario;

    public function test_the_owner_closes_an_open_interactive_run(): void
    {
        $client = static::createClient();
        $em = $this->em();

        $owner = $this->user($em, 'close-run-owner@example.com');
        $project = $this->project($em, $owner, 'Close run');
        $cardId = Uuid::v7();
        $run = $this->seedRun($em, $project, workKind: 'loupe:product-design', cardId: $cardId, state: WorkerRunState::Running, kind: WorkerRunKind::Interactive);
        $url = $this->closeUrl((string) $project->id, (string) $run->id);
        $runId = $run->id;
        $em->clear();

        $client->loginUser($owner);
        $this->post($client, $url, 'card-worker-runs');

        self::assertResponseRedirects('/projects/'.$project->id.'/worker-runs/card/'.$cardId);
        $closed = $em->find(WorkerRun::class, $runId);
        self::assertNotNull($closed);
        self::assertSame(WorkerRunState::Closed, $closed->state);
        self::assertNotNull($closed->endedAt);
    }

    public function test_a_close_from_the_runs_list_returns_to_the_same_view(): void
    {
        $client = static::createClient();
        $em = $this->em();

        $owner = $this->user($em, 'close-run-list@example.com');
        $project = $this->project($em, $owner, 'Close run list');
        $run = $this->seedRun($em, $project, state: WorkerRunState::Running, kind: WorkerRunKind::Interactive);
        $url = $this->closeUrl((string) $project->id, (string) $run->id).'?outcome=open&search=design';
        $runId = $run->id;
        $em->clear();

        $client->loginUser($owner);
        $this->post($client, $url, 'worker-runs-frame');

        self::assertResponseRedirects('/projects/'.$project->id.'/worker-runs?page=1&search=design&outcome=open');
        self::assertSame(WorkerRunState::Closed, $em->find(WorkerRun::class, $runId)?->state);
    }

    public function test_a_close_from_the_drawer_reloads_the_list_on_that_run(): void
    {
        $client = static::createClient();
        $em = $this->em();

        $owner = $this->user($em, 'close-run-drawer@example.com');
        $project = $this->project($em, $owner, 'Close run drawer');
        $run = $this->seedRun($em, $project, state: WorkerRunState::Running, kind: WorkerRunKind::Interactive);
        $url = $this->closeUrl((string) $project->id, (string) $run->id);
        $runId = (string) $run->id;
        $em->clear();

        $client->loginUser($owner);
        $this->post($client, $url);

        self::assertResponseRedirects('/projects/'.$project->id.'/worker-runs?search='.$runId);
    }

    public function test_a_request_without_a_valid_csrf_token_is_refused(): void
    {
        $client = static::createClient();
        $em = $this->em();

        $owner = $this->user($em, 'close-run-csrf@example.com');
        $project = $this->project($em, $owner, 'Close run csrf');
        $run = $this->seedRun($em, $project, state: WorkerRunState::Running, kind: WorkerRunKind::Interactive);
        $url = $this->closeUrl((string) $project->id, (string) $run->id);
        $runId = $run->id;
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_POST, $url, ['_csrf_token' => 'forged']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(WorkerRunState::Running, $em->find(WorkerRun::class, $runId)?->state);
    }

    public function test_a_user_who_cannot_edit_the_project_is_refused(): void
    {
        $client = static::createClient();
        $em = $this->em();

        $owner = $this->user($em, 'close-run-theirs@example.com');
        $stranger = $this->user($em, 'close-run-stranger@example.com');
        $project = $this->project($em, $owner, 'Close run private');
        $run = $this->seedRun($em, $project, state: WorkerRunState::Running, kind: WorkerRunKind::Interactive);
        $url = $this->closeUrl((string) $project->id, (string) $run->id);
        $runId = $run->id;
        $em->clear();

        $client->loginUser($stranger);
        $this->post($client, $url);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(WorkerRunState::Running, $em->find(WorkerRun::class, $runId)?->state);
    }

    public function test_a_worker_run_is_not_found(): void
    {
        $client = static::createClient();
        $em = $this->em();

        $owner = $this->user($em, 'close-run-worker@example.com');
        $project = $this->project($em, $owner, 'Close run worker');
        $run = $this->seedRun($em, $project, state: WorkerRunState::Running, runKey: Uuid::v7());
        $url = $this->closeUrl((string) $project->id, (string) $run->id);
        $runId = $run->id;
        $em->clear();

        $client->loginUser($owner);
        $this->post($client, $url);

        self::assertResponseStatusCodeSame(404);
        self::assertSame(WorkerRunState::Running, $em->find(WorkerRun::class, $runId)?->state);
    }

    public function test_a_run_of_another_project_is_not_found(): void
    {
        $client = static::createClient();
        $em = $this->em();

        $owner = $this->user($em, 'close-run-cross@example.com');
        $project = $this->project($em, $owner, 'Close run mine');
        $other = $this->project($em, $owner, 'Close run other');
        $run = $this->seedRun($em, $other, state: WorkerRunState::Running, kind: WorkerRunKind::Interactive);
        $url = $this->closeUrl((string) $project->id, (string) $run->id);
        $runId = $run->id;
        $em->clear();

        $client->loginUser($owner);
        $this->post($client, $url);

        self::assertResponseStatusCodeSame(404);
        self::assertSame(WorkerRunState::Running, $em->find(WorkerRun::class, $runId)?->state);
    }

    private function closeUrl(string $projectId, string $runId): string
    {
        return '/projects/'.$projectId.'/worker-runs/'.$runId.'/close';
    }

    /** The same-origin sentinel passes the CSRF check, so a refusal is the voter's or the lookup's. */
    private function post(KernelBrowser $client, string $url, ?string $frame = null): void
    {
        $server = ['HTTP_REFERER' => 'http://localhost'.$url];
        if (null !== $frame) {
            $server['HTTP_TURBO_FRAME'] = $frame;
        }
        $client->request(Request::METHOD_POST, $url, ['_csrf_token' => 'csrf-token'], [], $server);
    }
}
