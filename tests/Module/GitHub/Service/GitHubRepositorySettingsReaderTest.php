<?php

declare(strict_types=1);

namespace App\Tests\Module\GitHub\Service;

use App\Module\Account\Entity\User;
use App\Module\Forge\Entity\ForgeRepository;
use App\Module\Forge\Entity\ForgeRepositorySource;
use App\Module\Forge\Service\RepositorySettingsReaders;
use App\Module\Forge\Service\RepositorySettingsUnreadable;
use App\Module\Forge\Settings\RepositorySettings;
use App\Module\GitHub\Entity\GitHubInstallation;
use App\Module\GitHub\Entity\GitHubRepositorySelection;
use App\Module\GitHub\Repository\GitHubInstallationRepository;
use App\Module\GitHub\Service\GitHubAppApi;
use App\Module\GitHub\Service\GitHubAppConfiguration;
use App\Module\GitHub\Service\GitHubRepositoryInstallations;
use App\Module\GitHub\Service\GitHubRepositorySettingsReader;
use App\Module\Project\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Every GitHub answer here is a mock shaped like GitHub's. No live repository
 * was read, because a live run would change a repository.
 */
final class GitHubRepositorySettingsReaderTest extends KernelTestCase
{
    private const string APP_ID = '123456';

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

    public function test_the_registry_hands_github_repositories_to_this_reader(): void
    {
        $reader = $this->reader();
        $readers = new RepositorySettingsReaders([$reader]);

        self::assertSame($reader, $readers->for('github'));
        self::assertNull($readers->for('gitlab'));
    }

    public function test_it_reads_the_settings_with_get_requests_alone(): void
    {
        $repository = $this->installed('loupe-ac/loupe', 80_001);
        $this->responses = [
            $this->answer(['token' => 'ghs_token'], 201),
            $this->answer(['default_branch' => 'main', 'allow_squash_merge' => true, 'allow_merge_commit' => false, 'allow_rebase_merge' => true]),
            $this->answer($this->rules()),
            $this->answer(['id' => 101, 'name' => 'Main gate']),
            $this->answer(['id' => 7, 'name' => 'Org floor']),
            $this->answer($this->branch(false)),
            $this->answer($this->checksAnswer([
                [['__typename' => 'CheckRun', 'name' => 'lint'], ['__typename' => 'StatusContext', 'context' => 'ci/legacy'], ['__typename' => 'CheckRun', 'name' => 'Loupe / Approval']],
                [['__typename' => 'CheckRun', 'name' => 'lint'], ['__typename' => 'CheckRun', 'name' => '  e2e {a} ']],
            ])),
        ];

        $settings = $this->reader()->read($repository);

        self::assertSame('main', $settings->defaultBranch);
        self::assertSame(['squash', 'rebase'], $settings->mergeMethods);
        self::assertSame(123_456, $settings->loupeAppId);
        self::assertTrue($settings->rules->pullRequestRule);
        self::assertFalse($settings->rules->requiresReview);
        self::assertSame(['squash', 'rebase'], $settings->rules->allowedMergeMethods);
        self::assertSame(['Loupe / Approval', 'ci'], $settings->rules->requiredChecks);
        self::assertSame([123_456], $settings->rules->approvalSources);
        self::assertTrue($settings->rules->forbidsForcePush);
        self::assertTrue($settings->rules->forbidsDeletion);
        self::assertCount(2, $settings->rulesets);
        self::assertSame([101, 'Main gate', 'Repository', 'loupe-ac/loupe', ['pull_request', 'required_status_checks']], [$settings->rulesets[0]->id, $settings->rulesets[0]->name, $settings->rulesets[0]->sourceType, $settings->rulesets[0]->source, $settings->rulesets[0]->rules]);
        self::assertSame([7, 'Org floor', 'Organization', 'loupe-ac', ['non_fast_forward', 'deletion']], [$settings->rulesets[1]->id, $settings->rulesets[1]->name, $settings->rulesets[1]->sourceType, $settings->rulesets[1]->source, $settings->rulesets[1]->rules]);
        self::assertSame(['lint', 'ci/legacy', 'e2e a'], $settings->recentChecks);
        self::assertFalse($settings->classicProtection);

        self::assertSame(['POST', 'GET', 'GET', 'GET', 'GET', 'GET', 'POST'], array_column($this->requests, 'method'));
        self::assertSame('https://api.github.com/repos/loupe-ac/loupe', $this->requests[1]['url']);
        self::assertSame('https://api.github.com/repos/loupe-ac/loupe/rules/branches/main?per_page=100&page=1', $this->requests[2]['url']);
        self::assertSame('https://api.github.com/repos/loupe-ac/loupe/rulesets/101', $this->requests[3]['url']);
        self::assertSame('https://api.github.com/repos/loupe-ac/loupe/rulesets/7', $this->requests[4]['url']);
        self::assertSame('https://api.github.com/repos/loupe-ac/loupe/branches/main', $this->requests[5]['url']);
        self::assertSame('https://api.github.com/graphql', $this->requests[6]['url']);
        $body = json_decode($this->requests[6]['options']['body'] ?? '', true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame(['owner' => 'loupe-ac', 'name' => 'loupe', 'base' => 'main'], $body['variables']);
        self::assertStringContainsString('pullRequests(first:10,baseRefName:$base,orderBy:{field:UPDATED_AT,direction:DESC})', $body['query']);
        self::assertStringContainsString('commits(last:1)', $body['query']);

        $stored = json_decode(json_encode($settings, \JSON_THROW_ON_ERROR), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($stored);
        self::assertEquals($settings, RepositorySettings::fromArray($stored));
    }

    public function test_it_reads_classic_protection_from_a_branch_that_has_it(): void
    {
        $this->responses = $this->minimalAnswers($this->branch(true));

        self::assertTrue($this->reader()->read($this->installed('loupe-ac/loupe', 80_002))->classicProtection);
    }

    public function test_a_branch_with_a_ruleset_and_no_classic_protection_reads_as_unprotected_by_classic_means(): void
    {
        $this->responses = $this->minimalAnswers($this->branch(false));

        self::assertFalse($this->reader()->read($this->installed('loupe-ac/loupe', 80_003))->classicProtection);
    }

    public function test_it_keeps_at_most_thirty_recent_check_names(): void
    {
        $names = array_map(static fn (int $i): array => ['__typename' => 'CheckRun', 'name' => 'job '.$i], range(1, 45));
        $answers = $this->minimalAnswers($this->branch(false), $this->checksAnswer([$names]));
        $this->responses = $answers;

        $checks = $this->reader()->read($this->installed('loupe-ac/loupe', 80_004))->recentChecks;

        self::assertCount(30, $checks);
        self::assertSame('job 1', $checks[0]);
        self::assertSame('job 30', $checks[29]);
    }

    public function test_a_repository_with_no_pull_request_has_no_recent_checks(): void
    {
        $this->responses = $this->minimalAnswers($this->branch(false), ['data' => ['repository' => ['pullRequests' => ['nodes' => []]]]]);

        self::assertSame([], $this->reader()->read($this->installed('loupe-ac/loupe', 80_005))->recentChecks);
    }

    public function test_a_webhook_only_repository_is_unreadable_with_no_installation(): void
    {
        $project = $this->project();
        $repository = new ForgeRepository($project, 'github', 'hook-1', 'loupe-ac/loupe', ForgeRepositorySource::Hook);
        $this->em->persist($repository);
        $this->em->flush();

        self::assertSame('no_installation', $this->unreadable($repository));
        self::assertSame([], $this->requests);
    }

    public function test_a_suspended_installation_is_unreadable(): void
    {
        $repository = $this->installed('loupe-ac/loupe', 80_006);
        $installations = self::getContainer()->get(GitHubInstallationRepository::class);
        self::assertInstanceOf(GitHubInstallationRepository::class, $installations);
        $installation = $installations->findOneByInstallationId(80_006);
        self::assertInstanceOf(GitHubInstallation::class, $installation);
        $installation->suspendedAt = new \DateTimeImmutable();
        $this->em->flush();

        self::assertSame('installation_suspended', $this->unreadable($repository));
    }

    public function test_an_installation_that_belongs_to_another_project_is_unreadable(): void
    {
        $this->em->persist(new GitHubInstallation($this->project(), 80_007, 'loupe-ac', GitHubRepositorySelection::Selected));
        $repository = new ForgeRepository($this->project(), 'github', '80007', 'loupe-ac/loupe', ForgeRepositorySource::Installation, '80007');
        $this->em->persist($repository);
        $this->em->flush();

        self::assertSame('no_installation', $this->unreadable($repository));
    }

    /** @param list<MockResponse> $responses */
    #[\PHPUnit\Framework\Attributes\DataProvider('failures')]
    public function test_a_failed_or_unclear_read_is_unreadable_with_a_reason(string $reason, array $responses): void
    {
        $this->responses = [$this->answer(['token' => 'ghs_token'], 201), ...$responses];

        self::assertSame($reason, $this->unreadable($this->installed('loupe-ac/loupe', 80_008)));
    }

    /** @return iterable<string, array{string, list<MockResponse>}> */
    public static function failures(): iterable
    {
        $repo = ['default_branch' => 'main', 'allow_squash_merge' => true, 'allow_merge_commit' => true, 'allow_rebase_merge' => true];
        $rules = [['type' => 'deletion', 'ruleset_id' => 7, 'ruleset_source_type' => 'Repository', 'ruleset_source' => 'loupe-ac/loupe']];
        $json = static fn (array $body, int $status = 200): MockResponse => new MockResponse(json_encode($body, \JSON_THROW_ON_ERROR), ['http_code' => $status]);
        $branch = ['name' => 'main', 'protected' => true, 'protection' => ['enabled' => false]];

        yield 'the repository answers 404' => ['api_failed_http_status', [$json([], 404)]];
        yield 'the repository has no default branch' => ['default_branch_unreadable', [$json(['allow_squash_merge' => true])]];
        yield 'the merge switches are missing' => ['merge_switches_unreadable', [$json(['default_branch' => 'main'])]];
        yield 'the rules answer is not a list' => ['malformed_body', [$json($repo), $json(['message' => 'x'])]];
        yield 'the rules read fails' => ['api_failed_http_status', [$json($repo), $json([], 500)]];
        yield 'a ruleset name cannot be read' => ['api_failed_http_status', [$json($repo), $json($rules), $json([], 403)]];
        yield 'a ruleset has no name' => ['ruleset_unreadable', [$json($repo), $json($rules), $json(['id' => 7])]];
        yield 'the branch read fails' => ['api_failed_http_status', [$json($repo), $json([]), $json([], 404)]];
        yield 'the branch has no protection signal' => ['branch_unreadable', [$json($repo), $json([]), $json(['name' => 'main'])]];
        yield 'the checks read fails' => ['api_failed_http_status', [$json($repo), $json([]), $json($branch), $json([], 502)]];
        yield 'the checks read answers a GraphQL error' => ['api_failed_graphql_error', [$json($repo), $json([]), $json($branch), $json(['data' => null, 'errors' => [['type' => 'FORBIDDEN']]])]];
        yield 'the checks answer has no repository' => ['checks_unreadable', [$json($repo), $json([]), $json($branch), $json(['data' => ['repository' => null]])]];
    }

    public function test_every_page_of_the_rules_is_read(): void
    {
        $page = array_fill(0, 100, ['type' => 'creation', 'ruleset_id' => 9, 'ruleset_source_type' => 'Repository', 'ruleset_source' => 'loupe-ac/loupe']);
        $this->responses = [
            $this->answer(['token' => 'ghs_token'], 201),
            $this->answer(['default_branch' => 'main', 'allow_squash_merge' => true, 'allow_merge_commit' => true, 'allow_rebase_merge' => true]),
            $this->answer($page),
            $this->answer([['type' => 'deletion', 'ruleset_id' => 9, 'ruleset_source_type' => 'Repository', 'ruleset_source' => 'loupe-ac/loupe']]),
            $this->answer(['name' => 'All']),
            $this->answer($this->branch(false)),
            $this->answer($this->checksAnswer([])),
        ];

        $settings = $this->reader()->read($this->installed('loupe-ac/loupe', 80_009));

        self::assertTrue($settings->rules->forbidsDeletion);
        self::assertSame('https://api.github.com/repos/loupe-ac/loupe/rules/branches/main?per_page=100&page=2', $this->requests[3]['url']);
        self::assertSame(['creation', 'deletion'], $settings->rulesets[0]->rules);
    }

    public function test_an_app_without_a_numeric_id_is_unreadable(): void
    {
        self::assertSame('app_not_configured', $this->unreadable($this->installed('loupe-ac/loupe', 80_010), 'not-a-number'));
    }

    private function unreadable(ForgeRepository $repository, string $appId = self::APP_ID): string
    {
        try {
            $this->reader($appId)->read($repository);
        } catch (RepositorySettingsUnreadable $e) {
            return $e->reason;
        }

        self::fail('Expected RepositorySettingsUnreadable.');
    }

    /**
     * The answers of a repository with no rule at all.
     *
     * @param array<mixed>      $branch
     * @param array<mixed>|null $checks
     *
     * @return list<MockResponse>
     */
    private function minimalAnswers(array $branch, ?array $checks = null): array
    {
        return [
            $this->answer(['token' => 'ghs_token'], 201),
            $this->answer(['default_branch' => 'main', 'allow_squash_merge' => true, 'allow_merge_commit' => true, 'allow_rebase_merge' => true]),
            $this->answer([]),
            $this->answer($branch),
            $this->answer($checks ?? $this->checksAnswer([])),
        ];
    }

    /** @return list<array<mixed>> */
    private function rules(): array
    {
        $repo = ['ruleset_source_type' => 'Repository', 'ruleset_source' => 'loupe-ac/loupe', 'ruleset_id' => 101];
        $org = ['ruleset_source_type' => 'Organization', 'ruleset_source' => 'loupe-ac', 'ruleset_id' => 7];

        return [
            ['type' => 'pull_request', 'parameters' => ['required_approving_review_count' => 0, 'allowed_merge_methods' => ['squash', 'rebase']]] + $repo,
            ['type' => 'required_status_checks', 'parameters' => ['strict_required_status_checks_policy' => false, 'required_status_checks' => [['context' => 'Loupe / Approval', 'integration_id' => 123_456], ['context' => 'ci']]]] + $repo,
            ['type' => 'non_fast_forward'] + $org,
            ['type' => 'deletion'] + $org,
        ];
    }

    /**
     * The shape of `GET /repos/{owner}/{repo}/branches/{branch}`.
     *
     * @return array<mixed>
     */
    private function branch(bool $classic): array
    {
        return [
            'name' => 'main',
            'protected' => true,
            'protection' => $classic
                ? ['enabled' => true, 'required_status_checks' => ['enforcement_level' => 'everyone', 'contexts' => ['ci'], 'checks' => [['context' => 'ci', 'app_id' => null]]]]
                : ['enabled' => false, 'required_status_checks' => ['enforcement_level' => 'off', 'contexts' => [], 'checks' => []]],
            'protection_url' => 'https://api.github.com/repos/loupe-ac/loupe/branches/main/protection',
        ];
    }

    /**
     * @param list<list<array<string, string>>> $contextsOfEachPullRequest
     *
     * @return array<mixed>
     */
    private function checksAnswer(array $contextsOfEachPullRequest): array
    {
        return ['data' => ['repository' => ['pullRequests' => ['nodes' => array_map(
            static fn (array $contexts): array => ['commits' => ['nodes' => [['commit' => ['statusCheckRollup' => ['contexts' => ['nodes' => $contexts]]]]]]],
            $contextsOfEachPullRequest,
        )]]]];
    }

    private function installed(string $path, int $installationId): ForgeRepository
    {
        $project = $this->project();
        $this->em->persist(new GitHubInstallation($project, $installationId, 'loupe-ac', GitHubRepositorySelection::Selected));
        $repository = new ForgeRepository($project, 'github', (string) $installationId, $path, ForgeRepositorySource::Installation, (string) $installationId);
        $this->em->persist($repository);
        $this->em->flush();

        return $repository;
    }

    private function project(): Project
    {
        $user = new User(fullName: 'Riley', email: 'settings-'.uniqid().'@example.com', password: 'hashed');
        $project = new Project($user, 'settings-'.uniqid());
        $this->em->persist($user);
        $this->em->persist($project);

        return $project;
    }

    private function reader(string $appId = self::APP_ID): GitHubRepositorySettingsReader
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
        $configuration = new GitHubAppConfiguration(null, null, null, null, $appId, $pem);

        return new GitHubRepositorySettingsReader(new GitHubAppApi($client, $configuration, new MockClock('2026-10-27 12:00:00')), $installations, $configuration);
    }

    /** @param array<mixed> $body */
    private function answer(array $body, int $status = 200): MockResponse
    {
        return new MockResponse(json_encode($body, \JSON_THROW_ON_ERROR), ['http_code' => $status]);
    }
}
