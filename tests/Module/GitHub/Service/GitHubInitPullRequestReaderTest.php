<?php

declare(strict_types=1);

namespace App\Tests\Module\GitHub\Service;

use App\Module\Forge\Entity\ForgeRepository;
use App\Module\Forge\Entity\ForgeRepositorySource;
use App\Module\Forge\Service\InitPullRequestReader;
use App\Module\Forge\Service\InitPullRequestReaders;
use App\Module\GitHub\Entity\GitHubInstallation;
use App\Module\GitHub\Entity\GitHubRepositorySelection;
use App\Module\GitHub\Service\GitHubAppApi;
use App\Module\GitHub\Service\GitHubAppConfiguration;
use App\Module\GitHub\Service\GitHubInitPullRequestReader;
use App\Module\GitHub\Service\GitHubRepositoryInstallations;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\WorkflowSettings;
use App\Tests\Module\Workflow\Service\WorkflowSettingsTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class GitHubInitPullRequestReaderTest extends WorkflowSettingsTestCase
{
    private const int INSTALLATION = 74_501;
    private const string HEAD = 'a1b2c3d4e5f60718293a4b5c6d7e8f9012345678';

    private Project $project;

    /** @var list<string> */
    private array $requests = [];

    /** @var list<MockResponse> */
    private array $responses = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->project = $this->workflowProject('init-pull-request');
        $this->bindLifecycle($this->project);
        $this->resetServices();
    }

    public function test_the_registry_hands_github_to_this_reader(): void
    {
        $reader = $this->reader();

        self::assertSame($reader, new InitPullRequestReaders([$reader])->for('github'));
        self::assertNull(new InitPullRequestReaders([$reader])->for('gitlab'));
    }

    public function test_an_open_pull_request_that_sets_a_valid_merge_approval_answers_true(): void
    {
        $repository = $this->installedRepository();
        $this->responses = [
            $this->answer(['token' => 'ghs_token'], 201),
            $this->answer([['head' => ['sha' => self::HEAD]]]),
            $this->file("project: {$this->project->requireId()}\nworkflow:\n  merge-approval: person\n"),
        ];

        self::assertTrue($this->reader()->setsMergeSetting($repository));
        self::assertSame([
            'POST /app/installations/'.self::INSTALLATION.'/access_tokens',
            'GET /repos/acme/app/pulls?head=acme:'.InitPullRequestReader::BRANCH.'&state=open',
            'GET /repos/acme/app/contents/.loupe.yaml?ref='.self::HEAD,
        ], $this->requests);
    }

    public function test_no_open_pull_request_answers_false(): void
    {
        $this->responses = [$this->answer(['token' => 'ghs_token'], 201), $this->answer([])];

        self::assertFalse($this->reader()->setsMergeSetting($this->installedRepository()));
    }

    /** @return iterable<string, array{string}> */
    public static function unusableFiles(): iterable
    {
        yield 'no merge approval' => ["workflow:\n  epic-branches: false\n"];
        yield 'a value that is not allowed' => ["workflow:\n  merge-approval: maybe\n"];
        yield 'another project' => ["project: 0197d9a0-0000-7000-8000-000000000000\nworkflow:\n  merge-approval: person\n"];
        yield 'broken yaml' => ["project: [\nworkflow: {\n"];
    }

    #[DataProvider('unusableFiles')]
    public function test_a_file_that_does_not_set_a_valid_merge_approval_answers_false(string $content): void
    {
        $repository = $this->installedRepository();
        if (!str_contains($content, 'project:')) {
            $content = "project: {$this->project->requireId()}\n".$content;
        }
        $this->responses = [$this->answer(['token' => 'ghs_token'], 201), $this->answer([['head' => ['sha' => self::HEAD]]]), $this->file($content)];

        self::assertFalse($this->reader()->setsMergeSetting($repository));
    }

    public function test_a_pull_request_with_no_file_answers_false(): void
    {
        $repository = $this->installedRepository();
        $this->responses = [
            $this->answer(['token' => 'ghs_token'], 201),
            $this->answer([['head' => ['sha' => self::HEAD]]]),
            $this->answer(['message' => 'Not Found'], 404),
        ];

        self::assertFalse($this->reader()->setsMergeSetting($repository));
    }

    public function test_a_github_failure_answers_false(): void
    {
        $repository = $this->installedRepository();
        $this->responses = [$this->answer(['token' => 'ghs_token'], 201), $this->answer([], 500)];

        self::assertFalse($this->reader()->setsMergeSetting($repository));
    }

    public function test_a_malformed_head_answers_false(): void
    {
        $repository = $this->installedRepository();
        $this->responses = [$this->answer(['token' => 'ghs_token'], 201), $this->answer([['head' => ['sha' => 'nope']]])];

        self::assertFalse($this->reader()->setsMergeSetting($repository));
    }

    public function test_a_repository_without_access_answers_false_and_asks_nothing(): void
    {
        $repository = $this->installedRepository();
        $repository->accessLostAt = new \DateTimeImmutable();

        self::assertFalse($this->reader()->setsMergeSetting($repository));
        self::assertSame([], $this->requests);
    }

    private function installedRepository(): ForgeRepository
    {
        $this->em()->persist(new GitHubInstallation(self::INSTALLATION, 'acme', GitHubRepositorySelection::Selected));
        $repository = new ForgeRepository($this->em()->getReference(Project::class, $this->project->requireId()) ?? throw new \LogicException('No project.'), 'github', (string) self::INSTALLATION, 'acme/app', ForgeRepositorySource::Installation, (string) self::INSTALLATION);
        $this->em()->persist($repository);
        $this->em()->flush();

        return $repository;
    }

    private function reader(): GitHubInitPullRequestReader
    {
        $client = new MockHttpClient(function (string $method, string $url): MockResponse {
            $this->requests[] = $method.' '.substr($url, \strlen('https://api.github.com'));

            return array_shift($this->responses) ?? throw new \LogicException('Unexpected request '.$method.' '.$url);
        }, 'https://api.github.com');
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => \OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($key);
        self::assertTrue(openssl_pkey_export($key, $pem));
        self::assertIsString($pem);

        $installations = self::getContainer()->get(GitHubRepositoryInstallations::class);
        self::assertInstanceOf(GitHubRepositoryInstallations::class, $installations);
        $settings = self::getContainer()->get(WorkflowSettings::class);
        self::assertInstanceOf(WorkflowSettings::class, $settings);

        return new GitHubInitPullRequestReader(
            new GitHubAppApi($client, new GitHubAppConfiguration(null, null, null, null, '123456', $pem), new MockClock('@1790000000')),
            $installations,
            $settings,
            new NullLogger(),
        );
    }

    private function file(string $content): MockResponse
    {
        return $this->answer(['type' => 'file', 'encoding' => 'base64', 'size' => \strlen($content), 'sha' => 'b10b5ha0000000000000000000000000000000aa', 'content' => base64_encode($content)]);
    }

    /** @param array<mixed> $body */
    private function answer(array $body, int $status = 200): MockResponse
    {
        return new MockResponse(json_encode($body, \JSON_THROW_ON_ERROR), ['http_code' => $status]);
    }
}
