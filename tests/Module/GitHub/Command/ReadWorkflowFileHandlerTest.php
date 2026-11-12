<?php

declare(strict_types=1);

namespace App\Tests\Module\GitHub\Command;

use App\Module\Forge\Entity\ForgeRepository;
use App\Module\Forge\Entity\ForgeRepositorySource;
use App\Module\Forge\Repository\ForgeRepositoryRepository;
use App\Module\GitHub\Command\ReadWorkflowFileCommand;
use App\Module\GitHub\Command\ReadWorkflowFileHandler;
use App\Module\GitHub\Entity\GitHubInstallation;
use App\Module\GitHub\Entity\GitHubRepositorySelection;
use App\Module\GitHub\Messenger\ReadRepositoryWorkflowSettings;
use App\Module\GitHub\Messenger\ReadRepositoryWorkflowSettingsHandler;
use App\Module\GitHub\Repository\GitHubInstallationRepository;
use App\Module\GitHub\Service\GitHubAppApi;
use App\Module\GitHub\Service\GitHubAppConfiguration;
use App\Module\GitHub\Service\GitHubRepositoryInstallations;
use App\Module\GitHub\Service\GitHubWorkflowFileReader;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\RepositoryWorkflowSettingsWriter;
use App\Module\Workflow\Entity\WorkflowRepositorySetting;
use App\Module\Workflow\Repository\WorkflowRepositorySettingRepository;
use App\Module\Workflow\Repository\WorkflowSettingOverrideRepository;
use App\Module\Workflow\Service\SettingValues;
use App\Tests\Module\GitHub\Service\CountingWorkflowSettingsWriter;
use App\Tests\Module\Workflow\WorkflowProjects;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Uid\Uuid;

final class ReadWorkflowFileHandlerTest extends KernelTestCase
{
    use WorkflowProjects;

    private const int INSTALLATION = 4_100_001;
    private const string HEAD = 'a1b2c3d4e5f60718293a4b5c6d7e8f9012345678';
    private const string BLOB = 'b10b5ha0000000000000000000000000000000aa';

    private Project $project;
    private Uuid $repositoryId;

    /** @var list<string> the method and the path with the query of each request */
    private array $requests = [];

    private ?MockResponse $branch = null;
    private ?MockResponse $contents = null;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->project = $this->workflowProject('read-file');
        $this->bindLifecycle($this->project);
        $this->repositoryId = $this->createRepository(ForgeRepositorySource::Installation);
    }

    public function test_a_valid_file_stores_its_rows_and_reads_the_head_before_the_contents_at_that_head(): void
    {
        $this->contents = $this->file($this->yaml("merge-approval: person\nepic-branches: false"));

        $this->read();

        $row = $this->overrideRows()['merge-approval'];
        self::assertTrue($row->valid);
        self::assertSame('"person"', $row->value);
        self::assertSame(self::BLOB, $row->blobSha);
        self::assertSame(self::HEAD, $row->headSha);
        self::assertFalse(false === $this->overrideRows()['epic-branches']->valid);
        self::assertSame([], $this->storedRepository()->fileWarnings);
        self::assertSame([
            'POST /app/installations/'.self::INSTALLATION.'/access_tokens',
            'GET /repos/acme/app',
            'GET /repos/acme/app/branches/main',
            'GET /repos/acme/app/contents/.loupe.yaml?ref='.self::HEAD,
        ], $this->requests);
    }

    public function test_a_value_that_is_not_allowed_stores_an_invalid_row_and_a_warning(): void
    {
        $this->contents = $this->file($this->yaml('merge-approval: maybe'));

        $this->read();

        self::assertFalse($this->overrideRows()['merge-approval']->valid);
        $warnings = $this->storedRepository()->fileWarnings;
        self::assertCount(1, $warnings);
        self::assertStringContainsString('merge-approval', $warnings[0]);
        self::assertStringContainsString('agent, person', $warnings[0]);
    }

    public function test_an_unknown_key_stores_nothing_and_warns(): void
    {
        $this->contents = $this->file($this->yaml('no-such-key: x'));

        $this->read();

        self::assertSame([], $this->overrideRows());
        self::assertCount(1, $this->storedRepository()->fileWarnings);
        self::assertStringContainsString('no-such-key', $this->storedRepository()->fileWarnings[0]);
    }

    public function test_a_missing_file_removes_the_rows_and_the_warnings_and_keeps_the_stored_value(): void
    {
        $this->contents = $this->file($this->yaml("merge-approval: person\nno-such-key: x"));
        $this->read();
        $this->store('epic-child-merge-method', 'merge');
        self::assertNotSame([], $this->storedRepository()->fileWarnings);
        $this->contents = new MockResponse('{"message":"Not Found"}', ['http_code' => 404]);

        $this->read();

        self::assertSame([], $this->overrideRows());
        self::assertSame([], $this->storedRepository()->fileWarnings);
        self::assertSame(1, $this->storedRows());
    }

    public function test_a_file_that_names_another_project_is_not_usable(): void
    {
        $this->contents = $this->file($this->yaml('merge-approval: person'));
        $this->read();
        $this->contents = $this->file('project: '.Uuid::v7()."\nworkflow:\n  merge-approval: agent\n");

        $this->read();

        self::assertSame([], $this->overrideRows());
    }

    public function test_the_project_id_matches_without_regard_to_case(): void
    {
        $this->contents = $this->file('project: '.strtoupper((string) $this->project->id)."\nworkflow:\n  merge-approval: agent\n");

        $this->read();

        self::assertTrue($this->overrideRows()['merge-approval']->valid);
    }

    /** @return iterable<string, array{string}> */
    public static function unusableFiles(): iterable
    {
        yield 'no project' => ["workflow:\n  merge-approval: agent\n"];
        yield 'project is not a uuid' => ["project: nope\nworkflow:\n  merge-approval: agent\n"];
        yield 'project is a list' => ["project: [a]\nworkflow:\n  merge-approval: agent\n"];
        yield 'a scalar document' => ["just text\n"];
        yield 'broken yaml' => ["project: [\nworkflow: {\n"];
    }

    #[DataProvider('unusableFiles')]
    public function test_a_file_that_cannot_be_used_removes_the_rows(string $content): void
    {
        $this->contents = $this->file($this->yaml('merge-approval: person'));
        $this->read();
        self::assertNotSame([], $this->overrideRows());
        $this->contents = $this->file($content);

        $this->read();

        self::assertSame([], $this->overrideRows());
    }

    /** @return iterable<string, array{string}> */
    public static function dangerousYaml(): iterable
    {
        yield 'php object' => ["!php/object 'O:8:\"stdClass\":0:{}'"];
        yield 'php const' => ["project: !php/const PHP_VERSION\n"];
        yield 'custom tag on the map' => ["project: PROJECT\nworkflow: !custom\n  merge-approval: agent\n"];
        yield 'custom tag on the value' => ["project: PROJECT\nworkflow:\n  merge-approval: !custom agent\n"];
    }

    #[DataProvider('dangerousYaml')]
    public function test_a_tag_or_an_object_makes_the_file_unusable_and_runs_nothing(string $content): void
    {
        $this->contents = $this->file($this->yaml('merge-approval: person'));
        $this->read();
        $this->contents = $this->file(str_replace('PROJECT', (string) $this->project->id, $content));

        $this->read();

        self::assertSame([], $this->overrideRows());
    }

    public function test_a_file_over_the_size_cap_is_not_usable(): void
    {
        $this->contents = $this->file($this->yaml('merge-approval: person'));
        $this->read();
        $this->contents = $this->file($this->yaml('merge-approval: agent'), size: 65_537);

        $this->read();

        self::assertSame([], $this->overrideRows());
    }

    public function test_a_body_over_the_cap_is_not_usable_whatever_size_the_answer_claims(): void
    {
        $this->contents = $this->file($this->yaml('merge-approval: person'));
        $this->read();
        $this->contents = $this->file($this->yaml('merge-approval: agent')."\n# ".str_repeat('x', 65_536), size: 10);

        $this->read();

        self::assertSame([], $this->overrideRows());
    }

    public function test_a_file_exactly_at_the_cap_is_read(): void
    {
        $body = $this->yaml('merge-approval: agent');
        $body .= "\n# ".str_repeat('x', 65_536 - \strlen($body) - 3);
        self::assertSame(65_536, \strlen($body));
        $this->contents = $this->file($body);

        $this->read();

        self::assertTrue($this->overrideRows()['merge-approval']->valid);
    }

    public function test_a_directory_in_place_of_the_file_is_not_usable(): void
    {
        $this->contents = $this->file($this->yaml('merge-approval: person'));
        $this->read();
        $this->contents = new MockResponse('[{"name":"a","type":"file"}]', ['http_code' => 200]);

        $this->read();

        self::assertSame([], $this->overrideRows());
    }

    public function test_a_workflow_that_is_not_a_map_sets_nothing(): void
    {
        $this->contents = $this->file($this->yaml('merge-approval: person'));
        $this->read();
        $this->contents = $this->file('project: '.$this->project->id."\nworkflow:\n  - merge-approval\n", blob: 'c0ffee0000000000000000000000000000000000');

        $this->read();

        self::assertSame([], $this->overrideRows());
        self::assertSame([], $this->storedRepository()->fileWarnings);
    }

    public function test_the_same_blob_writes_nothing_again(): void
    {
        $this->contents = $this->file($this->yaml('merge-approval: person'));
        $writer = $this->countingWriter();
        $this->read($writer);
        $this->contents = $this->file($this->yaml('merge-approval: person'));
        $this->branch = $this->branchAt('ffffffffffffffffffffffffffffffffffffffff');

        $this->read($writer);

        self::assertSame(1, $writer->applied);
        self::assertSame(self::HEAD, $this->overrideRows()['merge-approval']->headSha);
    }

    public function test_a_new_blob_writes_again(): void
    {
        $writer = $this->countingWriter();
        $this->contents = $this->file($this->yaml('merge-approval: person'));
        $this->read($writer);
        $this->contents = $this->file($this->yaml('merge-approval: agent'), blob: 'c0ffee0000000000000000000000000000000000');

        $this->read($writer);

        self::assertSame(2, $writer->applied);
        self::assertSame('"agent"', $this->overrideRows()['merge-approval']->value);
    }

    /** @return iterable<string, array{string}> */
    public static function failingCalls(): iterable
    {
        yield 'repository' => ['repository'];
        yield 'branch' => ['branch'];
        yield 'contents' => ['contents'];
    }

    #[DataProvider('failingCalls')]
    public function test_a_failed_call_leaves_the_rows_and_the_warnings_as_they_are(string $failing): void
    {
        $this->contents = $this->file($this->yaml("merge-approval: person\nno-such-key: x"));
        $this->read();
        $before = [$this->overrideRows()['merge-approval']->value, $this->storedRepository()->fileWarnings];
        $this->contents = $this->file($this->yaml('merge-approval: agent'), blob: 'c0ffee0000000000000000000000000000000000');
        $server = new MockResponse('{"message":"boom"}', ['http_code' => 502]);
        match ($failing) {
            'repository' => $this->repositoryAnswer = $server,
            'branch' => $this->branch = $server,
            default => $this->contents = $server,
        };

        $this->read();

        self::assertSame($before, [$this->overrideRows()['merge-approval']->value, $this->storedRepository()->fileWarnings]);
    }

    public function test_a_malformed_head_leaves_the_rows_as_they_are(): void
    {
        $this->contents = $this->file($this->yaml('merge-approval: person'));
        $this->read();
        $this->branch = $this->branchAt('not-a-sha');
        $this->contents = $this->file($this->yaml('merge-approval: agent'), blob: 'c0ffee0000000000000000000000000000000000');

        $this->read();

        self::assertSame('"person"', $this->overrideRows()['merge-approval']->value);
    }

    public function test_a_repository_that_is_not_an_installation_row_makes_no_request(): void
    {
        $hook = $this->createRepository(ForgeRepositorySource::Hook);

        $this->read(repositoryId: $hook);

        self::assertSame([], $this->requests);
    }

    public function test_an_unknown_repository_makes_no_request(): void
    {
        $this->read(repositoryId: Uuid::v7());

        self::assertSame([], $this->requests);
    }

    public function test_a_suspended_installation_makes_no_content_request_and_keeps_the_rows(): void
    {
        $this->contents = $this->file($this->yaml('merge-approval: person'));
        $this->read();
        $installations = self::getContainer()->get(GitHubInstallationRepository::class);
        self::assertInstanceOf(GitHubInstallationRepository::class, $installations);
        $installation = $installations->findOneByInstallationId(self::INSTALLATION);
        self::assertInstanceOf(GitHubInstallation::class, $installation);
        $installation->suspendedAt = new \DateTimeImmutable();
        $this->em()->flush();
        $this->requests = [];

        $this->read();

        self::assertSame([], $this->requests);
        self::assertTrue($this->overrideRows()['merge-approval']->valid);
    }

    public function test_the_message_handler_reads_for_a_valid_id_and_ignores_a_bad_one(): void
    {
        $this->contents = $this->file($this->yaml('merge-approval: person'));
        $handler = new ReadRepositoryWorkflowSettingsHandler($this->handler());

        $handler(new ReadRepositoryWorkflowSettings('not-a-uuid'));
        self::assertSame([], $this->requests);

        $handler(new ReadRepositoryWorkflowSettings((string) $this->repositoryId));
        self::assertTrue($this->overrideRows()['merge-approval']->valid);
    }

    private ?MockResponse $repositoryAnswer = null;

    private function read(?RepositoryWorkflowSettingsWriter $writer = null, ?Uuid $repositoryId = null): void
    {
        ($this->handler($writer))(new ReadWorkflowFileCommand($repositoryId ?? $this->repositoryId));
        $this->em()->clear();
    }

    private function handler(?RepositoryWorkflowSettingsWriter $writer = null): ReadWorkflowFileHandler
    {
        $client = new MockHttpClient(function (string $method, string $url): MockResponse {
            $path = substr($url, \strlen('https://api.github.com'));
            $this->requests[] = $method.' '.$path;

            return match (true) {
                str_ends_with($path, '/access_tokens') => new MockResponse('{"token":"ghs_token"}', ['http_code' => 201]),
                '/repos/acme/app' === $path => $this->repositoryAnswer ?? new MockResponse('{"default_branch":"main"}', ['http_code' => 200]),
                '/repos/acme/app/branches/main' === $path => $this->branch ?? $this->branchAt(self::HEAD),
                str_starts_with($path, '/repos/acme/app/contents/.loupe.yaml?ref=') => $this->contents ?? throw new \LogicException('No contents answer set.'),
                default => throw new \LogicException('Unexpected request '.$method.' '.$path),
            };
        }, 'https://api.github.com');
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => \OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($key);
        self::assertTrue(openssl_pkey_export($key, $pem));
        self::assertIsString($pem);

        $container = self::getContainer();
        $installations = $container->get(GitHubRepositoryInstallations::class);
        self::assertInstanceOf(GitHubRepositoryInstallations::class, $installations);
        $repositories = $container->get(ForgeRepositoryRepository::class);
        self::assertInstanceOf(ForgeRepositoryRepository::class, $repositories);
        $real = $container->get(RepositoryWorkflowSettingsWriter::class);
        self::assertInstanceOf(RepositoryWorkflowSettingsWriter::class, $real);

        return new ReadWorkflowFileHandler(
            $repositories,
            new GitHubWorkflowFileReader($installations, new GitHubAppApi($client, new GitHubAppConfiguration(null, null, null, null, '123456', $pem), new MockClock('@1790000000'))),
            $writer ?? $real,
            $this->em(),
            new NullLogger(),
        );
    }

    private function countingWriter(): CountingWorkflowSettingsWriter
    {
        $real = self::getContainer()->get(RepositoryWorkflowSettingsWriter::class);
        self::assertInstanceOf(RepositoryWorkflowSettingsWriter::class, $real);

        return new CountingWorkflowSettingsWriter($real);
    }

    private function createRepository(ForgeRepositorySource $source): Uuid
    {
        $em = $this->em();
        $project = $em->getReference(Project::class, $this->project->requireId()) ?? throw new \LogicException('No project.');
        if (ForgeRepositorySource::Installation === $source) {
            $em->persist(new GitHubInstallation(self::INSTALLATION, 'acme', GitHubRepositorySelection::All));
        }
        $row = new ForgeRepository($project, 'github', 'ext-'.uniqid(), 'acme/app', $source, ForgeRepositorySource::Installation === $source ? (string) self::INSTALLATION : null);
        $em->persist($row);
        $em->flush();
        $em->clear();

        return $row->id ?? throw new \LogicException('Not flushed.');
    }

    private function yaml(string $workflow): string
    {
        return 'project: '.$this->project->id."\nworkflow:\n".implode("\n", array_map(static fn (string $line): string => '  '.$line, explode("\n", $workflow)))."\n";
    }

    private function file(string $content, ?int $size = null, string $blob = self::BLOB): MockResponse
    {
        return new MockResponse(json_encode([
            'type' => 'file',
            'encoding' => 'base64',
            'size' => $size ?? \strlen($content),
            'sha' => $blob,
            'content' => chunk_split(base64_encode($content), 60, "\n"),
        ], \JSON_THROW_ON_ERROR), ['http_code' => 200]);
    }

    private function branchAt(string $sha): MockResponse
    {
        return new MockResponse(json_encode(['name' => 'main', 'commit' => ['sha' => $sha]], \JSON_THROW_ON_ERROR), ['http_code' => 200]);
    }

    /** @return array<string, \App\Module\Workflow\Entity\WorkflowSettingOverride> */
    private function overrideRows(): array
    {
        $rows = self::getContainer()->get(WorkflowSettingOverrideRepository::class);
        self::assertInstanceOf(WorkflowSettingOverrideRepository::class, $rows);
        $byKey = [];
        foreach ($rows->findByRepositoryId($this->repositoryId) as $row) {
            $byKey[$row->settingKey] = $row;
        }

        return $byKey;
    }

    private function storedRepository(): ForgeRepository
    {
        return $this->em()->find(ForgeRepository::class, $this->repositoryId) ?? throw new \LogicException('The repository is gone.');
    }

    private function store(string $key, string $value): void
    {
        $project = $this->em()->getReference(Project::class, $this->project->requireId()) ?? throw new \LogicException('No project.');
        $this->em()->persist(new WorkflowRepositorySetting($project, $this->repositoryId, $key, SettingValues::encode($value)));
        $this->em()->flush();
        $this->em()->clear();
    }

    private function storedRows(): int
    {
        $rows = self::getContainer()->get(WorkflowRepositorySettingRepository::class);
        self::assertInstanceOf(WorkflowRepositorySettingRepository::class, $rows);

        return null === $rows->findOneByRepositoryIdAndKey($this->repositoryId, 'epic-child-merge-method') ? 0 : 1;
    }
}
