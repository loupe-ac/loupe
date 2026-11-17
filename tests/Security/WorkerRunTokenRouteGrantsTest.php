<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Module\Artifact\Install\ArtifactInstallFlags;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Artifact\ArtifactScenario;
use App\Tests\Module\OAuth\PreviewTokenScenario;
use App\Tests\Support\WorkerRunToken;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

/**
 * What a run token reaches over HTTP. It reaches the two artifact uploads and
 * the read of one card by number, and nothing else the owner's login reaches.
 */
final class WorkerRunTokenRouteGrantsTest extends WebTestCase
{
    use ArtifactScenario;
    use PreviewTokenScenario;

    private KernelBrowser $client;
    private string $runToken;

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->setUpScenario('run-token-grants@example.com');
        $this->connection->executeStatement("UPDATE feature_flag SET value = 'true' WHERE name = ?", [ArtifactInstallFlags::FLAG_ENABLED]);
        $this->runToken = WorkerRunToken::issue(static::getContainer(), $this->owner, $this->project)->token;
    }

    protected function tearDown(): void
    {
        array_map(unlink(...), $this->tempFiles);
        $this->tempFiles = [];
        parent::tearDown();
    }

    public function test_a_run_token_creates_an_artifact(): void
    {
        $this->upload($this->project, '/artifacts', ['index.html' => '<h1>Hi</h1>']);

        self::assertResponseStatusCodeSame(201);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM artifacts WHERE project_id = :id', ['id' => (string) $this->project->id]));
    }

    public function test_a_run_token_stores_a_version_of_an_artifact(): void
    {
        $artifact = $this->storedArtifact($this->project, 'Tokens', [['index.html' => 'v1']]);

        $this->upload($this->project, '/artifacts/'.$artifact->id.'/versions', ['index.html' => 'v2']);

        self::assertResponseStatusCodeSame(201);
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM artifact_versions WHERE artifact_id = :id', ['id' => (string) $artifact->id]));
    }

    public function test_a_run_token_reads_a_card_by_number(): void
    {
        $this->cardOf($this->project, 7);

        $this->request(Request::METHOD_GET, '/api/v1/projects/'.$this->project->id.'/cards/7');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(7, $this->json()['number']);
    }

    public function test_a_run_token_reaches_no_project_but_its_own(): void
    {
        $other = $this->scenario->createProject($this->owner, 'Another site');
        $this->cardOf($other, 3);

        $this->upload($other, '/artifacts', ['index.html' => 'x']);
        self::assertResponseStatusCodeSame(404);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM artifacts WHERE project_id = :id', ['id' => (string) $other->id]));

        $this->request(Request::METHOD_GET, '/api/v1/projects/'.$other->id.'/cards/3');
        self::assertResponseStatusCodeSame(404);
    }

    #[DataProvider('refusedRoutes')]
    public function test_a_run_token_is_refused_on_every_other_route(string $method, string $path): void
    {
        $artifact = $this->storedArtifact($this->project, 'Tokens', [['index.html' => 'v1']]);
        $this->cardOf($this->project, 7);
        $path = str_replace(['{project}', '{artifact}'], [(string) $this->project->id, (string) $artifact->id], $path);

        $this->request($method, $path, '{}');

        self::assertResponseStatusCodeSame(403, $method.' '.$path);
        self::assertSame('credential_not_allowed', $this->json()['code']);
    }

    /** @return iterable<string, array{string, string}> */
    public static function refusedRoutes(): iterable
    {
        yield 'project list' => [Request::METHOD_GET, '/api/v1/projects'];
        yield 'project' => [Request::METHOD_GET, '/api/v1/projects/{project}'];
        yield 'artifact list' => [Request::METHOD_GET, '/api/v1/projects/{project}/artifacts'];
        yield 'artifact' => [Request::METHOD_GET, '/api/v1/projects/{project}/artifacts/{artifact}'];
        yield 'artifact update' => [Request::METHOD_PATCH, '/api/v1/projects/{project}/artifacts/{artifact}'];
        yield 'artifact file' => [Request::METHOD_GET, '/api/v1/projects/{project}/artifacts/{artifact}/files/index.html'];
        yield 'card list' => [Request::METHOD_GET, '/api/v1/projects/{project}/cards'];
        yield 'card create' => [Request::METHOD_POST, '/api/v1/projects/{project}/cards'];
        yield 'card write' => [Request::METHOD_PATCH, '/api/v1/projects/{project}/cards/7'];
        yield 'card events' => [Request::METHOD_GET, '/api/v1/projects/{project}/cards/7/events'];
        yield 'card hold' => [Request::METHOD_PUT, '/api/v1/projects/{project}/cards/7/hold'];
        yield 'documents' => [Request::METHOD_GET, '/api/v1/projects/{project}/documents'];
        yield 'verdict' => [Request::METHOD_POST, '/api/v1/projects/{project}/documents/0198a3c4-0000-7000-8000-000000000001/verdicts'];
        yield 'inbox items' => [Request::METHOD_GET, '/api/v1/projects/{project}/inbox/items'];
        yield 'inbox action' => [Request::METHOD_GET, '/api/v1/projects/{project}/inbox/actions'];
        yield 'runs' => [Request::METHOD_GET, '/api/v1/projects/{project}/runs'];
        yield 'preview token mint' => [Request::METHOD_POST, '/api/v1/bridge/projects/{project}/preview-tokens'];
        yield 'preview token revoke' => [Request::METHOD_DELETE, '/api/v1/bridge/projects/{project}/preview-tokens/pr-12'];
        yield 'run credential mint' => [Request::METHOD_POST, '/api/v1/bridge/projects/{project}/worker-runs/0198a3c4-0000-7000-8000-000000000001/credential'];
        yield 'git token' => [Request::METHOD_POST, '/api/v1/bridge/projects/{project}/git-token'];
        yield 'bridge projects' => [Request::METHOD_GET, '/api/v1/bridge/projects'];
        yield 'bridge run report' => [Request::METHOD_PUT, '/api/v1/bridge/projects/{project}/worker-runs/0198a3c4-0000-7000-8000-000000000001'];
    }

    public function test_a_run_token_still_reaches_the_mcp_endpoint(): void
    {
        $this->client->request(Request::METHOD_POST, '/mcp', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->runToken,
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
        ], content: '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-03-26","capabilities":{},"clientInfo":{"name":"worker","version":"1"}}}');

        self::assertResponseStatusCodeSame(200);
    }

    public function test_a_revoked_run_token_is_refused_everywhere(): void
    {
        $this->cardOf($this->project, 7);
        $this->connection->executeStatement('UPDATE oauth2_access_token SET revoked = true');
        $this->em()->clear();

        $this->request(Request::METHOD_GET, '/api/v1/projects/'.$this->project->id.'/cards/7');

        self::assertResponseStatusCodeSame(401);
    }

    public function test_a_run_token_that_lost_its_record_authenticates_nothing(): void
    {
        $this->cardOf($this->project, 7);
        $this->connection->executeStatement('DELETE FROM oauth_worker_run_credentials');
        $this->em()->clear();

        $this->request(Request::METHOD_GET, '/api/v1/projects/'.$this->project->id.'/cards/7');

        self::assertResponseStatusCodeSame(401);
    }

    private function request(string $method, string $path, string $content = ''): void
    {
        $this->client->request($method, $path, server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->runToken,
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], content: $content);
    }

    /** @param array<string, string> $contentsByPath */
    private function upload(Project $project, string $suffix, array $contentsByPath): void
    {
        $files = [];
        foreach ($contentsByPath as $path => $contents) {
            $temp = tempnam(sys_get_temp_dir(), 'artifact');
            self::assertNotFalse($temp);
            $this->tempFiles[] = $temp;
            file_put_contents($temp, $contents);
            $files[] = new UploadedFile($temp, basename($path), 'text/html', null, true);
        }

        $fields = '/artifacts' === $suffix ? ['name' => 'From a worker'] : [];
        $this->client->request(
            Request::METHOD_POST,
            '/api/v1/projects/'.$project->id.$suffix,
            $fields + ['paths' => array_map(strval(...), array_keys($contentsByPath))],
            ['files' => $files],
            ['HTTP_AUTHORIZATION' => 'Bearer '.$this->runToken],
        );
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
