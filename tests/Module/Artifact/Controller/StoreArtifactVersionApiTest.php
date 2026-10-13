<?php

declare(strict_types=1);

namespace App\Tests\Module\Artifact\Controller;

use App\Module\Artifact\Entity\Artifact;
use App\Module\Artifact\Entity\ArtifactVersion;
use App\Module\Artifact\Repository\ArtifactRepository;
use App\Module\Artifact\Repository\ArtifactVersionRepository;
use App\Module\Board\Entity\Card;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\ValueObject\WorkerRunKind;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Artifact\ArtifactScenario;
use App\Tests\Module\Board\BoardColumnFixtures;
use App\Tests\Support\AgentCredential;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Uid\Uuid;

final class StoreArtifactVersionApiTest extends WebTestCase
{
    use ArtifactScenario;
    use BoardColumnFixtures;

    /** @var list<string> */
    private array $tempFiles = [];

    #[\Override]
    protected function tearDown(): void
    {
        array_map(unlink(...), $this->tempFiles);
        $this->tempFiles = [];
        parent::tearDown();
    }

    public function test_it_stores_a_new_artifact_and_returns_its_paths(): void
    {
        $client = static::createClient();
        $project = $this->switchedOnProject('route-ok@example.com');
        $token = $this->token($project);

        $this->post($client, $project, $token, ['name' => 'Widget tokens', 'notes' => ' First draft. ', 'paths' => ['index.html', 'css/app.css']], [
            'index.html' => '<h1>Hi</h1>',
            'css/app.css' => 'body{}',
        ]);

        self::assertResponseStatusCodeSame(201);
        $body = $this->json($client);
        $stable = \sprintf('/projects/%s/artifacts/%s', $project->id, $body['artifactId']);
        self::assertSame(1, $body['versionNumber']);
        self::assertSame($stable, $body['path']);
        self::assertSame($stable.'/versions/1', $body['versionPath']);

        $artifact = $this->artifactRepository()->find($body['artifactId']);
        self::assertInstanceOf(Artifact::class, $artifact);
        self::assertSame('Widget tokens', $artifact->name);
        $contents = [];
        $version = $this->versionRepository()->findByNumber($artifact, 1);
        self::assertInstanceOf(ArtifactVersion::class, $version);
        foreach ($version->files as $file) {
            $contents[$file->path] = $file->blob->contents();
        }
        self::assertEquals(['css/app.css' => 'body{}', 'index.html' => '<h1>Hi</h1>'], $contents);
        self::assertSame('First draft.', $version->notes);
    }

    public function test_it_adds_a_version_to_an_artifact_and_keeps_the_session_id(): void
    {
        $client = static::createClient();
        $project = $this->switchedOnProject('route-version@example.com');
        $token = $this->token($project);
        $this->post($client, $project, $token, ['name' => 'Widget tokens', 'paths' => ['index.html']], ['index.html' => 'v1']);
        $artifactId = $this->json($client)['artifactId'];
        $session = (string) Uuid::v4();

        $this->post($client, $project, $token, ['artifactId' => $artifactId, 'notes' => '   ', 'paths' => ['index.html']], ['index.html' => 'v2'], $session);

        self::assertResponseStatusCodeSame(201);
        self::assertSame(2, $this->json($client)['versionNumber']);
        $artifact = $this->artifactRepository()->find($artifactId);
        self::assertInstanceOf(Artifact::class, $artifact);
        $latest = $this->versionRepository()->findLatest($artifact);
        self::assertInstanceOf(ArtifactVersion::class, $latest);
        self::assertSame($session, $latest->storedBySession);
        self::assertNull($latest->notes);
    }

    public function test_it_refuses_notes_over_2000_characters_and_stores_nothing(): void
    {
        $client = static::createClient();
        $project = $this->switchedOnProject('route-notes@example.com');
        $token = $this->token($project);

        $this->post($client, $project, $token, ['name' => 'X', 'notes' => str_repeat('a', 2001), 'paths' => ['a.html']], ['a.html' => 'a']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->artifactRepository()->count([]));
    }

    public function test_a_request_with_no_token_is_refused(): void
    {
        $client = static::createClient();
        $project = $this->switchedOnProject('route-anon@example.com');

        $this->post($client, $project, null, ['name' => 'X', 'paths' => ['a.html']], ['a.html' => 'a']);

        self::assertResponseStatusCodeSame(401);
        self::assertSame(0, $this->artifactRepository()->count([]));
    }

    public function test_a_token_without_the_agent_scope_is_refused(): void
    {
        $client = static::createClient();
        $project = $this->switchedOnProject('route-scope@example.com');
        $token = AgentCredential::tokenFor(static::getContainer(), $project->owner, 'mcp', $project);

        $this->post($client, $project, $token, ['name' => 'X', 'paths' => ['a.html']], ['a.html' => 'a']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->artifactRepository()->count([]));
    }

    public function test_a_project_of_another_owner_is_not_found(): void
    {
        $client = static::createClient();
        $project = $this->switchedOnProject('route-own@example.com');
        $stranger = $this->project($this->em(), 'route-stranger@example.com', 'Stranger');
        $token = $this->token($stranger);

        $this->post($client, $project, $token, ['name' => 'X', 'paths' => ['a.html']], ['a.html' => 'a']);

        self::assertResponseStatusCodeSame(404);
        self::assertSame(0, $this->artifactRepository()->count([]));
    }

    public function test_it_refuses_while_the_flag_is_off(): void
    {
        $client = static::createClient();
        $project = $this->project($this->em(), 'route-off@example.com', 'Artifacts');
        $token = $this->token($project);

        $this->post($client, $project, $token, ['name' => 'X', 'paths' => ['a.html']], ['a.html' => 'a']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('Artifacts are switched off', $this->json($client)['errors']['artifacts']);
        self::assertSame(0, $this->artifactRepository()->count([]));
    }

    public function test_it_refuses_a_store_from_a_worker_session(): void
    {
        $client = static::createClient();
        $project = $this->switchedOnProject('route-worker@example.com');
        $token = $this->token($project);
        $session = Uuid::v4();
        $em = $this->em();
        $em->persist(new WorkerRun(
            project: $project,
            bridgeId: Uuid::v4(),
            subjectType: WorkSubject::CARD,
            subjectId: Uuid::v4(),
            cardNumber: 1,
            workKind: 'implement',
            state: WorkerRunState::Running,
            sessionId: $session,
            receivedAt: new \DateTimeImmutable(),
            kind: WorkerRunKind::Worker,
        ));
        $em->flush();

        $this->post($client, $project, $token, ['name' => 'X', 'paths' => ['a.html']], ['a.html' => 'a'], (string) $session);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(
            'Workers read artifacts and do not store them. Store a new version from an interactive session.',
            $this->json($client)['errors']['session'],
        );
        self::assertSame(0, $this->artifactRepository()->count([]));
    }

    public function test_it_refuses_an_unsafe_path_with_a_message(): void
    {
        $client = static::createClient();
        $project = $this->switchedOnProject('route-path@example.com');
        $token = $this->token($project);

        $this->post($client, $project, $token, ['name' => 'X', 'paths' => ['../a.html']], ['a.html' => 'a']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('artifact_refused', $this->json($client)['error']);
        self::assertArrayHasKey('files', $this->json($client)['errors']);
    }

    public function test_it_refuses_a_claude_design_canvas_and_names_the_cli_that_compiles_it(): void
    {
        $client = static::createClient();
        $project = $this->switchedOnProject('route-canvas@example.com');
        $token = $this->token($project);

        $this->post($client, $project, $token, ['name' => 'X', 'paths' => ['Main.dc.html', 'support.js']], ['Main.dc.html' => '<x-dc></x-dc>', 'support.js' => 'x']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('artifact_refused', $this->json($client)['error']);
        self::assertSame(
            'A .dc.html file is a Claude Design canvas, and it does not render outside Claude Design. Push it with loupe artifact push from CLI 1.17.0 or later, which compiles the canvas to plain HTML.',
            $this->json($client)['errors']['files'],
        );
        self::assertSame(0, $this->artifactRepository()->count([]));
    }

    public function test_it_refuses_a_request_whose_paths_do_not_match_its_files(): void
    {
        $client = static::createClient();
        $project = $this->switchedOnProject('route-mismatch@example.com');
        $token = $this->token($project);

        $this->post($client, $project, $token, ['name' => 'X', 'paths' => ['a.html', 'b.html']], ['a.html' => 'a']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->artifactRepository()->count([]));
    }

    public function test_it_refuses_a_request_with_no_files(): void
    {
        $client = static::createClient();
        $project = $this->switchedOnProject('route-nofiles@example.com');
        $token = $this->token($project);

        $this->post($client, $project, $token, ['name' => 'X'], []);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->artifactRepository()->count([]));
    }

    public function test_the_store_is_rate_limited(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        static::getContainer()->set('limiter.agent_artifact_store', new RateLimiterFactory(
            ['id' => 'agent_artifact_store', 'policy' => 'sliding_window', 'limit' => 1, 'interval' => '1 minute'],
            new InMemoryStorage(),
        ));
        $project = $this->switchedOnProject('route-limit@example.com');
        $token = $this->token($project);

        $this->post($client, $project, $token, ['name' => 'One', 'paths' => ['a.html']], ['a.html' => 'a']);
        self::assertResponseStatusCodeSame(201);

        $this->post($client, $project, $token, ['name' => 'Two', 'paths' => ['a.html']], ['a.html' => 'a']);
        self::assertResponseStatusCodeSame(429);
        self::assertSame(1, $this->artifactRepository()->count([]));
    }

    public function test_it_links_the_artifact_to_the_card_it_names_and_a_second_push_adds_no_link(): void
    {
        $client = static::createClient();
        $project = $this->switchedOnProject('route-card@example.com');
        $card = $this->card($project);
        $token = $this->token($project);

        $this->post($client, $project, $token, ['name' => 'Widget tokens', 'card' => (string) $card->number, 'paths' => ['a.html']], ['a.html' => 'v1']);
        self::assertResponseStatusCodeSame(201);
        $artifactId = $this->json($client)['artifactId'];
        $this->post($client, $project, $token, ['artifactId' => $artifactId, 'card' => (string) $card->id, 'paths' => ['a.html']], ['a.html' => 'v2']);
        self::assertResponseStatusCodeSame(201);

        $rows = $this->em()->getConnection()->fetchAllAssociative('SELECT card_id, artifact_id FROM board_card_artifacts');
        self::assertSame([['card_id' => (string) $card->id, 'artifact_id' => $artifactId]], $rows);
    }

    public function test_it_refuses_a_card_that_is_not_in_the_project_and_stores_nothing(): void
    {
        $client = static::createClient();
        $project = $this->switchedOnProject('route-card-refused@example.com');
        $token = $this->token($project);

        $this->post($client, $project, $token, ['name' => 'X', 'card' => '99', 'paths' => ['a.html']], ['a.html' => 'a']);

        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey('card', $this->json($client)['errors']);
        self::assertSame(0, $this->artifactRepository()->count([]));
    }

    private function card(Project $project): Card
    {
        $this->seedColumns($project);
        $card = new Card($project, $this->column($project, 'backlog'), 'Prototype', 'Body', 1);
        $this->em()->persist($card);
        $this->em()->flush();

        return $card;
    }

    private function token(Project $project): string
    {
        return AgentCredential::agentToken(static::getContainer(), $project->owner);
    }

    /**
     * @param array<string, mixed>  $fields
     * @param array<string, string> $contentsByName file contents; the key only names the temp file
     */
    private function post(KernelBrowser $client, Project $project, ?string $token, array $fields, array $contentsByName, ?string $session = null): void
    {
        $files = [];
        foreach ($contentsByName as $name => $contents) {
            $temp = tempnam(sys_get_temp_dir(), 'artifact');
            self::assertNotFalse($temp);
            $this->tempFiles[] = $temp;
            file_put_contents($temp, $contents);
            $files[] = new UploadedFile($temp, basename($name), 'text/plain', null, true);
        }

        $server = ['HTTP_ACCEPT' => 'application/json'];
        if (null !== $token) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
        }
        if (null !== $session) {
            $server['HTTP_X_LOUPE_SESSION'] = $session;
        }

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, \sprintf('/api/projects/%s/artifacts', $project->id), $fields, ['files' => $files], $server);
    }

    /** @return array<string, mixed> */
    private function json(KernelBrowser $client): array
    {
        $decoded = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function artifactRepository(): ArtifactRepository
    {
        return static::getContainer()->get(ArtifactRepository::class);
    }

    private function versionRepository(): ArtifactVersionRepository
    {
        return static::getContainer()->get(ArtifactVersionRepository::class);
    }
}
