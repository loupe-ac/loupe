<?php

declare(strict_types=1);

namespace App\Tests\Module\GitHub\Controller;

use App\Module\Forge\Entity\ForgeRepository;
use App\Module\Forge\Entity\ForgeRepositorySource;
use App\Module\Forge\Messenger\CheckForgeRepository;
use App\Module\Forge\Messenger\CheckTrigger;
use App\Module\Forge\Repository\ForgeRepositoryRepository;
use App\Module\GitHub\Command\AddGitHubRepositoriesHandler;
use App\Module\GitHub\Command\SignInGitHubPickerHandler;
use App\Module\GitHub\Entity\GitHubInstallation;
use App\Module\GitHub\Entity\GitHubRepositorySelection;
use App\Module\GitHub\Repository\GitHubInstallationRepository;
use App\Module\GitHub\Service\GitHubAppConfiguration;
use App\Module\GitHub\Service\GitHubInstallSession;
use App\Module\GitHub\Service\GitHubPickerListing;
use App\Module\GitHub\Service\GitHubUserApi;
use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use App\Module\Project\Service\InitInstallWaits;
use App\Tests\Support\MarkNewProjectsSetUp;
use App\Tests\Support\NotSetUp;
use App\Tests\Support\RecordingAuditor;
use App\Tests\Support\RecordingLogger;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Uid\Uuid;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;

final class GitHubAppInstallFlowTest extends WebTestCase
{
    use GitHubConnectionsScenario;

    private const int INSTALLATION_ID = 424242;
    private const string TOKEN = 'ghu_user_token_never_logged';

    private KernelBrowser $client;

    private MockClock $clock;

    /** @var list<array<int|string, string>> */
    private array $tokenRequests = [];

    /** @var list<string> */
    private array $apiAuthorizations = [];

    private MockResponse $tokenResponse;

    /** @var list<array<string, mixed>> */
    private array $installations = [];

    /** @var array<int, list<array<string, mixed>>> */
    private array $repositories = [];

    private ?MockResponse $apiFailure = null;

    /** @var list<\Closure(): void> run before the next repository listing is served */
    private array $beforeListing = [];

    /** @var list<int> */
    private array $failingRepositoryListings = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->tokenResponse = $this->json(['access_token' => self::TOKEN, 'token_type' => 'bearer']);
        $this->installations = [$this->installation(self::INSTALLATION_ID, 'acme')];
        $this->repositories = [self::INSTALLATION_ID => []];
        $this->clock = new MockClock('2026-11-10 12:00:00');
        $container = static::getContainer();
        $container->set(ClockInterface::class, $this->clock);
        $container->set(GitHubAppConfiguration::class, new GitHubAppConfiguration('loupe-test', 'the-client-id', 'the-client-secret', 'hook', null, null));
        $container->set('github.oauth_client', new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            self::assertSame('POST', $method);
            self::assertSame('https://github.com/login/oauth/access_token', $url);
            parse_str(\is_string($options['body'] ?? null) ? $options['body'] : '', $body);
            $this->tokenRequests[] = array_map(static fn (mixed $value): string => \is_string($value) ? $value : '', $body);

            return $this->tokenResponse;
        }, 'https://github.com'));
        $container->set('github.api_client', new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $authorization = $options['normalized_headers']['authorization'][0] ?? '';
            $this->apiAuthorizations[] = \is_string($authorization) ? $authorization : '';
            if (null !== $this->apiFailure) {
                return $this->apiFailure;
            }

            parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
            $page = (int) ($query['page'] ?? 1);
            $path = (string) parse_url($url, \PHP_URL_PATH);
            if ('/user/installations' === $path) {
                return $this->json(['total_count' => \count($this->installations), 'installations' => \array_slice($this->installations, ($page - 1) * 100, 100)]);
            }
            if (1 === preg_match('#^/user/installations/(\d+)/repositories$#', $path, $match)) {
                if (\in_array((int) $match[1], $this->failingRepositoryListings, true)) {
                    return new MockResponse('{}', ['http_code' => 503]);
                }

                $hook = array_shift($this->beforeListing);
                if (null !== $hook) {
                    $hook();
                }

                $repositories = $this->repositories[(int) $match[1]] ?? [];

                return $this->json(['total_count' => \count($repositories), 'repositories' => \array_slice($repositories, ($page - 1) * 100, 100)]);
            }

            return new MockResponse('{}', ['http_code' => 404]);
        }, 'https://api.github.com'));
    }

    public function test_add_repositories_sends_the_owner_to_github_with_a_pkce_challenge(): void
    {
        $owner = $this->signedUpUser('start');
        $project = $this->projectOf($owner);
        $this->em()->clear();

        $this->client->loginUser($owner);
        $authorize = $this->startSignIn($project);

        self::assertSame('the-client-id', $authorize['client_id']);
        self::assertSame('http://localhost/github/app/callback', $authorize['redirect_uri']);
        self::assertSame('S256', $authorize['code_challenge_method']);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $authorize['code_challenge']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32,}$/', $authorize['state']);
    }

    public function test_a_stranger_cannot_reach_any_picker_route(): void
    {
        $project = $this->projectOf($this->signedUpUser('owner'));
        $stranger = $this->signedUpUser('stranger');
        $this->em()->clear();

        $this->client->loginUser($stranger);
        foreach (['/github/install', '/github/install/account', '/settings/repositories'] as $path) {
            $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.$path);
            self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN, $path);
        }

        $this->postPicks($project, [self::INSTALLATION_ID.':101:acme/one']);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $this->postAction($this->client, '/projects/'.$project->id.'/github/repositories/cancel');
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function test_without_the_app_add_repositories_explains_and_goes_back(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->client->disableReboot();
        static::getContainer()->set(GitHubAppConfiguration::class, new GitHubAppConfiguration(null, null, null, null, null, null));
        $owner = $this->signedUpUser('noapp');
        $project = $this->projectOf($owner);
        $this->em()->clear();

        $this->client->loginUser($owner);
        foreach (['/github/install', '/github/install/account'] as $path) {
            $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.$path);

            self::assertResponseRedirects('/projects/'.$project->id.'/settings/repositories');
            self::assertStringContainsString('The operator has not registered a GitHub App', $this->followedText());
        }
    }

    public function test_the_install_link_sends_the_owner_to_github_with_a_state(): void
    {
        $owner = $this->signedUpUser('install');
        $project = $this->projectOf($owner);
        $this->em()->clear();

        $this->client->loginUser($owner);

        self::assertMatchesRegularExpression('/^[a-f0-9]{32,}$/', $this->startInstall($project));
    }

    public function test_installing_on_another_account_keeps_the_init_session_of_the_picker(): void
    {
        $this->repositories[self::INSTALLATION_ID] = [['id' => 101, 'full_name' => 'acme/api']];
        $owner = $this->signedUpUser('another-account');
        $project = $this->projectOf($owner);
        $this->em()->clear();
        $init = $this->markWait($project);

        $this->client->loginUser($owner);
        $this->startSignIn($project, init: $init);
        $state = $this->startInstall($project);
        $authorize = $this->setUpInstallation($state);
        $this->client->request(Request::METHOD_GET, '/github/app/callback?'.http_build_query(['code' => 'the-code', 'state' => $authorize['state']]));

        self::assertResponseRedirects('/projects/'.$project->id.'/github/init-handoff?repository=acme/api&init='.$init);
    }

    public function test_the_setup_url_without_a_pending_session_goes_to_the_projects(): void
    {
        $this->client->loginUser($this->signedUpUser('nopending'));
        $this->client->request(Request::METHOD_GET, '/github/app/setup?installation_id='.self::INSTALLATION_ID);

        self::assertResponseRedirects('/projects');
        self::assertStringContainsString('no GitHub sign-in in progress', $this->followedText());
    }

    public function test_the_setup_url_needs_a_signed_in_user(): void
    {
        $this->client->request(Request::METHOD_GET, '/github/app/setup?installation_id='.self::INSTALLATION_ID);

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function test_the_setup_url_refuses_a_state_that_does_not_match(): void
    {
        $owner = $this->signedUpUser('setupstate');
        $project = $this->projectOf($owner);
        $this->em()->clear();

        $this->client->loginUser($owner);
        $this->startInstall($project);
        $this->client->request(Request::METHOD_GET, '/github/app/setup?installation_id='.self::INSTALLATION_ID.'&state=forged');

        self::assertResponseRedirects('/projects/'.$project->id.'/settings/repositories');
        self::assertStringContainsString('Loupe could not confirm', $this->followedText());
        $this->client->request(Request::METHOD_GET, '/github/app/setup?installation_id='.self::INSTALLATION_ID);
        self::assertResponseRedirects('/projects');
    }

    public function test_the_setup_url_without_an_installation_explains(): void
    {
        $owner = $this->signedUpUser('setupnone');
        $project = $this->projectOf($owner);
        $this->em()->clear();

        $this->client->loginUser($owner);
        $this->startInstall($project);
        $this->client->request(Request::METHOD_GET, '/github/app/setup');

        self::assertResponseRedirects('/projects/'.$project->id.'/settings/repositories');
        self::assertStringContainsString('GitHub did not return an installation', $this->followedText());
    }

    public function test_a_request_return_shows_the_waiting_page_and_clears_the_install_in_progress(): void
    {
        $owner = $this->signedUpUser('request');
        $project = $this->projectOf($owner);
        $this->em()->clear();

        $this->client->loginUser($owner);
        $installState = $this->startInstall($project);
        $crawler = $this->client->request(Request::METHOD_GET, '/github/app/setup?setup_action=request&state='.$installState);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Your install request waits for an organization owner', $crawler->filter('h1')->text());
        self::assertStringContainsString('An owner of the GitHub organization must approve', $crawler->filter('[data-testid="github-install-request-waiting"]')->text());
        self::assertStringContainsString('open a project and select Add repositories', $crawler->text());
        self::assertSame('/projects', $crawler->selectLink('Open projects')->attr('href'));
        $this->client->request(Request::METHOD_GET, '/github/app/setup?installation_id='.self::INSTALLATION_ID.'&state='.$installState);
        self::assertResponseRedirects('/projects');
    }

    public function test_a_request_return_with_no_session_shows_the_waiting_page(): void
    {
        $this->client->loginUser($this->signedUpUser('requestnone'));
        $this->client->request(Request::METHOD_GET, '/github/app/setup?setup_action=request');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="github-install-request-waiting"]');
    }

    /** @return iterable<string, array{string}> */
    public static function githubFirstActions(): iterable
    {
        yield 'install' => ['install'];
        yield 'update' => ['update'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('githubFirstActions')]
    public function test_a_return_from_github_signs_in_and_opens_the_project_choice(string $action): void
    {
        $owner = $this->signedUpUser('first-'.$action);
        $this->em()->clear();

        $this->client->loginUser($owner);
        $this->githubFirstSignIn($action);

        self::assertResponseRedirects('/github/app/project');
        $crawler = $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSame('Loupe is installed on acme', $crawler->filter('h1')->text());
        self::assertStringContainsString('Create a project for the repositories of acme. You pick the repositories next.', $crawler->text());
        self::assertSame('acme', $crawler->filter('input[name$="[name]"]')->attr('value'));
        self::assertCount(0, $crawler->filter('input[type="radio"]'), 'With no project, only the name form shows.');
        self::assertCount(1, $crawler->filter('[data-install-name-only]'));
        self::assertCount(0, $crawler->filter('[data-install-new-project]'));
        self::assertSame('Create project and continue', trim($crawler->filter('button[type="submit"]')->text()));
        self::assertStringContainsString('You can rename it later in Project settings.', $crawler->text());
    }

    public function test_a_forged_installation_id_opens_no_project_choice(): void
    {
        $this->client->loginUser($this->signedUpUser('forged'));
        $this->githubFirstSignIn('install', 999_999);

        self::assertResponseRedirects('/projects');
        self::assertStringContainsString('Loupe cannot find this install on your GitHub account.', $this->followedText());
        $this->client->request(Request::METHOD_GET, '/github/app/project');
        self::assertResponseRedirects('/projects');
    }

    public function test_the_project_choice_needs_a_github_sign_in(): void
    {
        $this->client->loginUser($this->signedUpUser('nochoice'));
        $this->client->request(Request::METHOD_GET, '/github/app/project');

        self::assertResponseRedirects('/projects');
        self::assertStringContainsString('no GitHub sign-in in progress', $this->followedText());
    }

    public function test_the_project_choice_lists_the_owned_projects_with_their_repository_counts(): void
    {
        $this->repositories[self::INSTALLATION_ID] = [['id' => 101, 'full_name' => 'acme/one'], ['id' => 102, 'full_name' => 'acme/two']];
        $owner = $this->signedUpUser('lists');
        $stranger = $this->projectOf($this->signedUpUser('listsother'));
        $project = $this->projectOf($owner);
        $this->em()->persist(new ForgeRepository($project, 'github', '900', 'acme/held', ForgeRepositorySource::Hook));
        $this->em()->flush();
        $this->em()->clear();

        $this->client->loginUser($owner);
        $this->githubFirstSignIn('install');
        $crawler = $this->client->followRedirect();

        self::assertResponseIsSuccessful();
        $values = $crawler->filter('input[type="radio"]')->each(static fn (Crawler $radio): string => (string) $radio->attr('value'));
        self::assertSame([(string) $project->id, 'new'], $values);
        self::assertNotContains((string) $stranger->id, $values);
        self::assertStringContainsString('1 repository', $crawler->filter('.lp-choice')->first()->text());
        self::assertSame(1, $crawler->filter('label.lp-choice.lp-choice--top[data-install-project]')->count());
        self::assertSame(2, $crawler->filter('.lp-choice.lp-choice--top')->count());
        $newRow = $crawler->filter('[data-install-new-project]');
        self::assertCount(1, $newRow);
        self::assertStringContainsString('New project', $newRow->text());
        self::assertCount(1, $newRow->filter('input[type="radio"][value="new"]'), 'The new project radio sits in its own row.');
        self::assertCount(1, $newRow->filter('input[name$="[name]"]'), 'The name field sits inside the new project row.');
        self::assertCount(1, $crawler->filter('input[name$="[name]"]'));
        self::assertSame('Continue', trim($crawler->filter('button[type="submit"]')->text()));
    }

    public function test_a_new_project_with_one_free_repository_adds_it_and_lands_on_the_setup_page(): void
    {
        $this->repositories[self::INSTALLATION_ID] = [['id' => 101, 'full_name' => 'acme/only']];
        $owner = $this->signedUpUser('newone');
        $this->em()->clear();

        $this->client->loginUser($owner);
        $this->githubFirstSignIn('install');
        $this->client->followRedirect();
        MarkNewProjectsSetUp::$enabled = false;
        try {
            $this->postChoice('new', 'Acme platform');
        } finally {
            MarkNewProjectsSetUp::$enabled = true;
        }

        $project = $this->projectNamed('Acme platform');
        self::assertSame(['acme/only'], $this->pathsOf($project));
        self::assertResponseRedirects('/projects/'.$project->id.'/setup');
    }

    public function test_a_new_project_with_several_free_repositories_opens_the_picker(): void
    {
        $this->repositories[self::INSTALLATION_ID] = [['id' => 101, 'full_name' => 'acme/one'], ['id' => 102, 'full_name' => 'acme/two']];
        $owner = $this->signedUpUser('newmany');
        $this->em()->clear();

        $this->client->loginUser($owner);
        $this->githubFirstSignIn('install');
        $this->client->followRedirect();
        $this->postChoice('new', 'Acme many');

        $project = $this->projectNamed('Acme many');
        self::assertResponseRedirects('/projects/'.$project->id.'/settings/repositories');
        self::assertSame([], $this->pathsOf($project));
        $crawler = $this->client->followRedirect();
        self::assertCount(1, $crawler->filter('#repositories [data-testid="github-picker"]'));
        $this->submitPicks($project, ['101:acme/one']);
        self::assertSame(['acme/one'], $this->pathsOf($project));
    }

    public function test_an_existing_project_takes_the_single_free_repository(): void
    {
        $this->repositories[self::INSTALLATION_ID] = [['id' => 101, 'full_name' => 'acme/only']];
        $owner = $this->signedUpUser('pickone');
        $project = $this->projectOf($owner);
        $this->em()->clear();

        $this->client->loginUser($owner);
        $this->githubFirstSignIn('install');
        $this->client->followRedirect();
        $this->postChoice((string) $project->id, 'ignored');

        self::assertResponseRedirects();
        self::assertStringStartsWith('/projects/'.$project->id.'/', (string) $this->client->getResponse()->headers->get('Location'));
        self::assertSame(['acme/only'], $this->pathsOf($project));
        self::assertNull($this->projectNamedOrNull('ignored'), 'An existing project creates no project.');
    }

    public function test_a_cut_list_never_adds_at_once(): void
    {
        $this->repositories[self::INSTALLATION_ID] = array_map(static fn (int $id): array => ['id' => $id, 'full_name' => 'acme/r'.$id], range(1, 1001));
        $owner = $this->signedUpUser('cut');
        $project = $this->projectOf($owner);
        $this->em()->clear();

        $this->client->loginUser($owner);
        $this->githubFirstSignIn('install');
        $this->client->followRedirect();
        $this->postChoice((string) $project->id, '');

        self::assertResponseRedirects('/projects/'.$project->id.'/settings/repositories');
        self::assertSame([], $this->pathsOf($project));
    }

    public function test_a_stranger_project_is_refused_and_nothing_is_added(): void
    {
        $this->repositories[self::INSTALLATION_ID] = [['id' => 101, 'full_name' => 'acme/only']];
        $owner = $this->signedUpUser('mine');
        $stranger = $this->projectOf($this->signedUpUser('theirs'));
        $this->em()->clear();

        $this->client->loginUser($owner);
        $this->githubFirstSignIn('install');
        $this->client->followRedirect();
        $this->postChoice((string) $stranger->id, '');

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame([], $this->pathsOf($stranger));
    }

    public function test_a_new_project_needs_a_name(): void
    {
        $owner = $this->signedUpUser('noname');
        $this->em()->clear();

        $this->client->loginUser($owner);
        $this->githubFirstSignIn('install');
        $this->client->followRedirect();
        $this->postChoice('new', '  ');

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringContainsString('Give the new project a name.', $this->client->getCrawler()->text());
    }

    public function test_a_taken_project_name_shows_the_error_on_the_page(): void
    {
        $owner = $this->signedUpUser('taken');
        $project = $this->projectOf($owner);
        $name = $project->name;
        $this->em()->clear();

        $this->client->loginUser($owner);
        $this->githubFirstSignIn('install');
        $this->client->followRedirect();
        $this->postChoice('new', $name);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function test_the_picker_lists_the_new_account_first(): void
    {
        $this->installations = [$this->installation(111, 'aardvark'), $this->installation(self::INSTALLATION_ID, 'zeta')];
        $this->repositories = [111 => [['id' => 201, 'full_name' => 'aardvark/a'], ['id' => 202, 'full_name' => 'aardvark/b']], self::INSTALLATION_ID => [['id' => 101, 'full_name' => 'zeta/a'], ['id' => 102, 'full_name' => 'zeta/b']]];
        $owner = $this->signedUpUser('sort');
        $this->em()->clear();

        $this->client->loginUser($owner);
        $this->githubFirstSignIn('install');
        $this->client->followRedirect();
        $this->postChoice('new', 'Sorted');
        $crawler = $this->client->followRedirect();

        $accounts = $crawler->filter('[data-testid="github-picker-account"]')->each(static fn (Crawler $account): string => trim($account->text()));
        self::assertSame(['zeta', 'aardvark'], $accounts);
    }

    public function test_the_choice_post_needs_a_csrf_token(): void
    {
        $owner = $this->signedUpUser('csrf');
        $this->em()->clear();

        $this->client->loginUser($owner);
        $this->githubFirstSignIn('install');
        $this->client->followRedirect();
        $this->client->request(Request::METHOD_POST, '/github/app/project', ['choose_install_project_form' => ['project' => 'new', 'name' => 'No token']]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertNull($this->projectNamedOrNull('No token'));
    }

    public function test_the_setup_url_after_an_install_asks_github_to_authorize(): void
    {
        $owner = $this->signedUpUser('setup');
        $project = $this->projectOf($owner);
        $this->em()->clear();

        $this->client->loginUser($owner);
        $installState = $this->startInstall($project);
        $authorize = $this->setUpInstallation($installState);

        self::assertSame('S256', $authorize['code_challenge_method']);
        self::assertNotSame($installState, $authorize['state']);
    }

    public function test_the_setup_url_reopens_the_picker_for_a_live_sign_in_and_ignores_the_installation_id(): void
    {
        $owner = $this->signedUpUser('adjust');
        $project = $this->projectOf($owner);
        $this->em()->clear();

        $this->client->loginUser($owner);
        $this->signIn($project);
        $exchanges = \count($this->tokenRequests);
        $this->client->request(Request::METHOD_GET, '/github/app/setup?'.http_build_query(['installation_id' => 999_999, 'setup_action' => 'update']));

        self::assertResponseRedirects('/projects/'.$project->id.'/settings/repositories');
        self::assertCount($exchanges, $this->tokenRequests, 'The setup return exchanges no code.');
        $crawler = $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/settings/repositories');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('#repositories [data-testid="github-picker"]'));
    }

    public function test_the_setup_url_after_the_sign_in_expired_shows_the_add_button_again(): void
    {
        $owner = $this->signedUpUser('adjustlate');
        $project = $this->projectOf($owner);
        $this->em()->clear();

        $this->client->loginUser($owner);
        $this->signIn($project);
        $this->clock->modify('+31 minutes');
        $this->client->request(Request::METHOD_GET, '/github/app/setup?installation_id='.self::INSTALLATION_ID);
        self::assertResponseRedirects('/projects/'.$project->id.'/settings/repositories');
        $crawler = $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/settings/repositories');

        self::assertResponseIsSuccessful();
        $this->assertNotSignedIn($crawler, $project);
    }

    public function test_a_refused_sign_in_started_on_connections_returns_to_connections(): void
    {
        $owner = $this->signedUpUser('connectstate');
        $project = $this->projectOf($owner);
        $this->em()->clear();

        $this->client->loginUser($owner);
        $this->startSignIn($project, null, 'connect');
        $this->client->request(Request::METHOD_GET, '/github/app/callback?code=the-code&state=forged');

        self::assertResponseRedirects('/projects/'.$project->id.'/connect');
    }

    public function test_a_cancelled_sign_in_started_on_connections_returns_to_connections(): void
    {
        $owner = $this->signedUpUser('connectcancel');
        $project = $this->projectOf($owner);
        $this->em()->clear();

        $this->client->loginUser($owner);
        $authorize = $this->startSignIn($project, null, 'connect');
        $this->client->request(Request::METHOD_GET, '/github/app/callback?'.http_build_query(['error' => 'access_denied', 'state' => $authorize['state']]));

        self::assertResponseRedirects('/projects/'.$project->id.'/connect');
    }

    public function test_an_unknown_starting_tab_returns_to_repositories(): void
    {
        $owner = $this->signedUpUser('connectother');
        $project = $this->projectOf($owner);
        $this->em()->clear();

        $this->client->loginUser($owner);
        $this->startSignIn($project, null, 'elsewhere');
        $this->client->request(Request::METHOD_GET, '/github/app/callback?code=the-code&state=forged');

        self::assertResponseRedirects('/projects/'.$project->id.'/settings/repositories');
    }

    public function test_the_callback_refuses_a_state_that_does_not_match(): void
    {
        $owner = $this->signedUpUser('callbackstate');
        $project = $this->projectOf($owner);
        $this->em()->clear();

        $this->client->loginUser($owner);
        $this->startSignIn($project);
        $this->client->request(Request::METHOD_GET, '/github/app/callback?code=the-code&state=forged');

        self::assertResponseRedirects('/projects/'.$project->id.'/settings/repositories');
        self::assertStringContainsString('Loupe could not confirm', $this->followedText());
        self::assertSame([], $this->tokenRequests);
    }

    public function test_the_callback_without_a_pending_session_goes_to_the_projects(): void
    {
        $this->client->loginUser($this->signedUpUser('callbacknone'));
        $this->client->request(Request::METHOD_GET, '/github/app/callback?code=the-code&state=anything');

        self::assertResponseRedirects('/projects');
        self::assertSame([], $this->tokenRequests);
    }

    public function test_a_second_callback_for_the_same_sign_in_is_refused(): void
    {
        $owner = $this->signedUpUser('replay');
        $project = $this->projectOf($owner);
        $this->em()->clear();

        $this->client->loginUser($owner);
        $authorize = $this->startSignIn($project);
        $this->client->request(Request::METHOD_GET, '/github/app/callback?'.http_build_query(['code' => 'the-code', 'state' => $authorize['state']]));
        $this->client->request(Request::METHOD_GET, '/github/app/callback?'.http_build_query(['code' => 'the-code', 'state' => $authorize['state']]));

        self::assertResponseRedirects('/projects');
        self::assertCount(1, $this->tokenRequests);
    }

    public function test_a_person_who_cancels_on_github_goes_back_with_a_message_and_adds_nothing(): void
    {
        $owner = $this->signedUpUser('cancelgithub');
        $project = $this->projectOf($owner);
        $this->em()->clear();

        $this->client->loginUser($owner);
        $authorize = $this->startSignIn($project);
        $this->client->request(Request::METHOD_GET, '/github/app/callback?'.http_build_query(['error' => 'access_denied', 'state' => $authorize['state']]));

        self::assertResponseRedirects('/projects/'.$project->id.'/settings/repositories');
        self::assertStringContainsString('GitHub did not authorize Loupe', $this->followedText());
        self::assertSame([], $this->tokenRequests);
        $crawler = $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/settings/repositories');
        $this->assertNotSignedIn($crawler, $project);
    }

    public function test_a_project_the_user_no_longer_manages_is_refused(): void
    {
        $owner = $this->signedUpUser('lost');
        $project = $this->projectOf($owner);
        $this->em()->clear();

        $newOwner = $this->signedUpUser('newcomer');

        $this->client->loginUser($owner);
        $authorize = $this->startSignIn($project);
        $this->em()->getConnection()->executeStatement('UPDATE projects SET owner_id = ? WHERE id = ?', [(string) $newOwner->id, (string) $project->id]);
        $this->em()->clear();
        $this->client->request(Request::METHOD_GET, '/github/app/callback?'.http_build_query(['code' => 'the-code', 'state' => $authorize['state']]));

        self::assertResponseRedirects('/projects');
        self::assertStringContainsString('You can no longer manage the connections of that project', $this->followedText());
        self::assertSame([], $this->tokenRequests);
    }

    public function test_the_sign_in_exchanges_the_code_with_the_verifier_and_opens_the_picker(): void
    {
        $owner = $this->signedUpUser('happy');
        $project = $this->projectOf($owner);
        $this->em()->clear();

        $this->client->loginUser($owner);
        $authorize = $this->startSignIn($project);
        $this->client->request(Request::METHOD_GET, '/github/app/callback?'.http_build_query(['code' => 'the-code', 'state' => $authorize['state']]));

        self::assertResponseRedirects('/projects/'.$project->id.'/settings/repositories');
        self::assertCount(1, $this->tokenRequests);
        $exchange = $this->tokenRequests[0];
        self::assertSame('the-code', $exchange['code']);
        self::assertSame('http://localhost/github/app/callback', $exchange['redirect_uri']);
        self::assertSame($authorize['code_challenge'], rtrim(strtr(base64_encode(hash('sha256', $exchange['code_verifier'], true)), '+/', '-_'), '='));
    }

    public function test_the_picker_marks_each_repository_free_in_this_project_or_held_by_another(): void
    {
        $owner = $this->signedUpUser('marks');
        $project = $this->projectOf($owner);
        $named = $this->projectOf($owner);
        $stranger = $this->projectOf($this->signedUpUser('strangerproject'));
        $this->repositories[self::INSTALLATION_ID] = [
            ['id' => 101, 'full_name' => 'acme/free'],
            ['id' => 202, 'full_name' => 'acme/mine'],
            ['id' => 303, 'full_name' => 'acme/named'],
            ['id' => 404, 'full_name' => 'acme/stranger'],
            ['id' => 505, 'full_name' => 'acme/hooked'],
        ];
        $installation = (string) self::INSTALLATION_ID;
        foreach ([
            new ForgeRepository($project, 'github', '202', 'acme/mine', ForgeRepositorySource::Installation, $installation),
            new ForgeRepository($named, 'github', '303', 'acme/named', ForgeRepositorySource::Installation, $installation),
            new ForgeRepository($stranger, 'github', '404', 'acme/stranger', ForgeRepositorySource::Installation, $installation),
            new ForgeRepository($project, 'github', '505', 'acme/hooked', ForgeRepositorySource::Hook),
        ] as $row) {
            $this->em()->persist($row);
        }
        $this->em()->flush();
        $this->em()->clear();

        $this->client->loginUser($owner);
        $this->signIn($project);
        $crawler = $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/settings/repositories');

        self::assertResponseIsSuccessful();
        $rows = $this->pickerRows($crawler);
        self::assertSame('free', $rows['acme/free']['state']);
        self::assertSame('in-project', $rows['acme/mine']['state']);
        self::assertSame('other-project', $rows['acme/named']['state']);
        self::assertSame('other-project', $rows['acme/stranger']['state']);
        self::assertSame('free', $rows['acme/hooked']['state'], 'A hook row holds nothing, so the pick can take it over.');
        self::assertCount(1, $crawler->filter('input[name="pick_repositories_form[_token]"]'), 'The form renders its own CSRF token.');
        self::assertTrue($rows['acme/free']['tickable']);
        self::assertTrue($rows['acme/hooked']['tickable']);
        self::assertFalse($rows['acme/mine']['tickable']);
        self::assertTrue($rows['acme/mine']['checked']);
        self::assertFalse($rows['acme/named']['tickable']);
        self::assertFalse($rows['acme/free']['checked'], 'Nothing is ticked at first.');
        self::assertStringContainsString('In this project', $rows['acme/mine']['text']);
        self::assertStringContainsString('Used by '.$named->name, $rows['acme/named']['text']);
        self::assertStringContainsString('Used by another project', $rows['acme/stranger']['text']);
        self::assertStringNotContainsString($stranger->name, (string) $crawler->filter('body')->text());
        self::assertStringNotContainsString(self::TOKEN, (string) $this->client->getResponse()->getContent());
        self::assertSame('no-store', $this->client->getResponse()->headers->getCacheControlDirective('no-store') ? 'no-store' : '');
    }

    public function test_the_picker_groups_the_repositories_by_account_and_links_to_the_installation_on_github(): void
    {
        $this->installations = [
            $this->installation(self::INSTALLATION_ID, 'acme'),
            $this->installation(7, 'beta', 'https://github.com/organizations/beta/settings/installations/7'),
        ];
        $this->repositories = [
            self::INSTALLATION_ID => [['id' => 101, 'full_name' => 'acme/one']],
            7 => [['id' => 701, 'full_name' => 'beta/two']],
        ];
        $owner = $this->signedUpUser('accounts');
        $project = $this->projectOf($owner);
        $this->em()->clear();

        $this->client->loginUser($owner);
        $this->signIn($project);
        $crawler = $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/settings/repositories');

        self::assertSame(['acme', 'beta'], $crawler->filter('[data-testid="github-picker-account"]')->each(static fn (Crawler $node): string => trim($node->text())));
        self::assertSame(
            ['https://github.com/settings/installations/'.self::INSTALLATION_ID, 'https://github.com/organizations/beta/settings/installations/7'],
            $crawler->filter('[data-testid="github-picker-adjust"]')->each(static fn (Crawler $node): string => (string) $node->attr('href')),
        );
        self::assertCount(1, $crawler->filter('[data-testid="github-picker-search"]'));
        self::assertCount(1, $crawler->filter('[data-testid="github-picker-install"][href="/projects/'.$project->id.'/github/install/account"]'));
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'), 'The page holds a listing of the owner\'s own GitHub user.');
        $refresh = $crawler->filter('form[data-testid="github-picker-refresh"]');
        self::assertCount(1, $refresh);
        self::assertSame('get', $refresh->attr('method'));
        self::assertSame('/projects/'.$project->id.'/settings/repositories', $refresh->attr('action'));
    }

    public function test_the_picker_ticks_the_repository_the_start_link_named(): void
    {
        $this->repositories[self::INSTALLATION_ID] = [['id' => 101, 'full_name' => 'acme/api'], ['id' => 202, 'full_name' => 'acme/web']];
        $owner = $this->signedUpUser('ticked');
        $project = $this->projectOf($owner);
        $this->em()->clear();

        $this->client->loginUser($owner);
        $this->signIn($project, 'ACME/Web');
        $crawler = $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/settings/repositories');

        $rows = $this->pickerRows($crawler);
        self::assertTrue($rows['acme/web']['checked']);
        self::assertFalse($rows['acme/api']['checked']);
        self::assertCount(0, $crawler->filter('[data-testid="github-picker-missing"]'));
    }

    public function test_the_sign_in_starts_the_install_when_the_app_does_not_reach_the_named_repository_and_keeps_it_ticked(): void
    {
        $this->repositories[self::INSTALLATION_ID] = [['id' => 101, 'full_name' => 'acme/api']];
        $owner = $this->signedUpUser('missing');
        $project = $this->projectOf($owner);
        $this->em()->clear();

        $this->client->loginUser($owner);
        $authorize = $this->startSignIn($project, 'acme/unshared');
        $this->client->request(Request::METHOD_GET, '/github/app/callback?'.http_build_query(['code' => 'the-code', 'state' => $authorize['state']]));

        self::assertResponseRedirects();
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringStartsWith('https://github.com/apps/loupe-test/installations/new?', $location);

        $this->repositories[self::INSTALLATION_ID] = [['id' => 101, 'full_name' => 'acme/api'], ['id' => 303, 'full_name' => 'acme/unshared'], ['id' => 404, 'full_name' => 'acme/other']];
        $authorize = $this->setUpInstallation($this->queryOf($location)['state']);
        $this->client->request(Request::METHOD_GET, '/github/app/callback?'.http_build_query(['code' => 'the-code', 'state' => $authorize['state']]));
        self::assertResponseRedirects('/projects/'.$project->id.'/settings/repositories');
        $crawler = $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/settings/repositories');

        $rows = $this->pickerRows($crawler);
        self::assertTrue($rows['acme/unshared']['checked']);
        self::assertFalse($rows['acme/other']['checked']);
    }

    public function test_a_listing_cut_at_one_thousand_repositories_says_so(): void
    {
        $this->repositories[self::INSTALLATION_ID] = array_map(static fn (int $id): array => ['id' => $id, 'full_name' => 'acme/repository-'.$id], range(1, 1000));
        $owner = $this->signedUpUser('cut');
        $project = $this->projectOf($owner);
        $this->em()->clear();

        $this->client->loginUser($owner);
        $this->signIn($project);
        $crawler = $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/settings/repositories');

        self::assertSame(
            'Showing the first 1,000 repositories of acme. Choose selected repositories on GitHub to see the others.',
            trim($crawler->filter('[data-testid="github-picker-cut"]')->text()),
        );
    }

    public function test_a_short_listing_shows_no_cut_line(): void
    {
        $this->repositories[self::INSTALLATION_ID] = [['id' => 101, 'full_name' => 'acme/api']];
        $owner = $this->signedUpUser('short');
        $project = $this->projectOf($owner);
        $this->em()->clear();

        $this->client->loginUser($owner);
        $this->signIn($project);
        $crawler = $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/settings/repositories');

        self::assertCount(0, $crawler->filter('[data-testid="github-picker-cut"]'));
    }

    public function test_an_owner_with_no_installation_sees_how_to_install(): void
    {
        $this->installations = [];
        $owner = $this->signedUpUser('noinstallation');
        $project = $this->projectOf($owner);
        $this->em()->clear();

        $this->client->loginUser($owner);
        $this->signIn($project);
        $crawler = $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/settings/repositories');

        $none = $crawler->filter('[data-testid="github-picker-none"]');
        self::assertCount(1, $none);
        self::assertStringContainsString('GitHub shows your user no installation of the Loupe App', $none->text());
        self::assertStringNotContainsString('No installation of the App yet', (string) $this->client->getResponse()->getContent());
        self::assertCount(0, $crawler->filter('[data-testid="github-picker-hidden"]'));
        self::assertCount(1, $crawler->filter('[data-testid="github-picker-install"]'));
        self::assertCount(0, $crawler->filter('[data-testid="github-picker-submit"]'));
    }

    public function test_a_stored_installation_the_user_cannot_see_is_named_and_the_empty_state_does_not_show(): void
    {
        $this->installations = [];
        $owner = $this->signedUpUser('hiddeninstallation');
        $project = $this->projectOf($owner);
        $this->em()->persist(new GitHubInstallation(self::INSTALLATION_ID, 'acme', GitHubRepositorySelection::Selected));
        $this->em()->persist(new ForgeRepository($project, 'github', '501', 'acme/kept', ForgeRepositorySource::Installation, (string) self::INSTALLATION_ID));
        $this->em()->flush();
        $this->em()->clear();

        $this->client->loginUser($owner);
        $this->signIn($project);
        $crawler = $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/settings/repositories');

        self::assertResponseIsSuccessful();
        $hidden = $crawler->filter('[data-testid="github-picker-hidden"]');
        self::assertCount(1, $hidden);
        self::assertSame((string) self::INSTALLATION_ID, $hidden->attr('data-github-installation'));
        self::assertStringContainsString('Installed on acme, but your GitHub user cannot see it.', $hidden->text());
        self::assertCount(0, $crawler->filter('[data-testid="github-picker-none"]'));
        self::assertCount(1, $crawler->filter('[data-testid="github-picker-install"]'));
    }

    public function test_a_stored_installation_the_user_sees_gets_no_hidden_line(): void
    {
        $this->repositories = [self::INSTALLATION_ID => [['id' => 501, 'full_name' => 'acme/kept']]];
        $owner = $this->signedUpUser('seeninstallation');
        $project = $this->projectOf($owner);
        $this->em()->persist(new GitHubInstallation(self::INSTALLATION_ID, 'acme', GitHubRepositorySelection::Selected));
        $this->em()->persist(new ForgeRepository($project, 'github', '501', 'acme/kept', ForgeRepositorySource::Installation, (string) self::INSTALLATION_ID));
        $this->em()->flush();
        $this->em()->clear();

        $this->client->loginUser($owner);
        $this->signIn($project);
        $crawler = $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/settings/repositories');

        self::assertCount(0, $crawler->filter('[data-testid="github-picker-hidden"]'));
        self::assertSame(['acme'], $crawler->filter('[data-testid="github-picker-account"]')->each(static fn (Crawler $node): string => trim($node->text())));
    }

    public function test_the_page_without_a_sign_in_shows_the_add_button_and_calls_no_github(): void
    {
        $owner = $this->signedUpUser('nosignin');
        $project = $this->projectOf($owner);
        $this->em()->clear();

        $this->client->loginUser($owner);
        $crawler = $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/settings/repositories');

        self::assertResponseIsSuccessful();
        $this->assertNotSignedIn($crawler, $project);
        self::assertSame([], $this->apiAuthorizations);
    }

    public function test_the_token_works_for_thirty_minutes_and_not_after(): void
    {
        $owner = $this->signedUpUser('expiry');
        $project = $this->projectOf($owner);
        $this->em()->clear();

        $this->client->loginUser($owner);
        $this->signIn($project);
        $this->clock->modify('+29 minutes');
        $crawler = $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/settings/repositories');
        self::assertCount(1, $crawler->filter('[data-testid="github-picker"]'));

        $this->clock->modify('+2 minutes');
        $calls = \count($this->apiAuthorizations);
        $crawler = $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/settings/repositories');
        $this->assertNotSignedIn($crawler, $project);
        self::assertCount($calls, $this->apiAuthorizations, 'An expired token reaches GitHub no more.');

        $this->clock->modify('-31 minutes');
        $crawler = $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/settings/repositories');
        $this->assertNotSignedIn($crawler, $project);
        self::assertCount($calls, $this->apiAuthorizations, 'The expired token was removed, not only ignored.');
    }

    public function test_a_token_of_another_project_is_not_used(): void
    {
        $owner = $this->signedUpUser('twoprojects');
        $first = $this->projectOf($owner);
        $second = $this->projectOf($owner);
        $this->em()->clear();

        $this->client->loginUser($owner);
        $this->signIn($first);
        $calls = \count($this->apiAuthorizations);
        $crawler = $this->client->request(Request::METHOD_GET, '/projects/'.$second->id.'/settings/repositories');

        $this->assertNotSignedIn($crawler, $second);
        self::assertCount($calls, $this->apiAuthorizations);
    }

    public function test_the_owner_adds_the_ticked_repositories_and_the_token_goes(): void
    {
        $audit = RecordingAuditor::installedIn(static::getContainer());
        $this->repositories[self::INSTALLATION_ID] = [
            ['id' => 101, 'full_name' => 'acme/one'],
            ['id' => 202, 'full_name' => 'acme/two'],
            ['id' => 303, 'full_name' => 'acme/three'],
        ];
        $owner = $this->signedUpUser('adds');
        $project = $this->projectOf($owner);
        $this->em()->clear();

        $this->client->loginUser($owner);
        $this->signIn($project);
        $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/settings/repositories');
        $this->submitPicks($project, ['101:acme/one', '202:acme/two']);

        self::assertResponseRedirects('/projects/'.$project->id.'/settings/repositories');
        self::assertSame(['acme/one', 'acme/two'], $this->pathsOf($project));
        $installation = $this->installationRow();
        self::assertNotNull($installation);
        self::assertSame('acme', $installation->accountLogin);
        self::assertSame(GitHubRepositorySelection::Selected, $installation->repositorySelection);
        self::assertSame(555, $installation->accountId);
        $rows = static::getContainer()->get(ForgeRepositoryRepository::class)?->findBy(['project' => $project->id]);
        self::assertNotNull($rows);
        foreach ($rows as $row) {
            self::assertSame(ForgeRepositorySource::Installation, $row->source);
            self::assertSame((string) self::INSTALLATION_ID, $row->sourceRef);
        }
        self::assertSame(AuditOutcome::Success, $audit->record('github.repositories_added')->outcome);
        self::assertStringContainsString('Loupe added 2 repositories', $this->followedText());
        self::assertSame(['Authorization: Bearer '.self::TOKEN], array_values(array_unique($this->apiAuthorizations)));

        $crawler = $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/settings/repositories');
        $this->assertNotSignedIn($crawler, $project);
    }

    public function test_a_hook_row_of_the_project_becomes_an_installation_row_when_picked(): void
    {
        $this->repositories[self::INSTALLATION_ID] = [['id' => 505, 'full_name' => 'acme/hooked']];
        $owner = $this->signedUpUser('takeover');
        $project = $this->projectOf($owner);
        $this->em()->persist(new ForgeRepository($project, 'github', '505', 'acme/hooked', ForgeRepositorySource::Hook));
        $this->em()->flush();
        $this->em()->clear();

        $this->client->loginUser($owner);
        $this->signIn($project);
        $this->submitPicks($project, ['505:acme/hooked']);

        $this->em()->clear();
        $row = static::getContainer()->get(ForgeRepositoryRepository::class)?->findOneBy(['project' => $project->id]);
        self::assertNotNull($row);
        self::assertSame(ForgeRepositorySource::Installation, $row->source);
    }

    public function test_a_repository_removed_on_github_during_the_pick_is_named_and_the_others_are_added(): void
    {
        $this->repositories[self::INSTALLATION_ID] = [['id' => 101, 'full_name' => 'acme/one'], ['id' => 202, 'full_name' => 'acme/two']];
        $owner = $this->signedUpUser('gone');
        $project = $this->projectOf($owner);
        $this->em()->clear();

        $this->client->loginUser($owner);
        $this->signIn($project);
        $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/settings/repositories');
        $this->repositories[self::INSTALLATION_ID] = [['id' => 202, 'full_name' => 'acme/two']];
        $this->submitPicks($project, ['101:acme/one', '202:acme/two']);

        self::assertResponseRedirects('/projects/'.$project->id.'/settings/repositories');
        self::assertSame(['acme/two'], $this->pathsOf($project));
        $text = $this->followedText();
        self::assertStringContainsString('Loupe added 1 repository', $text);
        self::assertStringContainsString('Loupe could not add acme/one', $text);
    }

    public function test_a_repository_taken_by_another_project_during_the_pick_is_named_and_the_others_are_added(): void
    {
        $this->repositories[self::INSTALLATION_ID] = [['id' => 101, 'full_name' => 'acme/one'], ['id' => 202, 'full_name' => 'acme/two']];
        $owner = $this->signedUpUser('race');
        $project = $this->projectOf($owner);
        $rival = $this->projectOf($this->signedUpUser('rival'));
        $this->em()->clear();

        $this->client->loginUser($owner);
        $this->signIn($project);
        $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/settings/repositories');
        $this->em()->persist(new ForgeRepository($this->em()->find(Project::class, $rival->id) ?? throw new \LogicException('The rival project exists.'), 'github', '101', 'acme/one', ForgeRepositorySource::Installation, (string) self::INSTALLATION_ID));
        $this->em()->flush();
        $this->em()->clear();
        $this->submitPicks($project, ['101:acme/one', '202:acme/two']);

        self::assertSame(['acme/two'], $this->pathsOf($project));
        $text = $this->followedText();
        self::assertStringContainsString('Loupe could not add acme/one', $text);
        self::assertStringNotContainsString($rival->name, $text);
        self::assertSame(['acme/one'], $this->pathsOf($rival));
    }

    public function test_a_forged_pick_that_the_owner_cannot_reach_is_refused(): void
    {
        $this->repositories[self::INSTALLATION_ID] = [['id' => 101, 'full_name' => 'acme/one']];
        $owner = $this->signedUpUser('forged');
        $project = $this->projectOf($owner);
        $this->em()->clear();

        $this->client->loginUser($owner);
        $this->signIn($project);
        $this->postPicks($project, [self::INSTALLATION_ID.':999:evil/secret', '777:101:acme/one']);

        self::assertResponseRedirects('/projects/'.$project->id.'/settings/repositories');
        self::assertSame([], $this->pathsOf($project));
        $text = $this->followedText();
        self::assertStringContainsString('evil/secret', $text);
        self::assertStringContainsString('acme/one', $text);
        self::assertNull($this->installationRow(777));
    }

    public function test_the_name_of_a_pick_comes_from_github_and_not_from_the_post(): void
    {
        $this->repositories[self::INSTALLATION_ID] = [['id' => 101, 'full_name' => 'acme/real']];
        $owner = $this->signedUpUser('named');
        $project = $this->projectOf($owner);
        $this->em()->clear();

        $this->client->loginUser($owner);
        $this->signIn($project);
        $this->submitPicks($project, ['101:acme/posted']);

        self::assertSame(['acme/real'], $this->pathsOf($project));
    }

    public function test_a_submit_with_nothing_ticked_adds_nothing_and_keeps_the_token(): void
    {
        $owner = $this->signedUpUser('nothing');
        $project = $this->projectOf($owner);
        $this->em()->clear();

        $this->client->loginUser($owner);
        $this->signIn($project);
        $this->postPicks($project, []);

        self::assertResponseRedirects('/projects/'.$project->id.'/settings/repositories');
        self::assertStringContainsString('Tick at least one repository', $this->followedText());
    }

    public function test_a_submit_after_the_token_expired_adds_nothing(): void
    {
        $this->repositories[self::INSTALLATION_ID] = [['id' => 101, 'full_name' => 'acme/one']];
        $owner = $this->signedUpUser('lateadd');
        $project = $this->projectOf($owner);
        $this->em()->clear();

        $this->client->loginUser($owner);
        $this->signIn($project);
        $this->clock->modify('+31 minutes');
        $calls = \count($this->apiAuthorizations);
        $this->submitPicks($project, ['101:acme/one']);

        self::assertResponseRedirects('/projects/'.$project->id.'/github/install');
        self::assertSame([], $this->pathsOf($project));
        self::assertCount($calls, $this->apiAuthorizations);
    }

    public function test_a_github_failure_on_submit_adds_nothing_and_keeps_the_token_for_a_retry(): void
    {
        $this->repositories[self::INSTALLATION_ID] = [['id' => 101, 'full_name' => 'acme/one']];
        $owner = $this->signedUpUser('submitdown');
        $project = $this->projectOf($owner);
        $this->em()->clear();

        $this->client->loginUser($owner);
        $this->signIn($project);
        $this->apiFailure = new MockResponse('{}', ['http_code' => 503]);
        $this->submitPicks($project, ['101:acme/one']);

        self::assertResponseRedirects('/projects/'.$project->id.'/settings/repositories');
        $this->apiFailure = null;
        self::assertStringContainsString('Loupe could not reach GitHub', $this->followedText());
        self::assertSame([], $this->pathsOf($project));

        $this->apiFailure = null;
        $this->submitPicks($project, ['101:acme/one']);
        self::assertSame(['acme/one'], $this->pathsOf($project));
    }

    public function test_a_github_failure_on_a_later_installation_adds_nothing_from_the_earlier_one(): void
    {
        $this->installations = [
            $this->installation(self::INSTALLATION_ID, 'acme'),
            $this->installation(7, 'beta'),
        ];
        $this->repositories = [
            self::INSTALLATION_ID => [['id' => 101, 'full_name' => 'acme/one']],
            7 => [['id' => 701, 'full_name' => 'beta/two']],
        ];
        $owner = $this->signedUpUser('latedown');
        $project = $this->projectOf($owner);
        $this->em()->clear();

        $this->client->loginUser($owner);
        $this->signIn($project);
        $this->failingRepositoryListings = [7];
        $this->postPicks($project, [self::INSTALLATION_ID.':101:acme/one', '7:701:beta/two']);

        self::assertResponseRedirects('/projects/'.$project->id.'/settings/repositories');
        self::assertSame([], $this->pathsOf($project));
        self::assertNull($this->installationRow());

        $this->failingRepositoryListings = [];
        $this->postPicks($project, [self::INSTALLATION_ID.':101:acme/one', '7:701:beta/two']);
        self::assertSame(['acme/one', 'beta/two'], $this->pathsOf($project));
    }

    public function test_cancel_removes_the_token(): void
    {
        $owner = $this->signedUpUser('cancelpick');
        $project = $this->projectOf($owner);
        $this->em()->clear();

        $this->client->loginUser($owner);
        $this->signIn($project);
        $this->postAction($this->client, '/projects/'.$project->id.'/github/repositories/cancel');

        self::assertResponseRedirects('/projects/'.$project->id.'/settings/repositories');
        $crawler = $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/settings/repositories');
        $this->assertNotSignedIn($crawler, $project);
    }

    public function test_a_fresh_install_with_one_free_repository_adds_it_at_once(): void
    {
        $this->repositories[self::INSTALLATION_ID] = [['id' => 101, 'full_name' => 'acme/api']];
        $owner = $this->signedUpUser('fresh');
        $project = $this->projectOf($owner);
        $this->em()->clear();

        $this->client->loginUser($owner);
        $this->completeInstall($project);

        self::assertResponseRedirects('/projects/'.$project->id.'/settings/repositories');
        self::assertSame(['acme/api'], $this->pathsOf($project));
        self::assertStringContainsString('Connected acme/api. Add more from Repositories.', $this->followedText());
        self::assertNotNull($this->installationRow());
        $crawler = $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/settings/repositories');
        $this->assertNotSignedIn($crawler, $project);
    }

    public function test_a_project_that_is_not_set_up_returns_to_the_setup_page_after_a_submit_a_cancel_and_a_fresh_install(): void
    {
        $this->repositories[self::INSTALLATION_ID] = [['id' => 101, 'full_name' => 'acme/api'], ['id' => 202, 'full_name' => 'acme/web']];
        $owner = $this->signedUpUser('gated-returns');
        $project = $this->projectOf($owner);
        NotSetUp::mark($this->em(), $project);
        $this->em()->clear();
        $setup = '/projects/'.$project->id.'/setup';

        $this->client->loginUser($owner);
        $this->signIn($project);
        $this->postAction($this->client, '/projects/'.$project->id.'/github/repositories/cancel');
        self::assertResponseRedirects($setup);

        $this->signIn($project);
        $this->submitPicks($project, ['101:acme/api']);
        self::assertResponseRedirects($setup);
        self::assertSame(['acme/api'], $this->pathsOf($project));
        self::assertStringContainsString('Loupe added 1 repository', $this->followedText());
    }

    public function test_a_fresh_install_into_a_project_that_is_not_set_up_ends_on_the_setup_page(): void
    {
        $this->repositories[self::INSTALLATION_ID] = [['id' => 101, 'full_name' => 'acme/api']];
        $owner = $this->signedUpUser('gated-fresh');
        $project = $this->projectOf($owner);
        NotSetUp::mark($this->em(), $project);
        $this->em()->clear();

        $this->client->loginUser($owner);
        $this->completeInstall($project);

        self::assertResponseRedirects('/projects/'.$project->id.'/setup');
        self::assertSame(['acme/api'], $this->pathsOf($project));
    }

    public function test_a_fresh_install_that_init_started_ends_on_the_hand_off_page_while_init_waits(): void
    {
        $this->repositories[self::INSTALLATION_ID] = [['id' => 101, 'full_name' => 'acme/api']];
        $owner = $this->signedUpUser('handoff-one');
        $project = $this->projectOf($owner);
        $this->em()->clear();
        $init = $this->markWait($project);

        $this->client->loginUser($owner);
        $this->completeInitInstall($project, $init);

        self::assertResponseRedirects('/projects/'.$project->id.'/github/init-handoff?repository=acme/api&init='.$init);
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-testid="github-init-handoff"] h1', 'acme/api is connected to Loupe');
        self::assertSelectorTextContains('[data-testid="github-init-handoff"]', 'Go back to your terminal.');
        self::assertSelectorExists('[data-testid="github-init-handoff"] a[href="/projects/'.$project->id.'/board"]');
        self::assertSame(['acme/api'], $this->pathsOf($project));
    }

    public function test_a_fresh_install_that_init_started_shows_the_tab_when_no_wait_mark_lives(): void
    {
        $this->repositories[self::INSTALLATION_ID] = [['id' => 101, 'full_name' => 'acme/api']];
        $owner = $this->signedUpUser('handoff-none');
        $project = $this->projectOf($owner);
        $this->em()->clear();

        $this->client->loginUser($owner);
        $this->completeInitInstall($project, (string) Uuid::v4());

        self::assertResponseRedirects('/projects/'.$project->id.'/settings/repositories');
        self::assertStringContainsString('Connected acme/api.', $this->followedText());
    }

    public function test_a_wait_mark_of_another_project_does_not_show_the_hand_off_page(): void
    {
        $this->repositories[self::INSTALLATION_ID] = [['id' => 101, 'full_name' => 'acme/api']];
        $owner = $this->signedUpUser('handoff-other');
        $project = $this->projectOf($owner);
        $this->em()->clear();
        $init = (string) Uuid::v4();
        $waits = static::getContainer()->get(InitInstallWaits::class);
        self::assertInstanceOf(InitInstallWaits::class, $waits);
        $waits->mark($init, (string) Uuid::v4());

        $this->client->loginUser($owner);
        $this->completeInitInstall($project, $init);

        self::assertResponseRedirects('/projects/'.$project->id.'/settings/repositories');
    }

    public function test_the_picker_submit_of_a_session_that_init_started_ends_on_the_hand_off_page(): void
    {
        $this->repositories[self::INSTALLATION_ID] = [
            ['id' => 101, 'full_name' => 'acme/one'],
            ['id' => 202, 'full_name' => 'acme/two'],
        ];
        $owner = $this->signedUpUser('handoff-picker');
        $project = $this->projectOf($owner);
        $this->em()->clear();
        $init = $this->markWait($project);

        $this->client->loginUser($owner);
        $authorize = $this->startSignIn($project, init: $init);
        $this->client->request(Request::METHOD_GET, '/github/app/callback?'.http_build_query(['code' => 'the-code', 'state' => $authorize['state']]));
        self::assertResponseRedirects('/projects/'.$project->id.'/settings/repositories');
        $this->submitPicks($project, ['101:acme/one', '202:acme/two']);

        self::assertResponseRedirects('/projects/'.$project->id.'/github/init-handoff?repository=acme/one,acme/two&init='.$init);
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-testid="github-init-handoff"] h1', 'acme/one, acme/two are connected to Loupe');
        $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/github/init-handoff?repository=acme%2Fnot-held&init='.$init);
        self::assertResponseRedirects('/projects/'.$project->id.'/settings/repositories');
        $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/github/init-handoff?repository=acme%2Fone');
        self::assertResponseRedirects('/projects/'.$project->id.'/settings/repositories');
        $waits = static::getContainer()->get(InitInstallWaits::class);
        self::assertInstanceOf(InitInstallWaits::class, $waits);
        $waits->clear($init);
        $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/github/init-handoff?repository=acme%2Fone&init='.$init);
        self::assertResponseRedirects('/projects/'.$project->id.'/settings/repositories');
        self::assertSame(['acme/one', 'acme/two'], $this->pathsOf($project));
    }

    public function test_the_picker_submit_of_an_ordinary_sign_in_redirects_to_the_tab(): void
    {
        $this->repositories[self::INSTALLATION_ID] = [['id' => 101, 'full_name' => 'acme/one']];
        $owner = $this->signedUpUser('handoff-plain');
        $project = $this->projectOf($owner);
        $this->em()->clear();
        $this->markWait($project);

        $this->client->loginUser($owner);
        $this->signIn($project);
        $this->submitPicks($project, ['101:acme/one']);

        self::assertResponseRedirects('/projects/'.$project->id.'/settings/repositories');
    }

    public function test_an_init_value_that_is_no_uuid_never_shows_the_hand_off_page(): void
    {
        $this->repositories[self::INSTALLATION_ID] = [['id' => 101, 'full_name' => 'acme/api']];
        $owner = $this->signedUpUser('handoff-junk');
        $project = $this->projectOf($owner);
        $this->em()->clear();

        $this->client->loginUser($owner);
        $this->completeInitInstall($project, 'not-a-uuid');

        self::assertResponseRedirects('/projects/'.$project->id.'/settings/repositories');
    }

    public function test_each_repository_that_joins_gets_a_first_check(): void
    {
        $this->repositories[self::INSTALLATION_ID] = [
            ['id' => 101, 'full_name' => 'acme/one'],
            ['id' => 202, 'full_name' => 'acme/two'],
        ];
        $owner = $this->signedUpUser('joined');
        $project = $this->projectOf($owner);
        $this->em()->clear();

        $this->client->loginUser($owner);
        $this->signIn($project);
        $this->submitPicks($project, ['101:acme/one', '202:acme/two']);

        $transport = static::getContainer()->get('messenger.transport.jobs');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $checks = [];
        foreach ($transport->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof CheckForgeRepository) {
                $checks[] = $message;
            }
        }
        self::assertCount(2, $checks);
        self::assertSame([CheckTrigger::Joined, CheckTrigger::Joined], array_map(static fn (CheckForgeRepository $check): CheckTrigger => $check->trigger, $checks));
    }

    public function test_a_fresh_install_whose_only_repository_is_taken_first_names_it_and_returns_to_the_tab(): void
    {
        $this->repositories[self::INSTALLATION_ID] = [['id' => 101, 'full_name' => 'acme/api']];
        $owner = $this->signedUpUser('freshrace');
        $project = $this->projectOf($owner);
        $rival = $this->projectOf($this->signedUpUser('freshracer'));
        $this->em()->clear();
        $listings = 0;
        $this->beforeListing = [static function (): void {}, function () use ($rival, &$listings): void {
            ++$listings;
            $this->em()->persist(new ForgeRepository($this->em()->find(Project::class, $rival->id) ?? throw new \LogicException('The rival project exists.'), 'github', '101', 'acme/api', ForgeRepositorySource::Installation, (string) self::INSTALLATION_ID));
            $this->em()->flush();
        }];

        $this->client->loginUser($owner);
        $this->completeInstall($project);

        self::assertSame(1, $listings);
        self::assertResponseRedirects('/projects/'.$project->id.'/settings/repositories');
        self::assertSame([], $this->pathsOf($project));
        self::assertStringContainsString('Loupe could not add acme/api', $this->followedText());
    }

    public function test_a_fresh_install_counts_only_the_free_repositories_of_the_new_installation(): void
    {
        $this->repositories[self::INSTALLATION_ID] = [['id' => 101, 'full_name' => 'acme/api'], ['id' => 202, 'full_name' => 'acme/held']];
        $owner = $this->signedUpUser('freshheld');
        $project = $this->projectOf($owner);
        $rival = $this->projectOf($this->signedUpUser('freshrival'));
        $this->em()->persist(new ForgeRepository($rival, 'github', '202', 'acme/held', ForgeRepositorySource::Installation, (string) self::INSTALLATION_ID));
        $this->em()->flush();
        $this->em()->clear();

        $this->client->loginUser($owner);
        $this->completeInstall($project);

        self::assertSame(['acme/api'], $this->pathsOf($project));
    }

    public function test_a_fresh_install_with_two_free_repositories_opens_the_picker(): void
    {
        $this->repositories[self::INSTALLATION_ID] = [['id' => 101, 'full_name' => 'acme/api'], ['id' => 202, 'full_name' => 'acme/web']];
        $owner = $this->signedUpUser('freshtwo');
        $project = $this->projectOf($owner);
        $this->em()->clear();

        $this->client->loginUser($owner);
        $this->completeInstall($project);

        self::assertResponseRedirects('/projects/'.$project->id.'/settings/repositories');
        self::assertSame([], $this->pathsOf($project));
        $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/settings/repositories');
        self::assertResponseIsSuccessful();
    }

    public function test_a_fresh_install_with_no_free_repository_opens_the_picker(): void
    {
        $owner = $this->signedUpUser('freshnone');
        $project = $this->projectOf($owner);
        $this->em()->clear();

        $this->client->loginUser($owner);
        $this->completeInstall($project);

        self::assertResponseRedirects('/projects/'.$project->id.'/settings/repositories');
    }

    public function test_a_cut_listing_never_counts_as_exactly_one_free_repository(): void
    {
        $this->repositories[self::INSTALLATION_ID] = array_map(static fn (int $id): array => ['id' => $id, 'full_name' => 'acme/repository-'.$id], range(1, 1000));
        $owner = $this->signedUpUser('freshcut');
        $project = $this->projectOf($owner);
        $rival = $this->projectOf($this->signedUpUser('freshcutrival'));
        for ($id = 2; $id <= 1000; ++$id) {
            $this->em()->persist(new ForgeRepository($rival, 'github', (string) $id, 'acme/repository-'.$id, ForgeRepositorySource::Installation, (string) self::INSTALLATION_ID));
        }
        $this->em()->flush();
        $this->em()->clear();

        $this->client->loginUser($owner);
        $this->completeInstall($project);

        self::assertResponseRedirects('/projects/'.$project->id.'/settings/repositories');
        self::assertSame([], $this->pathsOf($project));
    }

    public function test_a_fresh_install_the_user_cannot_reach_is_refused(): void
    {
        $this->installations = [$this->installation(99, 'someone-else')];
        $owner = $this->signedUpUser('spoofed');
        $project = $this->projectOf($owner);
        $this->em()->clear();

        $this->client->loginUser($owner);
        $this->completeInstall($project);

        self::assertResponseRedirects('/projects/'.$project->id.'/settings/repositories');
        self::assertStringContainsString('GitHub does not list this installation for your account', $this->followedText());
        self::assertNull($this->installationRow());
        $crawler = $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/settings/repositories');
        $this->assertNotSignedIn($crawler, $project);
    }

    public function test_a_known_installation_record_is_refreshed_when_a_repository_is_picked(): void
    {
        $known = new GitHubInstallation(self::INSTALLATION_ID, 'old-login', GitHubRepositorySelection::All);
        $known->removedAt = new \DateTimeImmutable('-1 day');
        $this->em()->persist($known);
        $this->em()->flush();
        $this->repositories[self::INSTALLATION_ID] = [['id' => 101, 'full_name' => 'acme/one']];
        $owner = $this->signedUpUser('refresh');
        $project = $this->projectOf($owner);
        $this->em()->clear();

        $this->client->loginUser($owner);
        $this->signIn($project);
        $this->submitPicks($project, ['101:acme/one']);

        $installation = $this->installationRow();
        self::assertNotNull($installation);
        self::assertSame('acme', $installation->accountLogin);
        self::assertNull($installation->removedAt);
        self::assertSame(['acme/one'], $this->pathsOf($project));
    }

    /** A second project picks from an installation that another project already uses. */
    public function test_a_second_project_picks_from_a_shared_installation(): void
    {
        $other = $this->projectOf($this->signedUpUser('otherowner'));
        $this->em()->persist(new GitHubInstallation(self::INSTALLATION_ID, 'acme', GitHubRepositorySelection::All));
        $this->em()->persist(new ForgeRepository($other, 'github', '102', 'acme/two', ForgeRepositorySource::Installation, (string) self::INSTALLATION_ID));
        $this->em()->flush();
        $this->repositories[self::INSTALLATION_ID] = [['id' => 101, 'full_name' => 'acme/one'], ['id' => 102, 'full_name' => 'acme/two']];
        $owner = $this->signedUpUser('second');
        $project = $this->projectOf($owner);
        $this->em()->clear();

        $this->client->loginUser($owner);
        $this->signIn($project);
        $this->submitPicks($project, ['101:acme/one']);

        self::assertSame(['acme/one'], $this->pathsOf($project));
        self::assertSame(['acme/two'], $this->pathsOf($other));
    }

    /** @return iterable<string, array{0: ?MockResponse, 1: ?MockResponse}> */
    public static function gitHubFailures(): iterable
    {
        yield 'token endpoint down' => [new MockResponse('oops', ['http_code' => 502]), null];
        yield 'token endpoint times out' => [new MockResponse('', ['error' => 'Idle timeout reached']), null];
        yield 'token refused' => [new MockResponse('{"error":"bad_verification_code"}', ['response_headers' => ['content-type' => 'application/json']]), null];
        yield 'token body is not JSON' => [new MockResponse('<html>', ['response_headers' => ['content-type' => 'text/html']]), null];
        yield 'api down' => [null, new MockResponse('{}', ['http_code' => 503])];
        yield 'api times out' => [null, new MockResponse('', ['error' => 'Idle timeout reached'])];
        yield 'api body is not JSON' => [null, new MockResponse('<html>', ['response_headers' => ['content-type' => 'text/html']])];
    }

    /** A fresh install calls GitHub twice, so a failure of either call is covered. */
    #[\PHPUnit\Framework\Attributes\DataProvider('gitHubFailures')]
    public function test_a_github_failure_redirects_with_a_message_adds_nothing_and_logs_no_token(?MockResponse $tokenFailure, ?MockResponse $apiFailure): void
    {
        if (null !== $tokenFailure) {
            $this->tokenResponse = $tokenFailure;
        }
        $this->apiFailure = $apiFailure;
        $logger = $this->recordingHandlerLogger();
        $owner = $this->signedUpUser('failure');
        $project = $this->projectOf($owner);
        $this->em()->clear();

        $this->client->loginUser($owner);
        $this->completeInstall($project);

        self::assertResponseRedirects('/projects/'.$project->id.'/settings/repositories');
        self::assertStringContainsString('Loupe could not reach GitHub', $this->followedText());
        self::assertSame([], $this->pathsOf($project));
        self::assertNull($this->installationRow());
        $warnings = array_values(array_filter($logger->records, static fn (array $record): bool => 'warning' === $record['level']));
        self::assertCount(1, $warnings);
        self::assertSame('github.user_api_failed', $warnings[0]['message']);
        self::assertStringNotContainsString(self::TOKEN, (string) json_encode($logger->records));
        self::assertStringNotContainsString('the-client-secret', (string) json_encode($logger->records));
        $this->apiFailure = null;
        $crawler = $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/settings/repositories');
        $this->assertNotSignedIn($crawler, $project);
    }

    public function test_a_github_failure_on_the_settings_page_says_so_and_ends_the_sign_in(): void
    {
        $owner = $this->signedUpUser('pagedown');
        $project = $this->projectOf($owner);
        $this->em()->clear();

        $this->client->loginUser($owner);
        $this->signIn($project);
        $this->apiFailure = new MockResponse('{}', ['http_code' => 503]);
        $crawler = $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/settings/repositories');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Loupe could not reach GitHub', $crawler->filter('#repositories [data-testid="github-picker-failed"]')->text());
        self::assertCount(0, $crawler->filter('[data-testid="github-picker"]'));
        $this->apiFailure = null;
        $calls = \count($this->apiAuthorizations);
        $crawler = $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/settings/repositories');
        $this->assertNotSignedIn($crawler, $project);
        self::assertCount($calls, $this->apiAuthorizations, 'The failure removed the sign-in.');
    }

    /** The page offers Add repositories, which starts the sign-in, and lists nothing from GitHub. */
    private function assertNotSignedIn(Crawler $crawler, Project $project): void
    {
        self::assertCount(0, $crawler->filter('[data-testid="github-picker"]'));
        self::assertCount(0, $crawler->filter('[data-testid="github-picker-failed"]'));
        $add = 'a[href="/projects/'.$project->id.'/github/install"]';
        self::assertCount(1, $crawler->filter('#repositories '.$add)->reduce(static fn (Crawler $link): bool => null === $link->closest('template')));
    }

    /** @return array<int|string, string> */
    private function startSignIn(Project $project, ?string $repository = null, ?string $from = null, ?string $init = null): array
    {
        $query = array_filter(['repository' => $repository, 'from' => $from, 'init' => $init], static fn (?string $value): bool => null !== $value);
        $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/github/install'.([] === $query ? '' : '?'.http_build_query($query)));
        self::assertResponseRedirects();
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringStartsWith('https://github.com/login/oauth/authorize?', $location);

        return $this->queryOf($location);
    }

    private function signIn(Project $project, ?string $repository = null): void
    {
        $authorize = $this->startSignIn($project, $repository);
        $this->client->request(Request::METHOD_GET, '/github/app/callback?'.http_build_query(['code' => 'the-code', 'state' => $authorize['state']]));
        self::assertResponseRedirects('/projects/'.$project->id.'/settings/repositories');
    }

    /** Marks the wait of a new init session, as its launch PUT does. */
    private function markWait(Project $project): string
    {
        $init = (string) Uuid::v4();
        $waits = static::getContainer()->get(InitInstallWaits::class);
        self::assertInstanceOf(InitInstallWaits::class, $waits);
        $waits->mark($init, (string) $project->id);

        return $init;
    }

    private function startInstall(Project $project): string
    {
        $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/github/install/account');
        self::assertResponseRedirects();
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringStartsWith('https://github.com/apps/loupe-test/installations/new?', $location);
        $query = $this->queryOf($location);
        self::assertArrayHasKey('state', $query);

        return $query['state'];
    }

    /** @return array<int|string, string> */
    private function setUpInstallation(string $installState): array
    {
        $this->client->request(Request::METHOD_GET, '/github/app/setup?'.http_build_query(['installation_id' => self::INSTALLATION_ID, 'setup_action' => 'install', 'state' => $installState]));
        self::assertResponseRedirects();
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringStartsWith('https://github.com/login/oauth/authorize?', $location);

        return $this->queryOf($location);
    }

    /** Runs the whole install flow up to the callback. */
    private function completeInstall(Project $project): void
    {
        $authorize = $this->setUpInstallation($this->startInstall($project));
        $this->client->request(Request::METHOD_GET, '/github/app/callback?'.http_build_query(['code' => 'the-code', 'state' => $authorize['state']]));
    }

    /** The install that `loupe init` links to: the sign-in finds the repository missing, the install adds it. */
    private function completeInitInstall(Project $project, string $init): void
    {
        $repositories = $this->repositories[self::INSTALLATION_ID];
        $this->repositories[self::INSTALLATION_ID] = [];
        $authorize = $this->startSignIn($project, 'acme/api', init: $init);
        $this->client->request(Request::METHOD_GET, '/github/app/callback?'.http_build_query(['code' => 'the-code', 'state' => $authorize['state']]));
        self::assertResponseRedirects();
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringStartsWith('https://github.com/apps/loupe-test/installations/new?', $location);

        $this->repositories[self::INSTALLATION_ID] = $repositories;
        $authorize = $this->setUpInstallation($this->queryOf($location)['state']);
        $this->client->request(Request::METHOD_GET, '/github/app/callback?'.http_build_query(['code' => 'the-code', 'state' => $authorize['state']]));
    }

    /** Follows the install that began on github.com: the setup return, then the callback of the GitHub sign-in. */
    private function githubFirstSignIn(string $action, int $installationId = self::INSTALLATION_ID): void
    {
        $this->client->request(Request::METHOD_GET, '/github/app/setup?'.http_build_query(['installation_id' => $installationId, 'setup_action' => $action]));
        self::assertResponseRedirects();
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringStartsWith('https://github.com/login/oauth/authorize?', $location);
        $authorize = $this->queryOf($location);
        $this->client->request(Request::METHOD_GET, '/github/app/callback?'.http_build_query(['code' => 'the-code', 'state' => $authorize['state']]));
    }

    private function postChoice(string $project, string $name): void
    {
        $this->client->request(
            Request::METHOD_POST,
            '/github/app/project',
            ['choose_install_project_form' => ['project' => $project, 'name' => $name, '_token' => 'csrf-token']],
            server: ['HTTP_ORIGIN' => 'http://localhost'],
        );
    }

    private function projectNamed(string $name): Project
    {
        return $this->projectNamedOrNull($name) ?? throw new \LogicException('No project is named '.$name);
    }

    private function projectNamedOrNull(string $name): ?Project
    {
        $this->em()->clear();

        $projects = static::getContainer()->get(ProjectRepository::class);
        self::assertInstanceOf(ProjectRepository::class, $projects);

        return $projects->findOneBy(['name' => $name]);
    }

    /** @param list<string> $picks `installationId:repositoryId:path`, as the tickboxes post them */
    private function postPicks(Project $project, array $picks): void
    {
        $this->client->request(
            Request::METHOD_POST,
            '/projects/'.$project->id.'/github/repositories',
            ['pick_repositories_form' => ['repositories' => $picks, '_token' => 'csrf-token']],
            server: ['HTTP_ORIGIN' => 'http://localhost'],
        );
    }

    /** @param list<string> $picks `repositoryId:path`, in installation 424242 */
    private function submitPicks(Project $project, array $picks): void
    {
        $this->postPicks($project, array_map(static fn (string $pick): string => self::INSTALLATION_ID.':'.$pick, $picks));
    }

    /** @return array<string, array{state: string, tickable: bool, checked: bool, text: string}> */
    private function pickerRows(Crawler $crawler): array
    {
        $rows = [];
        $crawler->filter('[data-testid="github-picker-repository"]')->each(static function (Crawler $row) use (&$rows): void {
            $box = $row->filter('input[type="checkbox"]');
            $name = trim($row->filter('.lp-picker-repo__name')->text());
            $rows[$name] = [
                'state' => (string) $row->attr('data-state'),
                'tickable' => 1 === $box->count() && null === $box->attr('disabled'),
                'checked' => 1 === $box->count() && null !== $box->attr('checked'),
                'text' => trim($row->text()),
            ];
        });

        return $rows;
    }

    /** @return array<int|string, string> */
    private function queryOf(string $url): array
    {
        parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);

        return array_map(static fn (mixed $value): string => \is_string($value) ? $value : '', $query);
    }

    private function followedText(): string
    {
        return $this->client->followRedirect()->filter('body')->text();
    }

    /** @return array<string, mixed> */
    private function installation(int $id, string $login, ?string $htmlUrl = null): array
    {
        return [
            'id' => $id,
            'account' => ['login' => $login, 'id' => 555],
            'repository_selection' => 'selected',
            'html_url' => $htmlUrl ?? 'https://github.com/settings/installations/'.$id,
        ];
    }

    /** @param array<string, mixed> $body */
    private function json(array $body): MockResponse
    {
        return new MockResponse((string) json_encode($body), ['response_headers' => ['content-type' => 'application/json']]);
    }

    private function installationRow(int $installationId = self::INSTALLATION_ID): ?GitHubInstallation
    {
        $this->em()->clear();
        $installations = static::getContainer()->get(GitHubInstallationRepository::class);
        self::assertInstanceOf(GitHubInstallationRepository::class, $installations);

        return $installations->findOneByInstallationId($installationId);
    }

    /** @return list<string> */
    private function pathsOf(Project $project): array
    {
        $this->em()->clear();
        $repositories = static::getContainer()->get(ForgeRepositoryRepository::class);
        self::assertInstanceOf(ForgeRepositoryRepository::class, $repositories);
        $paths = array_map(static fn (ForgeRepository $repository): string => $repository->path, $repositories->findBy(['project' => $project->id]));
        sort($paths);

        return $paths;
    }

    /** Rebuilds the sign-in and add handlers around a recording logger, so a test reads every line they log. */
    private function recordingHandlerLogger(): RecordingLogger
    {
        $container = static::getContainer();
        $logger = new RecordingLogger();
        $get = static function (string $id) use ($container): object {
            $service = $container->get($id);
            self::assertIsObject($service);

            return $service;
        };
        $add = new AddGitHubRepositoriesHandler(
            self::narrow($get(GitHubInstallSession::class), GitHubInstallSession::class),
            self::narrow($get(GitHubUserApi::class), GitHubUserApi::class),
            self::narrow($get(\App\Module\GitHub\Service\GitHubInstallationRecords::class), \App\Module\GitHub\Service\GitHubInstallationRecords::class),
            self::narrow($get(\App\Module\GitHub\Service\DeliveryAnnouncer::class), \App\Module\GitHub\Service\DeliveryAnnouncer::class),
            self::narrow($get(Auditor::class), Auditor::class),
            $logger,
            self::narrow($get('messenger.default_bus'), \Symfony\Component\Messenger\MessageBusInterface::class),
        );
        $container->set(AddGitHubRepositoriesHandler::class, $add);
        $container->set(SignInGitHubPickerHandler::class, new SignInGitHubPickerHandler(
            self::narrow($get(ProjectRepository::class), ProjectRepository::class),
            self::narrow($get('security.authorization_checker'), AuthorizationCheckerInterface::class),
            self::narrow($get(GitHubUserApi::class), GitHubUserApi::class),
            self::narrow($get(GitHubInstallSession::class), GitHubInstallSession::class),
            self::narrow($get(GitHubPickerListing::class), GitHubPickerListing::class),
            $add,
            self::narrow($get(\App\Module\GitHub\Command\StartGitHubAppInstallHandler::class), \App\Module\GitHub\Command\StartGitHubAppInstallHandler::class),
            $logger,
        ));

        return $logger;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private static function narrow(object $service, string $class): object
    {
        self::assertInstanceOf($class, $service);

        return $service;
    }
}
