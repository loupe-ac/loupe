<?php

declare(strict_types=1);

namespace App\Tests\Module\GitHub\Service;

use App\Module\Account\Entity\User;
use App\Module\Forge\Entity\ForgeRepository;
use App\Module\Forge\Entity\ForgeRepositorySource;
use App\Module\Forge\Service\PullRequestWriteFailed;
use App\Module\Forge\Service\RepositorySettingProposers;
use App\Module\GitHub\Entity\GitHubInstallation;
use App\Module\GitHub\Entity\GitHubRepositorySelection;
use App\Module\GitHub\Service\GitHubAppApi;
use App\Module\GitHub\Service\GitHubAppConfiguration;
use App\Module\GitHub\Service\GitHubRepositoryInstallations;
use App\Module\GitHub\Service\GitHubRepositorySettingProposer;
use App\Module\GitHub\Service\GitHubWorkflowFileReader;
use App\Module\Project\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class GitHubRepositorySettingProposerTest extends KernelTestCase
{
    private const int NOW = 1_790_000_000;
    private const string HEAD = 'a1b2c3d4e5f60718293a4b5c6d7e8f9012345678';
    private const string BLOB = 'b10b5ha0000000000000000000000000000000aa';
    private const string BRANCH_BLOB = 'b10b5ha1111111111111111111111111111111bb';
    private const string PULLS = 'https://api.github.com/repos/acme/app/pulls';

    private EntityManagerInterface $em;

    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
    private array $requests = [];

    /** @var list<MockResponse> */
    private array $responses = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
    }

    public function test_the_registry_hands_github_to_this_proposer(): void
    {
        $proposer = $this->proposer();

        self::assertSame($proposer, new RepositorySettingProposers([$proposer])->for('github'));
        self::assertNull(new RepositorySettingProposers([$proposer])->for('gitlab'));
    }

    public function test_it_opens_a_pull_request_with_the_requests_in_order(): void
    {
        $repository = $this->repository(74_001);
        $projectId = $repository->project->id?->toRfc4122();
        $this->responses = [
            ...$this->readAnswers("project: {$projectId}\n# keep\nworkflow:\n  merge-approval: agent\n"),
            $this->answer([]),
            $this->answer(['ref' => 'refs/heads/loupe/merge-approval-person'], 201),
            $this->answer(['content' => []], 200),
            $this->answer(['html_url' => 'https://github.com/acme/app/pull/9'], 201),
        ];

        $url = $this->proposer()->propose($repository, 'merge-approval', 'person', 'Set merge approval', 'The body');

        self::assertSame('https://github.com/acme/app/pull/9', $url);
        $calls = array_map(static fn (array $r): string => $r['method'].' '.$r['url'], $this->requests);
        self::assertSame([
            'POST https://api.github.com/app/installations/74001/access_tokens',
            'GET https://api.github.com/repos/acme/app',
            'GET https://api.github.com/repos/acme/app/branches/main',
            'GET https://api.github.com/repos/acme/app/contents/.loupe.yaml?ref='.self::HEAD,
            'GET '.self::PULLS.'?head=acme:loupe/merge-approval-person&base=main&state=open',
            'POST https://api.github.com/repos/acme/app/git/refs',
            'PUT https://api.github.com/repos/acme/app/contents/.loupe.yaml',
            'POST '.self::PULLS,
        ], $calls);
        self::assertSame(['ref' => 'refs/heads/loupe/merge-approval-person', 'sha' => self::HEAD], $this->json(5));
        self::assertSame([
            'message' => 'Set merge approval',
            'content' => base64_encode("project: {$projectId}\n# keep\nworkflow:\n  merge-approval: person\n"),
            'branch' => 'loupe/merge-approval-person',
            'sha' => self::BLOB,
        ], $this->json(6));
        self::assertSame(
            ['title' => 'Set merge approval', 'head' => 'loupe/merge-approval-person', 'base' => 'main', 'body' => 'The body', 'draft' => false],
            $this->json(7),
        );
    }

    public function test_a_missing_file_is_created_without_a_blob_sha(): void
    {
        $repository = $this->repository(74_002);
        $this->responses = [
            ...$this->readAnswers(null),
            $this->answer([]),
            $this->answer([], 201),
            $this->answer([], 201),
            $this->answer(['html_url' => 'https://github.com/acme/app/pull/10'], 201),
        ];

        $this->proposer()->propose($repository, 'merge-approval', 'person', 't', 'b');

        $put = $this->json(6);
        self::assertArrayNotHasKey('sha', $put);
        self::assertSame(
            'project: '.$repository->project->id?->toRfc4122()."\nworkflow:\n  merge-approval: person\n",
            base64_decode((string) $put['content'], true),
        );
    }

    /** @return iterable<string, array{array<mixed>}> */
    public static function unusableFiles(): iterable
    {
        yield 'too large' => [['type' => 'file', 'encoding' => 'base64', 'size' => GitHubWorkflowFileReader::MAX_BYTES + 1, 'sha' => self::BLOB, 'content' => '']];
        yield 'a directory' => [[['type' => 'file', 'name' => 'x']]];
        yield 'no content' => [['type' => 'file', 'encoding' => 'none', 'size' => 10, 'sha' => self::BLOB, 'content' => '']];
    }

    /** @param array<mixed> $file */
    #[DataProvider('unusableFiles')]
    public function test_an_existing_file_that_cannot_be_read_is_a_permanent_refusal_with_no_write(array $file): void
    {
        $repository = $this->repository(74_009);
        $this->responses = [
            $this->answer(['token' => 'ghs_token'], 201),
            $this->answer(['default_branch' => 'main']),
            $this->answer(['commit' => ['sha' => self::HEAD]]),
            $this->answer($file),
            $this->answer([]),
        ];

        $e = $this->failure(fn () => $this->proposer()->propose($repository, 'merge-approval', 'person', 't', 'b'));

        self::assertSame('file_unusable', $e->cause);
        self::assertTrue($e->permanent);
        self::assertSame([], array_filter($this->requests, static fn (array $r): bool => 'GET' !== $r['method'] && !str_ends_with($r['url'], '/access_tokens')));
    }

    public function test_an_existing_branch_is_written_with_the_blob_of_that_branch(): void
    {
        $repository = $this->repository(74_003);
        $this->responses = [
            ...$this->readAnswers("project: x\nworkflow:\n  merge-approval: agent\n"),
            $this->answer([]),
            $this->answer(['message' => 'Reference already exists'], 422),
            $this->fileAnswer("project: x\nworkflow:\n  merge-approval: old\n", self::BRANCH_BLOB),
            $this->answer([], 200),
            $this->answer(['html_url' => 'https://github.com/acme/app/pull/11'], 201),
        ];

        self::assertSame('https://github.com/acme/app/pull/11', $this->proposer()->propose($repository, 'merge-approval', 'person', 't', 'b'));
        self::assertCount(9, $this->requests);
        self::assertSame('GET https://api.github.com/repos/acme/app/contents/.loupe.yaml?ref=loupe/merge-approval-person', $this->requests[6]['method'].' '.$this->requests[6]['url']);
        self::assertSame(self::BRANCH_BLOB, $this->json(7)['sha']);
    }

    public function test_an_existing_branch_without_the_file_is_written_without_a_blob_sha(): void
    {
        $repository = $this->repository(74_007);
        $this->responses = [
            ...$this->readAnswers("project: x\nworkflow:\n  merge-approval: agent\n"),
            $this->answer([]),
            $this->answer(['message' => 'Reference already exists'], 422),
            $this->answer(['message' => 'Not Found'], 404),
            $this->answer([], 201),
            $this->answer(['html_url' => 'https://github.com/acme/app/pull/12'], 201),
        ];

        $this->proposer()->propose($repository, 'merge-approval', 'person', 't', 'b');

        self::assertArrayNotHasKey('sha', $this->json(7));
    }

    public function test_an_existing_branch_that_holds_the_value_opens_the_pull_request_with_no_write(): void
    {
        $repository = $this->repository(74_008);
        $this->responses = [
            ...$this->readAnswers("project: x\nworkflow:\n  merge-approval: agent\n"),
            $this->answer([]),
            $this->answer(['message' => 'Reference already exists'], 422),
            $this->fileAnswer("project: x\nworkflow:\n  merge-approval: person\n", self::BRANCH_BLOB),
            $this->answer(['html_url' => 'https://github.com/acme/app/pull/13'], 201),
        ];

        self::assertSame('https://github.com/acme/app/pull/13', $this->proposer()->propose($repository, 'merge-approval', 'person', 't', 'b'));
        self::assertSame([], array_filter($this->requests, static fn (array $r): bool => 'PUT' === $r['method']));
    }

    public function test_an_open_pull_request_is_returned_with_no_write(): void
    {
        $repository = $this->repository(74_004);
        $this->responses = [
            ...$this->readAnswers("project: x\nworkflow:\n  merge-approval: agent\n"),
            $this->answer([['html_url' => 'https://github.com/acme/app/pull/5']]),
        ];

        self::assertSame('https://github.com/acme/app/pull/5', $this->proposer()->propose($repository, 'merge-approval', 'person', 't', 'b'));
        self::assertSame([], array_filter($this->requests, static fn (array $r): bool => 'GET' !== $r['method'] && !str_ends_with($r['url'], '/access_tokens')));
    }

    public function test_a_file_that_has_the_value_already_is_refused_when_no_pull_request_is_open(): void
    {
        $repository = $this->repository(74_005);
        $this->responses = [
            ...$this->readAnswers("project: x\nworkflow:\n  merge-approval: person\n"),
            $this->answer([]),
        ];

        $e = $this->failure(fn () => $this->proposer()->propose($repository, 'merge-approval', 'person', 't', 'b'));

        self::assertSame('already_set', $e->cause);
        self::assertTrue($e->permanent);
    }

    public function test_a_refused_api_call_is_a_permanent_permission_failure(): void
    {
        $repository = $this->repository(74_006);
        $this->responses = [
            $this->answer(['token' => 'ghs_token'], 201),
            $this->answer(['message' => 'Resource not accessible by integration'], 403),
        ];

        $e = $this->failure(fn () => $this->proposer()->propose($repository, 'merge-approval', 'person', 't', 'b'));

        self::assertSame('permission', $e->cause);
        self::assertTrue($e->permanent);
    }

    public function test_a_repository_without_an_installation_makes_no_request(): void
    {
        $project = $this->project();
        $repository = new ForgeRepository($project, 'github', 'x', 'acme/app', ForgeRepositorySource::Installation, '1');
        $this->em->persist($repository);
        $this->em->flush();

        $e = $this->failure(fn () => $this->proposer()->propose($repository, 'merge-approval', 'person', 't', 'b'));

        self::assertSame('no_installation', $e->cause);
        self::assertSame([], $this->requests);
    }

    /** @return list<MockResponse> the answers of the token, the repository, the branch and the file */
    private function readAnswers(?string $content): array
    {
        return [
            $this->answer(['token' => 'ghs_token'], 201),
            $this->answer(['default_branch' => 'main']),
            $this->answer(['commit' => ['sha' => self::HEAD]]),
            null === $content
                ? $this->answer(['message' => 'Not Found'], 404)
                : $this->fileAnswer($content, self::BLOB),
        ];
    }

    private function fileAnswer(string $content, string $blob): MockResponse
    {
        return $this->answer(['type' => 'file', 'encoding' => 'base64', 'size' => \strlen($content), 'sha' => $blob, 'content' => base64_encode($content)]);
    }

    private function repository(int $installationId): ForgeRepository
    {
        $project = $this->project();
        $this->em->persist(new GitHubInstallation($project, $installationId, 'acme', GitHubRepositorySelection::Selected));
        $repository = new ForgeRepository($project, 'github', (string) $installationId, 'acme/app', ForgeRepositorySource::Installation, (string) $installationId);
        $this->em->persist($repository);
        $this->em->flush();

        return $repository;
    }

    private function project(): Project
    {
        $user = new User(fullName: 'Riley', email: 'proposer-'.uniqid().'@example.com', password: 'hashed');
        $project = new Project($user, 'proposer-'.uniqid());
        $this->em->persist($user);
        $this->em->persist($project);
        $this->em->flush();

        return $project;
    }

    private function proposer(): GitHubRepositorySettingProposer
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $this->requests[] = ['method' => $method, 'url' => $url, 'options' => $options];

            return array_shift($this->responses) ?? throw new \LogicException('Unexpected request '.$method.' '.$url);
        }, 'https://api.github.com');
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => \OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($key);
        self::assertTrue(openssl_pkey_export($key, $pem));
        self::assertIsString($pem);

        $installations = self::getContainer()->get(GitHubRepositoryInstallations::class);
        self::assertInstanceOf(GitHubRepositoryInstallations::class, $installations);
        $api = new GitHubAppApi($client, new GitHubAppConfiguration(null, null, null, null, '123456', $pem), new MockClock('@'.self::NOW));

        return new GitHubRepositorySettingProposer($api, $installations, new GitHubWorkflowFileReader($installations, $api));
    }

    private function failure(\Closure $call): PullRequestWriteFailed
    {
        try {
            $call();
        } catch (PullRequestWriteFailed $e) {
            return $e;
        }

        self::fail('Expected PullRequestWriteFailed.');
    }

    /** @return array<string, mixed> */
    private function json(int $request): array
    {
        $body = $this->requests[$request]['options']['body'] ?? null;
        self::assertIsString($body);

        return json_decode($body, true, flags: \JSON_THROW_ON_ERROR);
    }

    /** @param array<mixed> $body */
    private function answer(array $body, int $status = 200): MockResponse
    {
        return new MockResponse(json_encode($body, \JSON_THROW_ON_ERROR), ['http_code' => $status]);
    }
}
