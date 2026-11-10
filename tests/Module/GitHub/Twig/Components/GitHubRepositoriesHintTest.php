<?php

declare(strict_types=1);

namespace App\Tests\Module\GitHub\Twig\Components;

use App\Module\Account\Entity\User;
use App\Module\Forge\Entity\ForgeRepository;
use App\Module\Forge\Entity\ForgeRepositorySource;
use App\Module\GitHub\Entity\GitHubInstallation;
use App\Module\GitHub\Entity\GitHubRepositorySelection;
use App\Module\GitHub\Service\GitHubAppApi;
use App\Module\GitHub\Service\GitHubAppConfiguration;
use App\Module\GitHub\Service\GitHubFreeRepositories;
use App\Module\Project\Entity\Project;
use App\Tests\Module\GitHub\Controller\GitHubConnectionsScenario;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\Cache\CacheInterface;

/** The note on the Repositories tab about repositories the App reaches and no project holds. */
final class GitHubRepositoriesHintTest extends WebTestCase
{
    use GitHubConnectionsScenario;

    private KernelBrowser $client;

    /** @var list<string> */
    private array $requests = [];

    /** @var list<MockResponse> */
    private array $responses = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $cache = static::getContainer()->get(CacheInterface::class);
        self::assertInstanceOf(AdapterInterface::class, $cache);
        $cache->clear();
    }

    public function test_the_tab_counts_the_repositories_that_no_project_holds(): void
    {
        [$owner, $project] = $this->projectWithInstallation('count', 91_001);
        $this->holdElsewhere($owner, 'held', 92_002);
        $this->useApi([$this->repositories([[92_001, 'acme/mine'], [92_002, 'acme/held'], [92_003, 'acme/free-a'], [92_004, 'acme/free-b']])]);

        $crawler = $this->tab($owner, $project);

        $hint = $crawler->filter('#repositories [data-testid="forge-repositories-unpicked"]');
        self::assertCount(1, $hint);
        self::assertStringContainsString('2 more repositories are shared with Loupe and are not in this project.', $hint->text());
        self::assertSame('/projects/'.$project->id.'/github/install', $hint->filter('a')->attr('href'));
    }

    public function test_the_tab_shows_no_hint_when_every_repository_is_held(): void
    {
        [$owner, $project] = $this->projectWithInstallation('none', 91_002);
        $this->useApi([$this->repositories([[92_002, 'acme/mine']])]);

        $crawler = $this->tab($owner, $project);

        self::assertCount(0, $crawler->filter('[data-testid="forge-repositories-unpicked"]'));
    }

    public function test_the_tab_shows_no_hint_when_github_fails(): void
    {
        [$owner, $project] = $this->projectWithInstallation('down', 91_003);
        $this->useApi([new MockResponse('{}', ['http_code' => 500])]);

        $crawler = $this->tab($owner, $project);

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-testid="forge-repositories-unpicked"]'));
        self::assertCount(1, $crawler->filter('#repositories [data-forge-repository]'));
    }

    public function test_the_listing_is_cached_until_the_installation_changes(): void
    {
        [$owner, $project] = $this->projectWithInstallation('cache', 91_004);
        $this->useApi([
            $this->repositories([[92_004, 'acme/mine'], [92_022, 'acme/free']]),
            $this->repositories([[92_004, 'acme/mine']]),
        ]);

        $this->tab($owner, $project);
        $crawler = $this->tab($owner, $project);
        self::assertCount(1, $crawler->filter('[data-testid="forge-repositories-unpicked"]'));
        self::assertCount(1, array_filter($this->requests, static fn (string $url): bool => str_contains($url, '/installation/repositories')), 'One listing call serves both renders.');

        $free = static::getContainer()->get(GitHubFreeRepositories::class);
        self::assertInstanceOf(GitHubFreeRepositories::class, $free);
        $free->forget(91_004);
        $crawler = $this->tab($owner, $project);

        self::assertCount(0, $crawler->filter('[data-testid="forge-repositories-unpicked"]'));
    }

    /** @return array{User, Project} */
    private function projectWithInstallation(string $label, int $installationId): array
    {
        $owner = $this->signedUpUser('hint-'.$label);
        $project = $this->projectOf($owner);
        $this->em()->persist(new GitHubInstallation($installationId, 'acme', GitHubRepositorySelection::Selected));
        $this->em()->persist(new ForgeRepository($project, 'github', (string) ($installationId + 1_000), 'acme/mine', ForgeRepositorySource::Installation, (string) $installationId));
        $this->em()->flush();

        return [$owner, $project];
    }

    private function holdElsewhere(User $owner, string $name, int $repositoryId): void
    {
        $other = $this->projectOf($owner);
        $this->em()->persist(new ForgeRepository($other, 'github', (string) $repositoryId, 'acme/'.$name, ForgeRepositorySource::Installation, '99999'));
        $this->em()->flush();
    }

    /** @param list<array{int, string}> $repositories */
    private function repositories(array $repositories): MockResponse
    {
        return new MockResponse(json_encode(['repositories' => array_map(static fn (array $r): array => ['id' => $r[0], 'full_name' => $r[1]], $repositories)], \JSON_THROW_ON_ERROR));
    }

    /** @param list<MockResponse> $answers the answers after the installation token */
    private function useApi(array $answers): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => \OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($key);
        self::assertTrue(openssl_pkey_export($key, $pem));
        self::assertIsString($pem);

        $this->responses = $answers;
        $http = new MockHttpClient(function (string $method, string $url): MockResponse {
            $this->requests[] = $url;
            if (str_ends_with($url, '/access_tokens')) {
                return new MockResponse(json_encode(['token' => 'ghs_t'], \JSON_THROW_ON_ERROR), ['http_code' => 201]);
            }

            return array_shift($this->responses) ?? throw new \LogicException('Unexpected request '.$method.' '.$url);
        }, 'https://api.github.com');
        $configuration = new GitHubAppConfiguration('loupe-app', 'client', 'secret', 'hook', '123456', $pem);
        static::getContainer()->set(GitHubAppConfiguration::class, $configuration);
        static::getContainer()->set(GitHubAppApi::class, new GitHubAppApi($http, $configuration, new MockClock('2026-10-18 13:00:00')));
    }

    private function tab(User $owner, Project $project): Crawler
    {
        $this->em()->clear();
        $this->client->loginUser($owner);

        return $this->repositoriesPage($this->client, $project);
    }
}
