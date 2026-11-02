<?php

declare(strict_types=1);

namespace App\Tests\Module\Artifact\Api;

use App\Module\Account\Entity\User;
use App\Module\Artifact\Controller\Api\ArtifactResponse;
use App\Module\Artifact\Install\ArtifactInstallFlags;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Artifact\ArtifactScenario;
use App\Tests\Support\ApiKeyCredential;
use App\Tests\Support\OAuthScenario;
use App\Tests\Support\PublicSpec;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;

/** The fields parameter on the artifact list and the artifact read. */
final class ArtifactFieldsApiTest extends WebTestCase
{
    use ArtifactScenario;

    private KernelBrowser $client;
    private User $owner;
    private Project $project;
    private string $token;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        static::getContainer()->set('clock', new MockClock('2026-03-01 12:00:00'));
        $scenario = new OAuthScenario(static::getContainer());
        $this->owner = $scenario->createUser('artifact-fields-'.uniqid().'@example.com');
        $this->project = $scenario->createProject($this->owner, 'Artifact fields');
        $this->em()->getConnection()->executeStatement('UPDATE feature_flag SET value = ? WHERE name = ?', ['true', ArtifactInstallFlags::FLAG_ENABLED]);
        $this->token = ApiKeyCredential::mint(static::getContainer(), $this->owner);
    }

    public function test_the_default_row_holds_short_versions_and_paths_and_no_signed_link(): void
    {
        $artifact = $this->twoVersions('Tokens');

        $this->get($this->base());

        self::assertResponseStatusCodeSame(200);
        $row = $this->json()['items'][0];
        self::assertSame(['artifactId', 'name', 'archived', 'archivedAt', 'sourceLink', 'createdAt', 'path', 'versions', 'selectedVersion', 'files'], array_keys($row));
        self::assertSame((string) $artifact->id, $row['artifactId']);
        self::assertSame([1, 2], array_column($row['versions'], 'number'));
        self::assertSame(['number', 'storedAt'], array_keys($row['versions'][0]));
        self::assertSame(2, $row['selectedVersion']);
        self::assertSame([['path' => 'css/app.css'], ['path' => 'index.html']], $row['files']);
        self::assertStringNotContainsString('signature', (string) $this->client->getResponse()->getContent());
    }

    public function test_each_name_alone_equals_the_same_name_of_the_single_read(): void
    {
        $first = $this->twoVersions('Tokens');
        $second = $this->storedArtifact($this->project, 'Plain', [['index.html' => 'one']]);
        $this->em()->getConnection()->executeStatement("UPDATE artifacts SET created_at = '2026-01-01 10:00:00' WHERE id = ?", [(string) $first->id]);
        $this->em()->getConnection()->executeStatement("UPDATE artifacts SET created_at = '2026-01-02 10:00:00' WHERE id = ?", [(string) $second->id]);
        $this->em()->clear();

        foreach ([$first, $second] as $index => $artifact) {
            $this->get($this->base().'/'.$artifact->id);
            $single = $this->json();
            self::assertIsString($single['pageLink']);
            self::assertSame(ArtifactResponse::FIELDS, array_keys($single));

            foreach (ArtifactResponse::FIELDS as $field) {
                $this->get($this->base().'?fields='.$field);
                self::assertResponseStatusCodeSame(200, $field);
                self::assertSame([$field => $single[$field]], $this->json()['items'][$index], $field);
            }

            $this->get($this->base().'?fields=all');
            self::assertSame($single, $this->json()['items'][$index]);
        }
    }

    public function test_each_answer_matches_the_schema_the_spec_publishes_for_it(): void
    {
        $artifact = $this->twoVersions('Tokens');

        $this->get($this->base());
        PublicSpec::assertMatches('ArtifactList', $this->json());
        PublicSpec::assertMatches('ArtifactRow', $this->json()['items'][0]);
        PublicSpec::assertDoesNotMatch('Artifact', $this->json()['items'][0]);

        $this->get($this->base().'/'.$artifact->id);
        PublicSpec::assertMatches('Artifact', $this->json());

        $this->get($this->base().'?fields=all');
        PublicSpec::assertMatches('ArtifactList', $this->json());
        PublicSpec::assertMatches('Artifact', $this->json()['items'][0]);

        foreach (['name,path', 'versions', 'files', 'pageLink'] as $fields) {
            $this->get($this->base().'?fields='.$fields);
            PublicSpec::assertMatches('ArtifactList', $this->json(), $fields);
            PublicSpec::assertMatches('PartialArtifact', $this->json()['items'][0], $fields);

            $this->get($this->base().'/'.$artifact->id.'?fields='.$fields);
            PublicSpec::assertMatches('PartialArtifact', $this->json(), $fields);
        }
    }

    public function test_a_named_versions_or_files_comes_back_whole(): void
    {
        $artifact = $this->twoVersions('Tokens');

        $this->get($this->base().'?fields=versions,files');

        $row = $this->json()['items'][0];
        self::assertSame(['versions', 'files'], array_keys($row));
        self::assertSame(['number', 'storedAt', 'storedInSession', 'notes', 'fileCount', 'totalBytes'], array_keys($row['versions'][0]));
        self::assertSame('Second pass', $row['versions'][1]['notes']);
        self::assertSame(2, $row['versions'][1]['fileCount']);
        self::assertSame(['path', 'contentType', 'size'], array_keys($row['files'][0]));
        self::assertNotNull($artifact->id);
    }

    public function test_the_page_link_is_signed_for_one_hour_from_the_clock(): void
    {
        $this->twoVersions('Tokens');

        $this->get($this->base().'?fields=pageLink');

        $link = $this->json()['items'][0]['pageLink'];
        self::assertIsString($link);
        self::assertStringContainsString((string) new \DateTimeImmutable('2026-03-01 13:00:00')->getTimestamp(), $link);
        self::assertStringEndsWith('index.html', $link);
    }

    public function test_a_page_reads_the_same_statements_whatever_its_size(): void
    {
        foreach (range(1, 6) as $n) {
            $this->storedArtifact($this->project, 'Artifact '.$n, [['index.html' => 'a'.$n], ['index.html' => 'b'.$n, 'app.css' => 'c'.$n]]);
        }

        foreach (['', '?fields=all', '?fields=versions', '?fields=files', '?fields=pageLink', '?fields=name'] as $query) {
            $glue = '' === $query ? '?' : '&';
            $small = $this->statementsOf($this->base().$query.$glue.'limit=2');
            $big = $this->statementsOf($this->base().$query.$glue.'limit=6');

            $small = array_filter($small, static fn (string $sql): bool => str_contains($sql, 'artifact'));
            $big = array_filter($big, static fn (string $sql): bool => str_contains($sql, 'artifact'));
            self::assertNotSame([], $small, $query);
            self::assertCount(\count($small), $big, $query);
        }
    }

    public function test_a_narrow_row_skips_the_version_and_file_reads(): void
    {
        $this->twoVersions('Tokens');

        $narrow = $this->statementsOf($this->base().'?fields=name');
        $wide = $this->statementsOf($this->base());

        self::assertNotSame([], $narrow);
        self::assertSame([], array_values(array_filter($narrow, static fn (string $sql): bool => str_contains($sql, 'artifact_versions') || str_contains($sql, 'artifact_files'))));
        self::assertNotSame([], array_values(array_filter($wide, static fn (string $sql): bool => str_contains($sql, 'artifact_files'))));
        self::assertSame([], array_values(array_filter($wide, static fn (string $sql): bool => str_contains($sql, 'bytes'))));
    }

    public function test_an_unknown_name_is_refused_and_a_single_read_is_only_narrowed(): void
    {
        $artifact = $this->twoVersions('Tokens');

        foreach ([$this->base(), $this->base().'/'.$artifact->id] as $url) {
            $this->get($url.'?fields=nope');
            self::assertResponseStatusCodeSame(422, $url);
            self::assertSame('fields_invalid', $this->json()['errors'][0]['code']);
        }

        $this->get($this->base().'/'.$artifact->id.'?fields=name,files');
        self::assertSame(['name', 'files'], array_keys($this->json()));
    }

    private function twoVersions(string $name): \App\Module\Artifact\Entity\Artifact
    {
        $artifact = $this->storedArtifact($this->project, $name, [['index.html' => 'v1'], ['index.html' => 'v2!', 'css/app.css' => 'body{}']]);
        $second = $this->versionNumbered($artifact, 2);
        $second->notes = 'Second pass';
        $this->em()->flush();

        return $artifact;
    }

    private function base(): string
    {
        return '/api/v1/projects/'.$this->project->id.'/artifacts';
    }

    private function get(string $url): void
    {
        $this->client->request(Request::METHOD_GET, $url, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token]);
    }

    /** @return list<string> */
    private function statementsOf(string $url): array
    {
        $queries = static::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(DebugDataHolder::class, $queries);
        $queries->reset();
        $this->get($url);
        self::assertResponseStatusCodeSame(200, $url);

        $all = [];
        foreach ($queries->getData() as $connectionQueries) {
            foreach ($connectionQueries as $query) {
                $all[] = (string) $query['sql'];
            }
        }

        return $all;
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        return $data;
    }
}
