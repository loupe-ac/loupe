<?php

declare(strict_types=1);

namespace App\Tests\Module\GitHub\Twig\Components;

use App\Module\Account\Entity\ConnectedAccount;
use App\Module\Account\Entity\SocialProvider;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Forge\Entity\ForgeRepository;
use App\Module\Forge\Entity\ForgeRepositorySource;
use App\Module\GitHub\Entity\GitHubInstallation;
use App\Module\GitHub\Entity\GitHubRepositorySelection;
use App\Module\GitHub\Entity\GitHubUserConnection;
use App\Module\GitHub\Service\GitHubAppConfiguration;
use App\Module\GitHub\Service\HookSecretKey;
use App\Module\Project\Entity\Project;
use App\Tests\Module\GitHub\Controller\GitHubConnectionsScenario;
use App\Tests\Support\AgentCredential;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;
use Ubermuda\DoctrineExtra\Encryption\EncryptionKeyProvider;

final class GitIdentityComponentTest extends WebTestCase
{
    use GitHubConnectionsScenario;

    public function test_a_stranger_cannot_see_the_panel(): void
    {
        $client = static::createClient();
        $project = $this->projectOf($this->signedUpUser('gistranger-owner'));
        $stranger = $this->signedUpUser('gistranger');
        $this->em()->clear();

        $client->loginUser($stranger);
        $this->connectPage($client, $project);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function test_the_switch_is_on_for_a_managed_project_and_off_for_a_project_that_keeps_its_own_setup(): void
    {
        $client = static::createClient();
        $owner = $this->signedUpUser('giswitch');
        $managed = $this->projectOf($owner);
        $unmanaged = $this->projectOf($owner);
        $unmanaged->gitIdentityManaged = false;
        $this->em()->flush();
        $this->em()->clear();

        $client->loginUser($owner);
        $crawler = $this->connectPage($client, $managed);
        self::assertCount(1, $crawler->filter('#git-identity input[type="checkbox"][role="switch"][checked]'));
        self::assertStringContainsString('Agents push as the Loupe app', $crawler->filter('#git-identity')->text());

        $crawler = $this->connectPage($client, $unmanaged);
        self::assertCount(1, $crawler->filter('#git-identity input[type="checkbox"][role="switch"]'));
        self::assertCount(0, $crawler->filter('#git-identity input[type="checkbox"][role="switch"][checked]'));
        self::assertStringContainsString('Agents use the git setup of each machine', $crawler->filter('#git-identity')->text());
    }

    public function test_a_managed_app_bot_machine_shows_the_app_line(): void
    {
        [$client, $project] = $this->managedProjectWith('giapp', [['mode' => 'app_token', 'commitName' => 'loupe[bot]', 'pushLogin' => 'loupe[bot]', 'githubUserId' => null]]);

        $line = $this->connectPage($client, $project)->filter('[data-git-identity="app-bot"]');

        self::assertCount(1, $line);
        self::assertStringContainsString('Pushes as the Loupe app', $line->text());
        self::assertStringContainsString('Managed', $line->text());
    }

    public function test_a_managed_agent_account_machine_shows_the_account_line(): void
    {
        [$client, $project] = $this->managedProjectWith('giagent', [['mode' => 'agent_account', 'commitName' => 'Acme Agent', 'pushLogin' => 'acme-agent', 'githubUserId' => null]]);

        $line = $this->connectPage($client, $project)->filter('[data-git-identity="agent-account"]');

        self::assertCount(1, $line);
        self::assertStringContainsString('Pushes as acme-agent, the agent account set on this machine', $line->text());
        self::assertSame('acme-agent', $line->filter('code')->text());
    }

    public function test_an_agent_account_that_github_did_not_confirm_is_not_shown_as_managed(): void
    {
        [$client, $project] = $this->managedProjectWith('giagentfail', [['mode' => 'agent_account', 'commitName' => 'Acme Agent', 'pushLogin' => '', 'githubUserId' => null]]);

        $crawler = $this->connectPage($client, $project);
        $line = $crawler->filter('[data-git-identity="agent-account-unverified"]');

        self::assertCount(0, $crawler->filter('[data-git-identity="agent-account"]'));
        self::assertCount(1, $line);
        self::assertStringContainsString('GitHub did not confirm its token', $line->text());
        self::assertStringContainsString('Check', $line->text());
        self::assertStringNotContainsString('Managed', $line->text());
    }

    public function test_a_managed_project_still_warns_about_a_machine_that_pushes_as_the_owner(): void
    {
        [$client, $project] = $this->managedProjectWith('gimachine', [['mode' => 'machine', 'commitName' => 'Riley', 'pushLogin' => 'riley-dev', 'githubUserId' => 42]], ownerLogin: 'riley-dev', ownerGitHubId: 42);

        $crawler = $this->connectPage($client, $project);

        self::assertCount(1, $crawler->filter('[data-git-identity="owner-account"]'));
        self::assertStringContainsString('You cannot approve its pull requests.', $crawler->filter('[data-testid="git-identity-warning"]')->text());
    }

    public function test_a_managed_project_shows_a_bridge_on_an_older_cli_as_not_managed(): void
    {
        [$client, $project] = $this->managedProjectWith('giolder', null);

        $crawler = $this->connectPage($client, $project);

        $line = $crawler->filter('[data-git-identity="not-managed"]');
        self::assertCount(1, $line);
        self::assertStringContainsString('This machine runs an older CLI', $line->text());
        self::assertStringContainsString('Not managed', $line->text());
        self::assertCount(0, $crawler->filter('[data-testid="git-identity-warning"]'));
        self::assertCount(0, $crawler->filter('[data-testid="git-identity-empty"]'));
    }

    public function test_an_unmanaged_project_shows_no_line_for_a_bridge_on_an_older_cli(): void
    {
        [$client, $project] = $this->managedProjectWith('giolderoff', null, managed: false);

        self::assertCount(1, $this->connectPage($client, $project)->filter('[data-testid="git-identity-empty"]'));
    }

    public function test_a_bridge_that_reports_no_row_for_the_project_shows_no_line(): void
    {
        [$client, $project] = $this->managedProjectWith('ginorow', []);

        self::assertCount(1, $this->connectPage($client, $project)->filter('[data-testid="git-identity-empty"]'));
    }

    public function test_only_the_bot_of_the_configured_app_is_the_loupe_app(): void
    {
        [$client, $project] = $this->managedProjectWith('gibots', [['mode' => 'machine', 'commitName' => 'Bot', 'pushLogin' => 'dependabot[bot]', 'githubUserId' => null]], managed: false);
        static::getContainer()->set(GitHubAppConfiguration::class, new GitHubAppConfiguration('loupe', 'client', 'secret', 'hook', null, null));

        $crawler = $this->connectPage($client, $project);

        self::assertCount(0, $crawler->filter('[data-git-identity="app-bot"]'));
        self::assertCount(1, $crawler->filter('[data-git-identity="maybe-owner-account"]'));
    }

    public function test_a_machine_that_pushes_as_the_configured_app_bot_is_the_loupe_app(): void
    {
        [$client, $project] = $this->managedProjectWith('giownbot', [['mode' => 'machine', 'commitName' => 'Bot', 'pushLogin' => 'Loupe[bot]', 'githubUserId' => null]], managed: false);
        static::getContainer()->set(GitHubAppConfiguration::class, new GitHubAppConfiguration('loupe', 'client', 'secret', 'hook', null, null));

        self::assertCount(1, $this->connectPage($client, $project)->filter('[data-git-identity="app-bot"]'));
    }

    public function test_a_machine_that_pushes_as_the_owner_gets_the_certain_warning(): void
    {
        [$client, $project] = $this->managedProjectWith('giowner', [['mode' => 'machine', 'commitName' => 'Riley', 'pushLogin' => 'RILEY-dev', 'githubUserId' => null]], managed: false, ownerLogin: 'riley-dev', ownerGitHubId: 42);

        $crawler = $this->connectPage($client, $project);

        self::assertCount(1, $crawler->filter('[data-git-identity="owner-account"]'));
        $warning = $crawler->filter('[data-testid="git-identity-warning"]');
        self::assertStringContainsString('This machine pushes as your GitHub account. You cannot approve its pull requests.', $warning->text());
        self::assertStringContainsString('Check', $crawler->filter('[data-git-identity="owner-account"]')->text());
        self::assertStringEndsWith('/getting-started/agent-github-account/', (string) $warning->filter('[data-testid="git-identity-guide"]')->attr('href'));
    }

    public function test_the_owner_match_prefers_the_numeric_id_over_the_login(): void
    {
        [$client, $project] = $this->managedProjectWith('giid', [['mode' => 'machine', 'commitName' => 'Riley', 'pushLogin' => 'renamed-login', 'githubUserId' => 42]], managed: false, ownerLogin: 'riley-dev', ownerGitHubId: 42);

        self::assertCount(1, $this->connectPage($client, $project)->filter('[data-git-identity="owner-account"]'));
    }

    public function test_a_github_sign_in_id_matches_a_reported_id(): void
    {
        [$client, $project] = $this->managedProjectWith('gisignin', [['mode' => 'machine', 'commitName' => 'Riley', 'pushLogin' => 'riley-dev', 'githubUserId' => 99]], managed: false, signInId: '99');

        self::assertCount(1, $this->connectPage($client, $project)->filter('[data-git-identity="owner-account"]'));
    }

    public function test_without_a_held_github_login_the_warning_is_conditional(): void
    {
        [$client, $project] = $this->managedProjectWith('gimaybe', [['mode' => 'machine', 'commitName' => 'Riley', 'pushLogin' => 'riley-dev', 'githubUserId' => 5]], managed: false);

        $crawler = $this->connectPage($client, $project);

        self::assertCount(1, $crawler->filter('[data-git-identity="maybe-owner-account"]'));
        $warning = $crawler->filter('[data-testid="git-identity-warning"]');
        self::assertStringContainsString('This machine pushes as riley-dev. If this is your own account, you cannot approve its pull requests.', $warning->text());
        self::assertStringNotContainsString('your GitHub account', $warning->text());
        self::assertStringEndsWith('/getting-started/agent-github-account/', (string) $warning->filter('[data-testid="git-identity-guide"]')->attr('href'));
    }

    public function test_a_login_that_differs_from_the_held_owner_login_gets_the_conditional_warning(): void
    {
        [$client, $project] = $this->managedProjectWith('gidiffers', [['mode' => 'machine', 'commitName' => 'Sam', 'pushLogin' => 'sam-ci', 'githubUserId' => 8]], managed: false, ownerLogin: 'riley-dev', ownerGitHubId: 42);

        $crawler = $this->connectPage($client, $project);

        self::assertCount(1, $crawler->filter('[data-git-identity="maybe-owner-account"]'));
        $warning = $crawler->filter('[data-testid="git-identity-warning"]');
        self::assertStringContainsString('If this is your own account', $warning->text());
        self::assertCount(1, $warning->filter('[data-testid="git-identity-guide"]'));
    }

    public function test_a_machine_that_gave_no_login_is_unknown(): void
    {
        [$client, $project] = $this->managedProjectWith('giunknown', [['mode' => 'machine', 'commitName' => 'Riley', 'pushLogin' => '', 'githubUserId' => null]], managed: false);

        $line = $this->connectPage($client, $project)->filter('[data-git-identity="unknown"]');

        self::assertCount(1, $line);
        self::assertStringContainsString('The push account is unknown', $line->text());
        self::assertStringContainsString('Unknown', $line->text());
    }

    public function test_the_project_agent_login_on_an_unmanaged_machine_is_detected_without_a_warning(): void
    {
        [$client, $project] = $this->managedProjectWith('gikinds', [['mode' => 'machine', 'commitName' => 'A', 'pushLogin' => 'Acme-Agent', 'githubUserId' => null]], managed: false, agentLogin: 'acme-agent');

        $crawler = $this->connectPage($client, $project);

        $line = $crawler->filter('[data-git-identity="detected"]');
        self::assertCount(1, $line);
        self::assertStringContainsString('Detected', $line->text());
        self::assertCount(0, $crawler->filter('[data-testid="git-identity-warning"]'));
    }

    public function test_a_quiet_bridge_is_left_out(): void
    {
        [$client, $project] = $this->managedProjectWith('giquiet', [['mode' => 'app_token', 'commitName' => 'x', 'pushLogin' => '', 'githubUserId' => null]], lastSeenAt: new \DateTimeImmutable('-3 days'));

        $crawler = $this->connectPage($client, $project);

        self::assertCount(0, $crawler->filter('[data-testid="git-identity-line"]'));
        self::assertCount(1, $crawler->filter('[data-testid="git-identity-empty"]'));
    }

    public function test_a_refusal_offers_the_install_button_and_a_turn_off_button(): void
    {
        $client = static::createClient();
        $owner = $this->signedUpUser('giinstall');
        $project = $this->projectOf($owner);
        $this->em()->clear();
        static::getContainer()->set(GitHubAppConfiguration::class, new GitHubAppConfiguration('loupe', 'client', 'secret', 'hook', '123456', 'key'));

        $client->loginUser($owner);
        $crawler = $this->connectPage($client, $project);

        self::assertCount(1, $crawler->filter('#git-identity [data-testid="git-identity-install"]'));
        self::assertCount(1, $crawler->filter('#git-identity [data-testid="git-identity-turn-off"]'));
    }

    public function test_a_managed_project_with_no_usable_installation_says_its_agents_cannot_push(): void
    {
        $client = static::createClient();
        $owner = $this->signedUpUser('pushrefused');
        $project = $this->projectOf($owner);
        $this->em()->flush();
        $this->em()->clear();
        static::getContainer()->set(GitHubAppConfiguration::class, new GitHubAppConfiguration('loupe', 'client', 'secret', 'hook', '123456', 'key'));

        $client->loginUser($owner);
        $crawler = $this->connectPage($client, $project);

        $notice = $crawler->filter('#git-identity [data-testid="github-push-refused"]');
        self::assertCount(1, $notice);
        self::assertStringContainsString('Agents of this project cannot push.', $notice->text());
        self::assertStringContainsString('not installed', $notice->text());
    }

    public function test_a_managed_project_on_an_instance_with_no_app_says_its_agents_cannot_push(): void
    {
        $client = static::createClient();
        $owner = $this->signedUpUser('pushnoapp');
        $project = $this->projectOf($owner);
        $this->em()->clear();
        static::getContainer()->set(GitHubAppConfiguration::class, new GitHubAppConfiguration(null, null, null, null, null, null));
        static::getContainer()->set(HookSecretKey::class, new HookSecretKey(new EncryptionKeyProvider('')));

        $client->loginUser($owner);
        $crawler = $this->connectPage($client, $project);

        $notice = $crawler->filter('#git-identity [data-testid="github-push-refused"]');
        self::assertCount(1, $notice);
        self::assertStringContainsString('Agents of this project cannot push.', $notice->text());
        self::assertStringContainsString('has not finished the setup of the GitHub App', $notice->text());
    }

    public function test_an_installed_project_with_no_app_signing_key_names_the_setup_problem(): void
    {
        $client = static::createClient();
        $owner = $this->signedUpUser('pushnokey');
        $project = $this->projectOf($owner);
        $this->em()->persist(new GitHubInstallation($project, 15, 'acme', GitHubRepositorySelection::Selected));
        $this->em()->persist(new ForgeRepository($project, 'github', '9015', 'acme/site', ForgeRepositorySource::Installation, '15'));
        $this->em()->flush();
        $this->em()->clear();
        static::getContainer()->set(GitHubAppConfiguration::class, new GitHubAppConfiguration('loupe', 'client', 'secret', 'hook', null, null));

        $client->loginUser($owner);
        $crawler = $this->connectPage($client, $project);

        $notice = $crawler->filter('#git-identity [data-testid="github-push-refused"]');
        self::assertCount(1, $notice);
        self::assertStringContainsString('has not finished the setup of the GitHub App', $notice->text());
        self::assertStringNotContainsString('GitHub did not answer', $notice->text());
    }

    public function test_a_project_with_no_repository_and_no_app_signing_key_names_the_setup_problem(): void
    {
        $client = static::createClient();
        $owner = $this->signedUpUser('pushnokeybare');
        $project = $this->projectOf($owner);
        $this->em()->flush();
        $this->em()->clear();
        static::getContainer()->set(GitHubAppConfiguration::class, new GitHubAppConfiguration('loupe', 'client', 'secret', 'hook', null, null));

        $client->loginUser($owner);
        $crawler = $this->connectPage($client, $project);

        $notice = $crawler->filter('#git-identity [data-testid="github-push-refused"]');
        self::assertCount(1, $notice);
        self::assertStringContainsString('has not finished the setup of the GitHub App', $notice->text());
        self::assertStringNotContainsString('not installed', $notice->text());
    }

    public function test_a_project_that_keeps_its_own_git_setup_shows_no_push_notice(): void
    {
        $client = static::createClient();
        $owner = $this->signedUpUser('pushunmanaged');
        $project = $this->projectOf($owner);
        $project->gitIdentityManaged = false;
        $this->em()->flush();
        $this->em()->clear();
        static::getContainer()->set(GitHubAppConfiguration::class, new GitHubAppConfiguration('loupe', 'client', 'secret', 'hook', null, null));

        $client->loginUser($owner);
        $crawler = $this->connectPage($client, $project);

        self::assertCount(0, $crawler->filter('#git-identity [data-testid="github-push-refused"]'));
    }

    /**
     * @param list<array{mode: 'agent_account'|'app_token'|'machine', commitName: string, pushLogin: string, githubUserId: ?int}>|null $rows null for a bridge on an older CLI
     *
     * @return array{\Symfony\Bundle\FrameworkBundle\KernelBrowser, Project}
     */
    private function managedProjectWith(string $label, ?array $rows, bool $managed = true, ?string $ownerLogin = null, ?int $ownerGitHubId = null, ?string $signInId = null, ?string $agentLogin = null, ?\DateTimeImmutable $lastSeenAt = null): array
    {
        $client = static::createClient();
        $owner = $this->signedUpUser($label);
        $project = $this->projectOf($owner);
        $project->gitIdentityManaged = $managed;
        $project->agentGitHubLogin = $agentLogin;
        if (null !== $ownerLogin && null !== $ownerGitHubId) {
            $this->em()->persist(new GitHubUserConnection($owner, $ownerGitHubId, $ownerLogin, 'a', 'r', new \DateTimeImmutable('+1 hour'), new \DateTimeImmutable('+1 day')));
        }
        if (null !== $signInId) {
            $this->em()->persist(new ConnectedAccount($owner, SocialProvider::Github, $signInId));
        }
        $bridge = new Bridge(AgentCredential::managed($this->em(), $owner, $owner->id), Uuid::v4(), [(string) $project->id], 'b4e39aa7', $lastSeenAt ?? new \DateTimeImmutable());
        if (null !== $rows) {
            $bridge->gitIdentities = array_map(static fn (array $row): array => ['projectId' => (string) $project->id] + $row, $rows);
            $bridge->gitIdentitiesReportedAt = new \DateTimeImmutable('-12 seconds');
        }
        $this->em()->persist($bridge);
        $this->em()->flush();
        $this->em()->clear();

        $client->loginUser($owner);

        return [$client, $project];
    }
}
