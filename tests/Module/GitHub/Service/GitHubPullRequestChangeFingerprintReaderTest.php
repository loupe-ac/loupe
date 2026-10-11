<?php

declare(strict_types=1);

namespace App\Tests\Module\GitHub\Service;

use App\Module\Account\Entity\User;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Entity\ForgeRepository;
use App\Module\Forge\Entity\ForgeRepositorySource;
use App\Module\Forge\Service\PullRequestChangeFingerprint;
use App\Module\Forge\Service\PullRequestChangeFingerprintReaders;
use App\Module\GitHub\Entity\GitHubInstallation;
use App\Module\GitHub\Entity\GitHubRepositorySelection;
use App\Module\GitHub\Service\GitHubAppApi;
use App\Module\GitHub\Service\GitHubAppConfiguration;
use App\Module\GitHub\Service\GitHubPullRequestChangeFingerprintReader;
use App\Module\GitHub\Service\GitHubPullRequestInstallations;
use App\Module\Project\Entity\Project;
use App\Tests\Support\RecordingLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class GitHubPullRequestChangeFingerprintReaderTest extends KernelTestCase
{
    private const string HEAD = 'ffffffffffffffffffffffffffffffffffffffff';

    private const string PATCH = "@@ -10,3 +10,4 @@ final class Widget\n context\n-old line\n+new line\n+added line\n context";

    private EntityManagerInterface $em;
    private RecordingLogger $logger;

    /** @var list<string> */
    private array $urls = [];

    /** @var list<MockResponse> */
    private array $responses = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->logger = new RecordingLogger();
    }

    public function test_the_registry_hands_github_pull_requests_to_this_reader(): void
    {
        $readers = self::getContainer()->get(PullRequestChangeFingerprintReaders::class);
        self::assertInstanceOf(PullRequestChangeFingerprintReaders::class, $readers);

        self::assertInstanceOf(GitHubPullRequestChangeFingerprintReader::class, $readers->for('github'));
    }

    public function test_it_compares_the_base_branch_with_the_head_once(): void
    {
        $pullRequest = $this->tracked('Ubermuda/Loupe', 72_001);

        $fingerprint = $this->read($pullRequest, [$this->file('src/Widget.php', self::PATCH)]);

        self::assertNotNull($fingerprint);
        self::assertSame(64, \strlen($fingerprint->change));
        self::assertSame(64, \strlen($fingerprint->lines));
        self::assertSame(['https://api.github.com/repos/Ubermuda/Loupe/compare/release%2F1.x...'.self::HEAD], \array_slice($this->urls, 1));
    }

    public function test_a_clean_merge_keeps_the_change_and_the_lines(): void
    {
        $pullRequest = $this->tracked('ubermuda/loupe', 72_002);

        $before = $this->read($pullRequest, [$this->file('src/Widget.php', self::PATCH)]);
        $after = $this->read($pullRequest, [$this->file('src/Widget.php', self::PATCH)]);

        self::assertNotNull($before);
        self::assertEquals($before, $after);
    }

    public function test_a_merge_that_moves_lines_keeps_the_change_and_not_the_lines(): void
    {
        $pullRequest = $this->tracked('ubermuda/loupe', 72_003);
        $moved = str_replace('@@ -10,3 +10,4 @@ final class Widget', '@@ -14,3 +14,4 @@ final class Widget', self::PATCH);

        $before = $this->read($pullRequest, [$this->file('src/Widget.php', self::PATCH)]);
        $after = $this->read($pullRequest, [$this->file('src/Widget.php', $moved)]);

        self::assertNotNull($before);
        self::assertNotNull($after);
        self::assertSame($before->change, $after->change);
        self::assertNotSame($before->lines, $after->lines);
    }

    public function test_a_hand_resolved_conflict_changes_the_change(): void
    {
        $pullRequest = $this->tracked('ubermuda/loupe', 72_004);
        $resolved = str_replace('+new line', '+resolved line', self::PATCH);

        $before = $this->read($pullRequest, [$this->file('src/Widget.php', self::PATCH)]);
        $after = $this->read($pullRequest, [$this->file('src/Widget.php', $resolved)]);

        self::assertNotNull($before);
        self::assertNotNull($after);
        self::assertNotSame($before->change, $after->change);
        self::assertNotSame($before->lines, $after->lines);
    }

    public function test_the_previous_name_of_a_renamed_file_is_part_of_the_change(): void
    {
        $pullRequest = $this->tracked('ubermuda/loupe', 72_005);

        $fromOld = $this->read($pullRequest, [$this->file('src/Widget.php', self::PATCH, 'renamed', 'src/Old.php')]);
        $fromOther = $this->read($pullRequest, [$this->file('src/Widget.php', self::PATCH, 'renamed', 'src/Other.php')]);

        self::assertNotNull($fromOld);
        self::assertNotNull($fromOther);
        self::assertNotSame($fromOld->change, $fromOther->change);
    }

    public function test_a_file_with_no_patch_is_known_by_its_blob(): void
    {
        $pullRequest = $this->tracked('ubermuda/loupe', 72_006);

        $first = $this->read($pullRequest, [$this->file('logo.png', sha: 'blob1')]);
        $same = $this->read($pullRequest, [$this->file('logo.png', sha: 'blob1')]);
        $other = $this->read($pullRequest, [$this->file('logo.png', sha: 'blob2')]);

        self::assertNotNull($first);
        self::assertNotNull($other);
        self::assertEquals($first, $same);
        self::assertNotSame($first->change, $other->change);
        self::assertNotSame($first->lines, $other->lines);
    }

    public function test_the_order_of_the_files_does_not_matter(): void
    {
        $pullRequest = $this->tracked('ubermuda/loupe', 72_007);
        $files = [$this->file('9.php', self::PATCH), $this->file('10.php', self::PATCH), $this->file('a.png', sha: 'blob1')];

        $forward = $this->read($pullRequest, $files);
        $backward = $this->read($pullRequest, array_reverse($files));

        self::assertNotNull($forward);
        self::assertEquals($forward, $backward);
    }

    public function test_a_list_of_300_files_gives_no_fingerprint(): void
    {
        $pullRequest = $this->tracked('ubermuda/loupe', 72_008);
        $files = array_map(fn (int $index): array => $this->file('src/F'.$index.'.php', self::PATCH), range(1, 300));

        self::assertNull($this->read($pullRequest, $files));
        self::assertNotNull($this->read($pullRequest, \array_slice($files, 0, 299)));
    }

    public function test_a_failed_compare_gives_no_fingerprint_and_is_logged(): void
    {
        $pullRequest = $this->tracked('ubermuda/loupe', 72_009);
        $this->answers(new MockResponse('{"message":"Server Error"}', ['http_code' => 500]));

        self::assertNull($this->reader()->read($pullRequest, self::HEAD));
        self::assertSame('forge.change_fingerprint_unreadable', $this->logger->records[0]['message']);
        self::assertSame('http_status', $this->logger->records[0]['context']['reason']);
        self::assertSame((string) $pullRequest->id, $this->logger->records[0]['context']['pullRequestId']);
        self::assertSame('ubermuda/loupe', $this->logger->records[0]['context']['repository']);
    }

    public function test_a_malformed_compare_gives_no_fingerprint_and_is_logged(): void
    {
        $bodies = [
            ['status' => 'ahead'],
            ['files' => ['src' => ['filename' => 'a', 'status' => 'added', 'patch' => 'x']]],
            ['files' => [['status' => 'added', 'patch' => 'x']]],
            ['files' => [['filename' => 'a', 'patch' => 'x']]],
            ['files' => [['filename' => 'a', 'status' => 'added']]],
            ['files' => ['not a file']],
        ];
        foreach ($bodies as $offset => $body) {
            $pullRequest = $this->tracked('ubermuda/loupe', 72_010 + $offset);
            $this->logger = new RecordingLogger();
            $this->answers($this->answer($body));

            self::assertNull($this->reader()->read($pullRequest, self::HEAD), (string) $offset);
            self::assertSame('malformed_body', $this->logger->records[0]['context']['reason'], (string) $offset);
        }
    }

    public function test_a_pull_request_without_a_base_or_a_head_is_not_compared(): void
    {
        $pullRequest = $this->tracked('ubermuda/loupe', 72_020);

        self::assertNull($this->reader()->read($pullRequest, ''));
        $pullRequest->baseBranch = null;
        self::assertNull($this->reader()->read($pullRequest, self::HEAD));
        $pullRequest->baseBranch = '';
        self::assertNull($this->reader()->read($pullRequest, self::HEAD));
        self::assertSame([], $this->urls);
        self::assertSame([], $this->logger->records);
    }

    /** @param list<array<string, mixed>> $files */
    private function read(ForgePullRequest $pullRequest, array $files): ?PullRequestChangeFingerprint
    {
        $this->answers($this->answer(['status' => 'ahead', 'files' => $files]));

        return $this->reader()->read($pullRequest, self::HEAD);
    }

    /** @return array<string, mixed> */
    private function file(string $filename, ?string $patch = null, string $status = 'modified', ?string $previous = null, string $sha = 'blob0'): array
    {
        $file = ['sha' => $sha, 'filename' => $filename, 'status' => $status];
        if (null !== $patch) {
            $file['patch'] = $patch;
        }
        if (null !== $previous) {
            $file['previous_filename'] = $previous;
        }

        return $file;
    }

    private function tracked(string $path, int $installationId): ForgePullRequest
    {
        $user = new User(fullName: 'Riley', email: 'fingerprint-'.uniqid().'@example.com', password: 'hashed');
        $project = new Project($user, 'fingerprint-'.uniqid());
        $this->em->persist($user);
        $this->em->persist($project);
        $this->em->persist(new GitHubInstallation($project, $installationId, 'ubermuda', GitHubRepositorySelection::Selected));
        $this->em->persist(new ForgeRepository($project, 'github', (string) $installationId, $path, ForgeRepositorySource::Installation, (string) $installationId));
        $pullRequest = new ForgePullRequest($project, 'github', $path, 604);
        $pullRequest->baseBranch = 'release/1.x';
        $pullRequest->headSha = self::HEAD;
        $this->em->persist($pullRequest);
        $this->em->flush();

        return $pullRequest;
    }

    /** A new API each time, because GitHubAppApi keeps the installation token after the first call. */
    private function reader(): GitHubPullRequestChangeFingerprintReader
    {
        $client = new MockHttpClient(function (string $method, string $url): MockResponse {
            $this->urls[] = $url;

            return array_shift($this->responses) ?? throw new \LogicException('Unexpected request '.$method.' '.$url);
        }, 'https://api.github.com');
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => \OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($key);
        self::assertTrue(openssl_pkey_export($key, $pem));
        self::assertIsString($pem);

        $installations = self::getContainer()->get(GitHubPullRequestInstallations::class);
        self::assertInstanceOf(GitHubPullRequestInstallations::class, $installations);

        return new GitHubPullRequestChangeFingerprintReader(
            new GitHubAppApi($client, new GitHubAppConfiguration(null, null, null, null, '123456', $pem), new MockClock()),
            $installations,
            $this->logger,
        );
    }

    private function answers(MockResponse $compare): void
    {
        $this->urls = [];
        $this->responses = [$this->answer(['token' => 'ghs_token'], 201), $compare];
    }

    /** @param array<mixed> $body */
    private function answer(array $body, int $status = 200): MockResponse
    {
        return new MockResponse(json_encode($body, \JSON_THROW_ON_ERROR), ['http_code' => $status]);
    }
}
