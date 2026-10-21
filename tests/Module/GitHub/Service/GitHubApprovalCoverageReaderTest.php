<?php

declare(strict_types=1);

namespace App\Tests\Module\GitHub\Service;

use App\Module\Account\Entity\User;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Entity\ForgeRepository;
use App\Module\Forge\Entity\ForgeRepositorySource;
use App\Module\Forge\Service\ApprovalCoverage;
use App\Module\Forge\Service\ApprovalCoverageReaders;
use App\Module\GitHub\Entity\GitHubInstallation;
use App\Module\GitHub\Entity\GitHubRepositorySelection;
use App\Module\GitHub\Service\GitHubAppApi;
use App\Module\GitHub\Service\GitHubAppConfiguration;
use App\Module\GitHub\Service\GitHubApprovalCoverageReader;
use App\Module\GitHub\Service\GitHubPullRequestInstallations;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\ApprovalRules;
use App\Tests\Support\RecordingLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Uid\Uuid;

final class GitHubApprovalCoverageReaderTest extends KernelTestCase
{
    private const string COVERED = 'c0c0c0c0c0c0c0c0c0c0c0c0c0c0c0c0c0c0c0c0';
    private const string HEAD = 'ffffffffffffffffffffffffffffffffffffffff';

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
        $readers = self::getContainer()->get(ApprovalCoverageReaders::class);
        self::assertInstanceOf(ApprovalCoverageReaders::class, $readers);

        self::assertInstanceOf(GitHubApprovalCoverageReader::class, $readers->for('github'));
    }

    public function test_one_merge_from_the_base_is_covered(): void
    {
        $pullRequest = $this->tracked('Loupe-AC/Loupe', 71_001);
        $this->answers(
            $this->compare('ahead', [$this->commit('b1', 'b0'), $this->commit(self::HEAD, self::COVERED, 'b1')]),
            $this->compare('ahead', [$this->commit('p1', 'b0'), $this->commit(self::COVERED, 'p1'), $this->commit(self::HEAD, self::COVERED, 'b1')]),
        );

        self::assertSame(ApprovalCoverage::Covered, $this->reader()->read($pullRequest));
        self::assertSame([
            'https://api.github.com/repos/Loupe-AC/Loupe/compare/'.self::COVERED.'...'.self::HEAD,
            'https://api.github.com/repos/Loupe-AC/Loupe/compare/release%2F1.x...'.self::HEAD,
        ], \array_slice($this->urls, 1));
    }

    public function test_two_merges_from_the_base_in_one_push_are_covered(): void
    {
        $pullRequest = $this->tracked('loupe-ac/loupe', 71_002);
        $this->answers(
            $this->compare('ahead', [$this->commit('b1', 'b0'), $this->commit('m1', self::COVERED, 'b1'), $this->commit('b2', 'b1'), $this->commit(self::HEAD, 'm1', 'b2')]),
            $this->compare('ahead', [$this->commit(self::COVERED, 'b0'), $this->commit('m1', self::COVERED, 'b1'), $this->commit(self::HEAD, 'm1', 'b2')]),
        );

        self::assertSame(ApprovalCoverage::Covered, $this->reader()->read($pullRequest));
    }

    public function test_a_merge_that_resolved_a_conflict_is_covered(): void
    {
        $pullRequest = $this->tracked('loupe-ac/loupe', 71_003);
        $this->answers(
            $this->compare('ahead', [$this->commit('b1', 'b0'), $this->commit('b2', 'b1'), $this->commit(self::HEAD, self::COVERED, 'b2')]),
            $this->compare('ahead', [$this->commit(self::COVERED, 'b0'), $this->commit(self::HEAD, self::COVERED, 'b2')]),
        );

        self::assertSame(ApprovalCoverage::Covered, $this->reader()->read($pullRequest));
    }

    public function test_a_clean_merge_is_covered_when_the_rule_holds_a_conflict_resolution(): void
    {
        $pullRequest = $this->tracked('loupe-ac/loupe', 71_020);
        $this->answers(...$this->mergeAnswers(
            headChanged: ['a.php'],
            baseChanged: ['b.php'],
            againstHead: ['b.php'],
            againstBase: ['a.php'],
        ));

        self::assertSame(ApprovalCoverage::Covered, $this->reader($this->strictRules(true))->read($pullRequest));
        // Two compares to list the commits, then the merge base and four file lists for the one merge.
        self::assertCount(7, \array_slice($this->urls, 1));
    }

    public function test_a_merge_that_changed_a_file_both_sides_changed_is_not_covered_when_the_rule_holds(): void
    {
        $pullRequest = $this->tracked('loupe-ac/loupe', 71_021);
        $this->answers(...$this->mergeAnswers(
            headChanged: ['a.php', 'c.php'],
            baseChanged: ['b.php', 'c.php'],
            againstHead: ['b.php', 'c.php'],
            againstBase: ['a.php', 'c.php'],
        ));

        self::assertSame(ApprovalCoverage::NotCovered, $this->reader($this->strictRules(true))->read($pullRequest));
    }

    public function test_a_merge_that_rewrote_a_file_only_one_side_changed_is_not_covered_when_the_rule_holds(): void
    {
        $pullRequest = $this->tracked('loupe-ac/loupe', 71_022);
        $this->answers(...$this->mergeAnswers(
            headChanged: ['a.php'],
            baseChanged: ['b.php'],
            againstHead: ['b.php', 'a.php'],
            againstBase: ['a.php'],
        ));

        self::assertSame(ApprovalCoverage::NotCovered, $this->reader($this->strictRules(true))->read($pullRequest));
    }

    public function test_a_merge_that_edited_the_base_side_files_is_not_covered_when_the_rule_holds(): void
    {
        $pullRequest = $this->tracked('loupe-ac/loupe', 71_023);
        $this->answers(...$this->mergeAnswers(
            headChanged: ['a.php'],
            baseChanged: ['b.php'],
            againstHead: ['b.php'],
            againstBase: ['a.php', 'b.php'],
        ));

        self::assertSame(ApprovalCoverage::NotCovered, $this->reader($this->strictRules(true))->read($pullRequest));
    }

    public function test_the_same_conflicting_merge_is_covered_when_the_rule_lets_an_approval_cover_it(): void
    {
        $pullRequest = $this->tracked('loupe-ac/loupe', 71_024);
        $this->answers(...\array_slice($this->mergeAnswers(
            headChanged: ['a.php', 'c.php'],
            baseChanged: ['b.php', 'c.php'],
            againstHead: ['b.php', 'c.php'],
            againstBase: ['a.php', 'c.php'],
        ), 0, 2));

        self::assertSame(ApprovalCoverage::Covered, $this->reader($this->strictRules(false))->read($pullRequest));
        self::assertCount(2, \array_slice($this->urls, 1));
    }

    public function test_a_renamed_file_counts_under_both_names(): void
    {
        $pullRequest = $this->tracked('loupe-ac/loupe', 71_025);
        $this->answers(...$this->mergeAnswers(
            headChanged: [['a.php', 'old.php']],
            baseChanged: ['old.php'],
            againstHead: [],
            againstBase: [],
        ));

        self::assertSame(ApprovalCoverage::NotCovered, $this->reader($this->strictRules(true))->read($pullRequest));
    }

    public function test_a_cut_file_list_is_not_covered_when_the_rule_holds(): void
    {
        $pullRequest = $this->tracked('loupe-ac/loupe', 71_026);
        $many = array_map(static fn (int $i): string => 'f'.$i.'.php', range(1, 300));
        $this->answers(...$this->mergeAnswers(
            headChanged: ['a.php'],
            baseChanged: $many,
            againstHead: [],
            againstBase: ['a.php'],
        ));

        self::assertSame(ApprovalCoverage::NotCovered, $this->reader($this->strictRules(true))->read($pullRequest));
    }

    public function test_a_merge_with_three_parents_is_not_covered_when_the_rule_holds(): void
    {
        $pullRequest = $this->tracked('loupe-ac/loupe', 71_027);
        $this->answers(
            $this->compare('ahead', [$this->commit('b1', 'b0'), $this->commit(self::HEAD, self::COVERED, 'b1', 'b2')]),
            $this->compare('ahead', [$this->commit(self::COVERED, 'b0'), $this->commit(self::HEAD, self::COVERED, 'b1', 'b2')]),
        );

        self::assertSame(ApprovalCoverage::NotCovered, $this->reader($this->strictRules(true))->read($pullRequest));
    }

    public function test_a_malformed_file_list_is_unknown_when_the_rule_holds(): void
    {
        $pullRequest = $this->tracked('loupe-ac/loupe', 71_028);
        $this->answers(
            $this->compare('ahead', [$this->commit('b1', 'b0'), $this->commit(self::HEAD, self::COVERED, 'b1')]),
            $this->compare('ahead', [$this->commit(self::COVERED, 'b0'), $this->commit(self::HEAD, self::COVERED, 'b1')]),
            $this->answer(['merge_base_commit' => ['sha' => 'mb'], 'files' => 'none']),
        );

        self::assertSame(ApprovalCoverage::Unknown, $this->reader($this->strictRules(true))->read($pullRequest));
        self::assertSame('malformed_body', $this->logger->records[0]['context']['reason']);
    }

    public function test_an_identical_head_is_covered(): void
    {
        $pullRequest = $this->tracked('loupe-ac/loupe', 71_004);
        $this->answers($this->compare('identical', []));

        self::assertSame(ApprovalCoverage::Covered, $this->reader()->read($pullRequest));
    }

    public function test_a_content_commit_is_not_covered(): void
    {
        $pullRequest = $this->tracked('loupe-ac/loupe', 71_005);
        $this->answers(
            $this->compare('ahead', [$this->commit(self::HEAD, self::COVERED)]),
            $this->compare('ahead', [$this->commit(self::COVERED, 'b0'), $this->commit(self::HEAD, self::COVERED)]),
        );

        self::assertSame(ApprovalCoverage::NotCovered, $this->reader()->read($pullRequest));
    }

    public function test_a_merge_then_a_content_commit_is_not_covered(): void
    {
        $pullRequest = $this->tracked('loupe-ac/loupe', 71_006);
        $this->answers(
            $this->compare('ahead', [$this->commit('b1', 'b0'), $this->commit('m1', self::COVERED, 'b1'), $this->commit(self::HEAD, 'm1')]),
            $this->compare('ahead', [$this->commit(self::COVERED, 'b0'), $this->commit('m1', self::COVERED, 'b1'), $this->commit(self::HEAD, 'm1')]),
        );

        self::assertSame(ApprovalCoverage::NotCovered, $this->reader()->read($pullRequest));
    }

    public function test_a_merge_of_a_commit_that_is_not_on_the_base_is_not_covered(): void
    {
        $pullRequest = $this->tracked('loupe-ac/loupe', 71_007);
        $this->answers(
            $this->compare('ahead', [$this->commit(self::HEAD, self::COVERED, 'f1')]),
            $this->compare('ahead', [$this->commit('f1', 'b0'), $this->commit(self::COVERED, 'b0'), $this->commit(self::HEAD, self::COVERED, 'f1')]),
        );

        self::assertSame(ApprovalCoverage::NotCovered, $this->reader()->read($pullRequest));
    }

    public function test_a_force_push_is_not_covered(): void
    {
        foreach (['diverged', 'behind'] as $offset => $status) {
            $pullRequest = $this->tracked('loupe-ac/loupe', 71_010 + $offset);
            $this->answers($this->compare($status, [$this->commit(self::HEAD, 'b0')]));

            self::assertSame(ApprovalCoverage::NotCovered, $this->reader()->read($pullRequest), $status);
        }
    }

    public function test_a_cut_list_is_not_covered(): void
    {
        $pullRequest = $this->tracked('loupe-ac/loupe', 71_012);
        $this->answers($this->compare('ahead', [$this->commit(self::HEAD, self::COVERED, 'b1')], total: 300));

        self::assertSame(ApprovalCoverage::NotCovered, $this->reader()->read($pullRequest));

        $pullRequest = $this->tracked('loupe-ac/loupe', 71_013);
        $this->answers(
            $this->compare('ahead', [$this->commit(self::HEAD, self::COVERED, 'b1')]),
            $this->compare('ahead', [$this->commit(self::HEAD, self::COVERED, 'b1')], total: 300),
        );

        self::assertSame(ApprovalCoverage::NotCovered, $this->reader()->read($pullRequest));
    }

    public function test_a_failed_compare_is_unknown_and_logged(): void
    {
        $pullRequest = $this->tracked('loupe-ac/loupe', 71_014);
        $this->answers(
            $this->compare('ahead', [$this->commit(self::HEAD, self::COVERED, 'b1')]),
            new MockResponse('{"message":"Server Error"}', ['http_code' => 500]),
        );

        self::assertSame(ApprovalCoverage::Unknown, $this->reader()->read($pullRequest));
        self::assertSame('forge.approval_coverage_unreadable', $this->logger->records[0]['message']);
        self::assertSame('http_status', $this->logger->records[0]['context']['reason']);
    }

    public function test_a_malformed_compare_is_unknown(): void
    {
        $pullRequest = $this->tracked('loupe-ac/loupe', 71_015);
        $this->answers($this->answer(['status' => 'ahead', 'total_commits' => 1, 'commits' => [['sha' => self::HEAD, 'parents' => 'none']]]));

        self::assertSame(ApprovalCoverage::Unknown, $this->reader()->read($pullRequest));
        self::assertSame('malformed_body', $this->logger->records[0]['context']['reason']);
    }

    public function test_a_pull_request_without_an_installation_is_unknown(): void
    {
        $project = $this->project();
        $pullRequest = new ForgePullRequest($project, 'github', 'loupe-ac/loupe', 604);
        $this->facts($pullRequest);
        $this->em->persist($pullRequest);
        $this->em->flush();

        self::assertSame(ApprovalCoverage::Unknown, $this->reader()->read($pullRequest));
        self::assertSame('no_installation', $this->logger->records[0]['context']['reason']);
        self::assertSame([], $this->urls);
    }

    public function test_a_pull_request_without_the_facts_to_compare_is_unknown(): void
    {
        $pullRequest = $this->tracked('loupe-ac/loupe', 71_016);
        $pullRequest->baseBranch = null;

        self::assertSame(ApprovalCoverage::Unknown, $this->reader()->read($pullRequest));
        self::assertSame([], $this->urls);
    }

    private function tracked(string $path, int $installationId): ForgePullRequest
    {
        $project = $this->project();
        $this->em->persist(new GitHubInstallation($project, $installationId, 'loupe-ac', GitHubRepositorySelection::Selected));
        $this->em->persist(new ForgeRepository($project, 'github', (string) $installationId, $path, ForgeRepositorySource::Installation, (string) $installationId));
        $pullRequest = new ForgePullRequest($project, 'github', $path, 604);
        $this->facts($pullRequest);
        $this->em->persist($pullRequest);
        $this->em->flush();

        return $pullRequest;
    }

    private function facts(ForgePullRequest $pullRequest): void
    {
        $pullRequest->baseBranch = 'release/1.x';
        $pullRequest->coveredSha = self::COVERED;
        $pullRequest->headSha = self::HEAD;
    }

    private function project(): Project
    {
        $user = new User(fullName: 'Riley', email: 'coverage-'.uniqid().'@example.com', password: 'hashed');
        $project = new Project($user, 'coverage-'.uniqid());
        $this->em->persist($user);
        $this->em->persist($project);

        return $project;
    }

    private function reader(?ApprovalRules $rules = null): GitHubApprovalCoverageReader
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

        return new GitHubApprovalCoverageReader(
            new GitHubAppApi($client, new GitHubAppConfiguration(null, null, null, null, '123456', $pem), new MockClock()),
            $installations,
            $this->logger,
            $rules ?? $this->strictRules(false),
        );
    }

    /** Queues the token answer, then the compare answers in the order the reader asks. */
    private function answers(MockResponse ...$compares): void
    {
        $this->urls = [];
        $this->responses = [$this->answer(['token' => 'ghs_token'], 201), ...array_values($compares)];
    }

    /** @param list<array<string, mixed>> $commits */
    private function compare(string $status, array $commits, ?int $total = null): MockResponse
    {
        return $this->answer(['status' => $status, 'total_commits' => $total ?? \count($commits), 'commits' => $commits]);
    }

    /** @return array<string, mixed> */
    private function commit(string $sha, string ...$parents): array
    {
        return ['sha' => $sha, 'parents' => array_map(static fn (string $parent): array => ['sha' => $parent], array_values($parents))];
    }

    /**
     * The compare answers for one clean merge of the base into the head, in the order the reader asks.
     *
     * @param list<string|array{string, string}> $headChanged
     * @param list<string|array{string, string}> $baseChanged
     * @param list<string|array{string, string}> $againstHead
     * @param list<string|array{string, string}> $againstBase
     *
     * @return list<MockResponse>
     */
    private function mergeAnswers(array $headChanged, array $baseChanged, array $againstHead, array $againstBase): array
    {
        return [
            $this->compare('ahead', [$this->commit('b1', 'b0'), $this->commit(self::HEAD, self::COVERED, 'b1')]),
            $this->compare('ahead', [$this->commit(self::COVERED, 'b0'), $this->commit(self::HEAD, self::COVERED, 'b1')]),
            $this->answer(['merge_base_commit' => ['sha' => 'mb'], 'files' => []]),
            $this->files($headChanged),
            $this->files($baseChanged),
            $this->files($againstHead),
            $this->files($againstBase),
        ];
    }

    /** @param list<string|array{string, string}> $names a renamed file is a pair of its new and its old name */
    private function files(array $names): MockResponse
    {
        return $this->answer(['merge_base_commit' => ['sha' => 'mb'], 'files' => array_map(
            static fn (string|array $name): array => \is_string($name) ? ['filename' => $name] : ['filename' => $name[0], 'previous_filename' => $name[1]],
            $names,
        )]);
    }

    /** @param bool $strict true when the template says an approval does not cover a conflict resolution */
    private function strictRules(bool $strict): ApprovalRules
    {
        return new readonly class($strict) implements ApprovalRules {
            public function __construct(
                private bool $strict,
            ) {
            }

            #[\Override]
            public function coversConflictResolution(Uuid $projectId): bool
            {
                return !$this->strict;
            }
        };
    }

    /** @param array<mixed> $body */
    private function answer(array $body, int $status = 200): MockResponse
    {
        return new MockResponse(json_encode($body, \JSON_THROW_ON_ERROR), ['http_code' => $status]);
    }
}
