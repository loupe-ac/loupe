<?php

declare(strict_types=1);

namespace App\Tests\Module\Artifact\Api;

use App\Module\Account\Entity\User;
use App\Module\Artifact\EventListener\RefuseArtifactApiWhenSwitchedOff;
use App\Module\Artifact\Install\ArtifactInstallFlags;
use App\Module\Artifact\Repository\ArtifactRepository;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Artifact\ArtifactScenario;
use App\Tests\Support\AgentCredential;
use App\Tests\Support\ApiKeyCredential;
use App\Tests\Support\OAuthScenario;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Uid\Uuid;

/** The artifact routes of the public API under /api/v1/projects/{project}/artifacts. */
final class ArtifactApiTest extends WebTestCase
{
    use ArtifactScenario;

    private KernelBrowser $client;
    private OAuthScenario $scenario;
    private User $owner;
    private Project $project;
    private string $token;

    /** @var list<string> */
    private array $tempFiles = [];

    #[\Override]
    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->scenario = new OAuthScenario(static::getContainer());
        $this->owner = $this->scenario->createUser('artifact-api-'.uniqid().'@example.com');
        $this->project = $this->scenario->createProject($this->owner, 'Artifact shop');
        $this->switchFlag(true);
        $this->token = ApiKeyCredential::mint(static::getContainer(), $this->owner);
    }

    #[\Override]
    protected function tearDown(): void
    {
        array_map(unlink(...), $this->tempFiles);
        $this->tempFiles = [];
        parent::tearDown();
    }

    public function test_the_list_holds_the_live_artifacts_oldest_first(): void
    {
        $first = $this->storedArtifact($this->project, 'First', [['index.html' => 'a'], ['index.html' => 'b']]);
        $second = $this->storedArtifact($this->project, 'Second', [['index.html' => 'c']]);
        $this->storedArtifact($this->project, 'Gone', [['index.html' => 'd']])->archivedAt = new \DateTimeImmutable();
        $this->em()->flush();
        $this->setCreatedAt($first, '2026-01-01 10:00:00');
        $this->setCreatedAt($second, '2026-01-02 10:00:00');

        $this->send(Request::METHOD_GET, $this->base());

        self::assertResponseStatusCodeSame(200);
        $body = $this->json();
        self::assertSame(['First', 'Second'], array_column($body['items'], 'name'));
        self::assertSame(['artifactId', 'name', 'latestVersion', 'archived', 'sourceLink', 'createdAt', 'path'], array_keys($body['items'][0]));
        self::assertSame(2, $body['items'][0]['latestVersion']);
        self::assertSame(1, $body['items'][1]['latestVersion']);
        self::assertSame(\sprintf('/projects/%s/artifacts/%s', $this->project->id, $first->id), $body['items'][0]['path']);
        self::assertNull($body['next']);
        self::assertTrue($this->client->getResponse()->headers->has('RateLimit-Limit'));
    }

    public function test_archived_artifacts_come_back_on_request_and_a_bad_flag_is_refused(): void
    {
        $this->storedArtifact($this->project, 'Live', [['index.html' => 'a']]);
        $this->storedArtifact($this->project, 'Gone', [['index.html' => 'd']])->archivedAt = new \DateTimeImmutable();
        $this->em()->flush();

        $this->send(Request::METHOD_GET, $this->base().'?includeArchived=true');
        self::assertCount(2, $this->json()['items']);

        $this->send(Request::METHOD_GET, $this->base().'?includeArchived=maybe');
        self::assertResponseStatusCodeSame(422);
        self::assertSame('include_archived_invalid', $this->json()['errors'][0]['code']);
    }

    public function test_a_page_walks_the_list_with_a_cursor_that_remembers_the_archive_filter(): void
    {
        $artifacts = [];
        foreach ([1, 2, 3] as $day) {
            $artifacts[$day] = $this->storedArtifact($this->project, 'Artifact '.$day, [['index.html' => 'x']]);
        }
        $ids = [];
        foreach ($artifacts as $day => $artifact) {
            $this->setCreatedAt($artifact, '2026-01-0'.$day.' 10:00:00');
            $ids[] = (string) $artifact->id;
        }

        $this->send(Request::METHOD_GET, $this->base().'?limit=2');
        $first = $this->json();
        self::assertSame([$ids[0], $ids[1]], array_column($first['items'], 'artifactId'));
        self::assertIsString($first['next']);

        $this->send(Request::METHOD_GET, $first['next']);
        self::assertSame([$ids[2]], array_column($this->json()['items'], 'artifactId'));
        self::assertNull($this->json()['next']);

        $this->send(Request::METHOD_GET, $first['next'].'&includeArchived=true');
        self::assertResponseStatusCodeSame(400);
        self::assertSame('invalid_cursor', $this->json()['code']);

        $this->send(Request::METHOD_GET, $this->base().'?limit=2&sort=-createdAt');
        $newest = $this->json();
        self::assertSame([$ids[2], $ids[1]], array_column($newest['items'], 'artifactId'));
        $this->send(Request::METHOD_GET, $newest['next']);
        self::assertSame([$ids[0]], array_column($this->json()['items'], 'artifactId'));

        $this->send(Request::METHOD_GET, $this->base().'?cursor=nonsense');
        self::assertResponseStatusCodeSame(400);
        self::assertSame('invalid_cursor', $this->json()['code']);
    }

    public function test_one_artifact_is_described_with_the_files_of_its_latest_version(): void
    {
        $artifact = $this->storedArtifact($this->project, 'Tokens', [['index.html' => 'v1'], ['index.html' => 'v2!', 'css/app.css' => 'body{}']], sourceLink: 'https://claude.ai/x');

        $this->send(Request::METHOD_GET, $this->base().'/'.$artifact->id);

        self::assertResponseStatusCodeSame(200);
        $body = $this->json();
        self::assertSame((string) $artifact->id, $body['artifactId']);
        self::assertSame('https://claude.ai/x', $body['sourceLink']);
        self::assertFalse($body['archived']);
        self::assertNull($body['archivedAt']);
        self::assertSame([1, 2], array_column($body['versions'], 'number'));
        self::assertSame(2, $body['selectedVersion']);
        self::assertSame(['css/app.css', 'index.html'], array_column($body['files'], 'path'));
        self::assertSame(['path', 'contentType', 'size'], array_keys($body['files'][0]));
        self::assertIsString($body['pageLink']);
    }

    public function test_a_version_number_picks_the_files_of_that_version(): void
    {
        $artifact = $this->storedArtifact($this->project, 'Tokens', [['index.html' => 'v1'], ['index.html' => 'v2', 'extra.txt' => 'x']]);

        $this->send(Request::METHOD_GET, $this->base().'/'.$artifact->id.'?versionNumber=1');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(1, $this->json()['selectedVersion']);
        self::assertSame(['index.html'], array_column($this->json()['files'], 'path'));

        $this->send(Request::METHOD_GET, $this->base().'/'.$artifact->id.'?versionNumber=9');
        self::assertResponseStatusCodeSame(404);
        self::assertSame('artifact_version_not_found', $this->json()['code']);

        foreach (['0', 'x', '-1', '1.5'] as $bad) {
            $this->send(Request::METHOD_GET, $this->base().'/'.$artifact->id.'?versionNumber='.$bad);
            self::assertResponseStatusCodeSame(422, $bad);
            self::assertSame('version_number_invalid', $this->json()['errors'][0]['code'], $bad);
        }
    }

    public function test_an_unknown_foreign_or_malformed_artifact_is_artifact_not_found(): void
    {
        $other = $this->scenario->createProject($this->owner, 'Other shop');
        $foreign = $this->storedArtifact($other, 'Foreign', [['index.html' => 'x']]);

        foreach ([(string) $foreign->id, (string) Uuid::v7(), 'nonsense'] as $id) {
            $this->send(Request::METHOD_GET, $this->base().'/'.$id);
            self::assertResponseStatusCodeSame(404, $id);
            self::assertSame('artifact_not_found', $this->json()['code'], $id);

            $this->send(Request::METHOD_PATCH, $this->base().'/'.$id, '{"archived":true}');
            self::assertResponseStatusCodeSame(404, $id);
            self::assertSame('artifact_not_found', $this->json()['code'], $id);
        }
        self::assertNull($this->stored($foreign)->archivedAt);
    }

    public function test_a_patch_archives_and_restores_and_a_second_write_changes_nothing(): void
    {
        $artifact = $this->storedArtifact($this->project, 'Tokens', [['index.html' => 'v1']]);

        $this->send(Request::METHOD_PATCH, $this->base().'/'.$artifact->id, '{"archived":true}');
        self::assertResponseStatusCodeSame(200);
        self::assertTrue($this->json()['archived']);
        self::assertIsString($this->json()['archivedAt']);
        $stamp = $this->json()['archivedAt'];
        self::assertNotNull($this->stored($artifact)->archivedAt);

        $this->send(Request::METHOD_PATCH, $this->base().'/'.$artifact->id, '{"archived":true}');
        self::assertResponseStatusCodeSame(200);
        self::assertSame($stamp, $this->json()['archivedAt']);

        $this->send(Request::METHOD_PATCH, $this->base().'/'.$artifact->id, '{"archived":false}');
        self::assertResponseStatusCodeSame(200);
        self::assertFalse($this->json()['archived']);
        self::assertNull($this->json()['archivedAt']);
        self::assertNull($this->stored($artifact)->archivedAt);

        $this->send(Request::METHOD_PATCH, $this->base().'/'.$artifact->id, '{"archived":false}');
        self::assertResponseStatusCodeSame(200);
        self::assertFalse($this->json()['archived']);
    }

    public function test_a_patch_without_a_boolean_is_refused_and_changes_nothing(): void
    {
        $artifact = $this->storedArtifact($this->project, 'Tokens', [['index.html' => 'v1']]);

        foreach (['{}', '{"archived":null}', '{"archived":"yes"}', '{"archived":[]}'] as $body) {
            $this->send(Request::METHOD_PATCH, $this->base().'/'.$artifact->id, $body);
            self::assertResponseStatusCodeSame(422, $body);
            self::assertSame('validation_failed', $this->json()['code'], $body);
        }
        $this->send(Request::METHOD_PATCH, $this->base().'/'.$artifact->id, '{not json');
        self::assertResponseStatusCodeSame(400);
        self::assertSame('invalid_json', $this->json()['code']);
        self::assertNull($this->stored($artifact)->archivedAt);
    }

    public function test_a_file_is_served_with_its_stored_type_as_an_attachment_a_page_cannot_run(): void
    {
        $artifact = $this->storedArtifact($this->project, 'Tokens', [['index.html' => '<script>alert(1)</script>']]);

        $this->send(Request::METHOD_GET, $this->base().'/'.$artifact->id.'/files/index.html');

        self::assertResponseStatusCodeSame(200);
        $response = $this->client->getResponse();
        self::assertSame('<script>alert(1)</script>', $response->getContent());
        self::assertStringStartsWith('text/html', (string) $response->headers->get('Content-Type'));
        self::assertSame('attachment; filename=index.html', $response->headers->get('Content-Disposition'));
        self::assertSame("sandbox; default-src 'none'", $response->headers->get('Content-Security-Policy'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        self::assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        self::assertTrue($response->headers->has('RateLimit-Limit'));
    }

    public function test_a_nested_path_is_read_with_an_encoded_or_a_raw_slash_and_a_name_with_odd_characters_stays_safe(): void
    {
        $artifact = $this->storedArtifact($this->project, 'Tokens', [['index.html' => 'x', 'css/app.css' => 'body{}', 'css/"q";é.css' => 'odd']]);

        foreach (['css%2Fapp.css', 'css/app.css'] as $path) {
            $this->send(Request::METHOD_GET, $this->base().'/'.$artifact->id.'/files/'.$path);
            self::assertResponseStatusCodeSame(200, $path);
            self::assertSame('body{}', $this->client->getResponse()->getContent(), $path);
            self::assertSame('attachment; filename=app.css', $this->client->getResponse()->headers->get('Content-Disposition'), $path);
        }

        $this->send(Request::METHOD_GET, $this->base().'/'.$artifact->id.'/files/'.rawurlencode('css/"q";é.css'));
        self::assertResponseStatusCodeSame(200);
        $disposition = (string) $this->client->getResponse()->headers->get('Content-Disposition');
        self::assertStringStartsWith('attachment;', $disposition);
        self::assertStringNotContainsString("\n", $disposition);
        self::assertStringContainsString("filename*=utf-8''", $disposition);
    }

    public function test_a_file_of_an_older_version_and_the_missing_cases(): void
    {
        $artifact = $this->storedArtifact($this->project, 'Tokens', [['index.html' => 'v1', 'old.txt' => 'old'], ['index.html' => 'v2']]);

        $this->send(Request::METHOD_GET, $this->base().'/'.$artifact->id.'/files/old.txt?versionNumber=1');
        self::assertResponseStatusCodeSame(200);
        self::assertSame('old', $this->client->getResponse()->getContent());

        $this->send(Request::METHOD_GET, $this->base().'/'.$artifact->id.'/files/old.txt');
        self::assertResponseStatusCodeSame(404);
        self::assertSame('artifact_file_not_found', $this->json()['code']);

        $this->send(Request::METHOD_GET, $this->base().'/'.$artifact->id.'/files/index.html?versionNumber=7');
        self::assertResponseStatusCodeSame(404);
        self::assertSame('artifact_version_not_found', $this->json()['code']);

        $this->send(Request::METHOD_GET, $this->base().'/'.$artifact->id.'/files/index.html?versionNumber=0');
        self::assertResponseStatusCodeSame(422);
    }

    public function test_a_multipart_request_creates_an_artifact_and_names_it_in_location(): void
    {
        $this->upload($this->base(), ['name' => 'Widget tokens', 'notes' => ' First draft. ', 'sourceLink' => 'https://claude.ai/x'], ['index.html' => '<h1>Hi</h1>', 'css/app.css' => 'body{}']);

        self::assertResponseStatusCodeSame(201);
        $body = $this->json();
        $stable = \sprintf('/projects/%s/artifacts/%s', $this->project->id, $body['artifactId']);
        self::assertSame(1, $body['versionNumber']);
        self::assertSame($stable, $body['path']);
        self::assertSame($stable.'/versions/1', $body['versionPath']);
        self::assertStringEndsWith($this->base().'/'.$body['artifactId'], (string) $this->client->getResponse()->headers->get('Location'));
        self::assertTrue($this->client->getResponse()->headers->has('RateLimit-Limit'));

        $artifact = $this->stored($body['artifactId']);
        self::assertSame('Widget tokens', $artifact->name);
        self::assertSame('https://claude.ai/x', $artifact->sourceLink);
        $version = $this->versionNumbered($artifact, 1);
        self::assertSame('First draft.', $version->notes);
        self::assertNull($version->storedBySession);
        $contents = [];
        foreach ($version->files as $file) {
            $contents[$file->path] = $file->blob->contents();
        }
        self::assertEquals(['css/app.css' => 'body{}', 'index.html' => '<h1>Hi</h1>'], $contents);
    }

    public function test_a_create_with_a_bad_part_is_refused_with_the_code_of_the_part_and_stores_nothing(): void
    {
        $card = Uuid::v7();
        $cases = [
            'no files' => [['name' => 'X'], [], 'no_files', 'files'],
            'blank name' => [['name' => '  '], ['a.html' => 'a'], 'name_blank', 'name'],
            'long name' => [['name' => str_repeat('n', 256)], ['a.html' => 'a'], 'name_too_long', 'name'],
            'unsafe path' => [['name' => 'X'], ['../a.html' => 'a'], 'unsafe_path', 'files'],
            'design canvas' => [['name' => 'X'], ['a.dc.html' => 'a'], 'claude_design_canvas', 'files'],
            'unknown card' => [['name' => 'X', 'card' => (string) $card], ['a.html' => 'a'], 'card_not_found', 'card'],
            'card that is no id' => [['name' => 'X', 'card' => '12'], ['a.html' => 'a'], 'card_not_found', 'card'],
        ];

        foreach ($cases as $label => [$fields, $files, $code, $field]) {
            $this->upload($this->base(), $fields, $files);
            self::assertResponseStatusCodeSame(422, $label);
            $body = $this->json();
            self::assertSame('validation_failed', $body['code'], $label);
            self::assertSame($code, $body['errors'][0]['code'], $label);
            self::assertSame($field, $body['errors'][0]['field'], $label);
            self::assertNotSame('', $body['errors'][0]['message'], $label);
        }
        self::assertSame(0, $this->artifacts()->count([]));
    }

    public function test_a_create_with_paths_that_do_not_pair_with_the_files_is_paths_mismatch(): void
    {
        $this->upload($this->base(), ['name' => 'X'], ['a.html' => 'a', 'b.html' => 'b'], paths: ['a.html']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('paths_mismatch', $this->json()['errors'][0]['code']);
        self::assertSame('paths', $this->json()['errors'][0]['field']);
        self::assertSame(0, $this->artifacts()->count([]));
    }

    public function test_a_create_with_over_long_notes_or_source_link_is_refused(): void
    {
        $this->upload($this->base(), ['name' => 'X', 'notes' => str_repeat('a', 2001)], ['a.html' => 'a']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('notes', $this->json()['errors'][0]['field']);

        $this->upload($this->base(), ['name' => 'X', 'sourceLink' => str_repeat('a', 2001)], ['a.html' => 'a']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('sourceLink', $this->json()['errors'][0]['field']);
        self::assertSame(0, $this->artifacts()->count([]));
    }

    public function test_a_json_body_on_an_upload_route_is_415_and_names_multipart(): void
    {
        $this->send(Request::METHOD_POST, $this->base(), '{"name":"X"}');

        self::assertResponseStatusCodeSame(415);
        self::assertSame('unsupported_media_type', $this->json()['code']);
        self::assertSame('The body must be multipart/form-data.', $this->json()['detail']);
    }

    public function test_a_version_is_stored_on_an_artifact_and_location_names_it(): void
    {
        $artifact = $this->storedArtifact($this->project, 'Tokens', [['index.html' => 'v1']]);

        $this->upload($this->base().'/'.$artifact->id.'/versions', ['notes' => 'Bigger hero'], ['index.html' => 'v2']);

        self::assertResponseStatusCodeSame(201);
        self::assertSame(2, $this->json()['versionNumber']);
        self::assertSame((string) $artifact->id, $this->json()['artifactId']);
        self::assertStringEndsWith($this->base().'/'.$artifact->id.'?versionNumber=2', (string) $this->client->getResponse()->headers->get('Location'));
        $stored = $this->stored($artifact);
        self::assertSame('Bigger hero', $this->versionNumbered($stored, 2)->notes);
        self::assertSame('Tokens', $stored->name);
    }

    public function test_a_version_with_no_files_is_refused_and_a_foreign_artifact_is_not_found(): void
    {
        $artifact = $this->storedArtifact($this->project, 'Tokens', [['index.html' => 'v1']]);
        $other = $this->scenario->createProject($this->owner, 'Other shop');
        $foreign = $this->storedArtifact($other, 'Foreign', [['index.html' => 'x']]);

        $this->upload($this->base().'/'.$artifact->id.'/versions', [], []);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('no_files', $this->json()['errors'][0]['code']);

        $this->upload($this->base().'/'.$foreign->id.'/versions', [], ['index.html' => 'y']);
        self::assertResponseStatusCodeSame(404);
        self::assertSame('artifact_not_found', $this->json()['code']);
        self::assertCount(1, $this->stored($foreign)->versions);
        self::assertCount(1, $this->stored($artifact)->versions);
    }

    public function test_a_repeated_idempotency_key_answers_the_first_answer_and_creates_one_artifact(): void
    {
        $server = ['HTTP_IDEMPOTENCY_KEY' => 'create-tokens-1'];

        $this->upload($this->base(), ['name' => 'Tokens'], ['index.html' => 'a'], server: $server);
        $first = $this->json();
        $this->upload($this->base(), ['name' => 'Tokens'], ['index.html' => 'a'], server: $server);

        self::assertResponseStatusCodeSame(201);
        self::assertSame($first, $this->json());
        self::assertSame(1, $this->artifacts()->count([]));
    }

    public function test_the_same_key_with_other_files_is_idempotency_key_reused(): void
    {
        $server = ['HTTP_IDEMPOTENCY_KEY' => 'create-tokens-2'];

        $this->upload($this->base(), ['name' => 'Tokens'], ['index.html' => 'a'], server: $server);
        $this->upload($this->base(), ['name' => 'Tokens'], ['index.html' => 'DIFFERENT'], server: $server);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('idempotency_key_reused', $this->json()['code']);
        self::assertSame(1, $this->artifacts()->count([]));
    }

    public function test_a_repeated_idempotency_key_stores_one_version(): void
    {
        $artifact = $this->storedArtifact($this->project, 'Tokens', [['index.html' => 'v1']]);
        $server = ['HTTP_IDEMPOTENCY_KEY' => 'version-1'];

        $this->upload($this->base().'/'.$artifact->id.'/versions', [], ['index.html' => 'v2'], server: $server);
        $first = $this->json();
        $this->upload($this->base().'/'.$artifact->id.'/versions', [], ['index.html' => 'v2'], server: $server);

        self::assertResponseStatusCodeSame(201);
        self::assertSame($first, $this->json());
        self::assertCount(2, $this->stored($artifact)->versions);
    }

    /** @return iterable<string, array{string, string}> */
    public static function routes(): iterable
    {
        yield 'list' => [Request::METHOD_GET, ''];
        yield 'create' => [Request::METHOD_POST, ''];
        yield 'show' => [Request::METHOD_GET, '/{id}'];
        yield 'update' => [Request::METHOD_PATCH, '/{id}'];
        yield 'file' => [Request::METHOD_GET, '/{id}/files/index.html'];
        yield 'version' => [Request::METHOD_POST, '/{id}/versions'];
    }

    #[DataProvider('routes')]
    public function test_a_switched_off_feature_answers_route_not_found_on_every_route(string $method, string $suffix): void
    {
        $artifact = $this->storedArtifact($this->project, 'Tokens', [['index.html' => 'v1']]);
        $this->switchFlag(false);

        $this->send($method, $this->base().str_replace('{id}', (string) $artifact->id, $suffix), '{"archived":true}', ['HTTP_IDEMPOTENCY_KEY' => 'off-'.$method.$suffix]);

        self::assertResponseStatusCodeSame(404);
        self::assertSame('route_not_found', $this->json()['code']);
        self::assertTrue($this->client->getResponse()->headers->has('RateLimit-Limit'));
        self::assertNull($this->stored($artifact)->archivedAt);
    }

    public function test_a_post_refused_while_the_feature_is_off_spends_no_idempotency_key(): void
    {
        $server = ['HTTP_IDEMPOTENCY_KEY' => 'off-then-on'];
        $this->switchFlag(false);
        $this->upload($this->base(), ['name' => 'Tokens'], ['index.html' => 'a'], server: $server);
        self::assertResponseStatusCodeSame(404);

        $this->switchFlag(true);
        $this->upload($this->base(), ['name' => 'Tokens'], ['index.html' => 'a'], server: $server);

        self::assertResponseStatusCodeSame(201);
        self::assertSame(1, $this->artifacts()->count([]));
    }

    public function test_a_switched_off_feature_still_refuses_a_request_with_no_credential(): void
    {
        $this->switchFlag(false);

        $this->send(Request::METHOD_GET, $this->base(), token: null);

        self::assertResponseStatusCodeSame(401);
    }

    public function test_every_public_artifact_route_carries_the_name_prefix_the_flag_listener_reads(): void
    {
        $router = static::getContainer()->get(RouterInterface::class);
        $seen = 0;
        foreach ($router->getRouteCollection() as $name => $route) {
            if (str_starts_with((string) $route->getPath(), '/api/v1/projects/{project}/artifacts')) {
                self::assertStringStartsWith(RefuseArtifactApiWhenSwitchedOff::ROUTE_PREFIX, $name);
                ++$seen;
            }
        }
        self::assertSame(6, $seen);
    }

    #[DataProvider('routes')]
    public function test_a_project_of_another_owner_reads_as_a_missing_project(string $method, string $suffix): void
    {
        $other = $this->scenario->createUser('artifact-other-'.uniqid().'@example.com');
        $otherProject = $this->scenario->createProject($other, 'Their shop');
        $artifact = $this->storedArtifact($otherProject, 'Theirs', [['index.html' => 'x']]);

        $this->send($method, '/api/v1/projects/'.$otherProject->id.'/artifacts'.str_replace('{id}', (string) $artifact->id, $suffix), '{"archived":true}');

        self::assertResponseStatusCodeSame(404);
        self::assertSame('project_not_found', $this->json()['code']);
        self::assertNull($this->stored($artifact)->archivedAt);
    }

    #[DataProvider('routes')]
    public function test_a_request_with_no_credential_is_refused(string $method, string $suffix): void
    {
        $this->send($method, $this->base().str_replace('{id}', (string) Uuid::v7(), $suffix), token: null);

        self::assertResponseStatusCodeSame(401);
        self::assertSame('invalid_credentials', $this->json()['code']);
    }

    #[DataProvider('routes')]
    public function test_a_credential_without_the_public_scope_is_refused(string $method, string $suffix): void
    {
        $this->send($method, $this->base().str_replace('{id}', (string) Uuid::v7(), $suffix), token: AgentCredential::tokenFor(static::getContainer(), $this->owner, 'agent mcp'));

        self::assertResponseStatusCodeSame(403);
        self::assertSame('credential_not_allowed', $this->json()['code']);
    }

    #[DataProvider('routes')]
    public function test_a_credential_bound_to_another_project_reaches_nothing_here(string $method, string $suffix): void
    {
        $bound = $this->scenario->createProject($this->owner, 'Bound shop');
        $artifact = $this->storedArtifact($this->project, 'Tokens', [['index.html' => 'v1']]);

        $this->send($method, $this->base().str_replace('{id}', (string) $artifact->id, $suffix), '{"archived":true}', token: AgentCredential::tokenFor(static::getContainer(), $this->owner, 'mcp public', $bound));

        self::assertResponseStatusCodeSame(404);
        self::assertSame('project_not_found', $this->json()['code']);
        self::assertNull($this->stored($artifact)->archivedAt);
    }

    private function base(): string
    {
        return '/api/v1/projects/'.$this->project->id.'/artifacts';
    }

    private function switchFlag(bool $on): void
    {
        $this->em()->getConnection()->executeStatement('UPDATE feature_flag SET value = ? WHERE name = ?', [$on ? 'true' : 'false', ArtifactInstallFlags::FLAG_ENABLED]);
    }

    private function artifacts(): ArtifactRepository
    {
        $this->em()->clear();

        return static::getContainer()->get(ArtifactRepository::class);
    }

    private function stored(\App\Module\Artifact\Entity\Artifact|string $artifact): \App\Module\Artifact\Entity\Artifact
    {
        $id = \is_string($artifact) ? $artifact : (string) $artifact->id;

        return $this->artifacts()->find($id) ?? throw new \LogicException('The artifact is gone.');
    }

    private function setCreatedAt(\App\Module\Artifact\Entity\Artifact $artifact, string $at): void
    {
        $this->em()->getConnection()->executeStatement('UPDATE artifacts SET created_at = ? WHERE id = ?', [$at, (string) $artifact->id]);
        $this->em()->clear();
    }

    /**
     * @param array<string, string> $fields
     * @param array<string, string> $contentsByPath
     * @param ?list<string>         $paths          the paths to send, when they are not the keys of $contentsByPath
     * @param array<string, string> $server
     */
    private function upload(string $url, array $fields, array $contentsByPath, ?array $paths = null, array $server = []): void
    {
        $files = [];
        foreach ($contentsByPath as $path => $contents) {
            $temp = tempnam(sys_get_temp_dir(), 'artifact');
            self::assertNotFalse($temp);
            $this->tempFiles[] = $temp;
            file_put_contents($temp, $contents);
            $files[] = new UploadedFile($temp, basename((string) $path), str_ends_with((string) $path, '.html') ? 'text/html' : 'text/plain', null, true);
        }

        $this->client->request(
            Request::METHOD_POST,
            $url,
            $fields + ['paths' => $paths ?? array_map(strval(...), array_keys($contentsByPath))],
            [] === $files ? [] : ['files' => $files],
            $server + ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token],
        );
    }

    /** @param array<string, string> $server */
    private function send(string $method, string $url, string $body = '', array $server = [], ?string $token = ''): void
    {
        $bearer = '' === $token ? $this->token : $token;
        $this->client->request($method, $url, server: $server + ['CONTENT_TYPE' => 'application/json'] + (null === $bearer ? [] : ['HTTP_AUTHORIZATION' => 'Bearer '.$bearer]), content: $body);
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        return $data;
    }
}
