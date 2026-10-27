<?php

declare(strict_types=1);

namespace App\Tests\Module\GitHub\Twig\Components;

use App\Module\Forge\Entity\ForgeRepository;
use App\Module\Forge\Entity\ForgeRepositoryCheck;
use App\Module\Forge\Messenger\CheckForgeRepository;
use App\Module\Forge\Messenger\CheckTrigger;
use App\Module\Forge\Service\RepositorySuggestionCatalog;
use App\Module\Forge\Settings\RepositoryRules;
use App\Module\Forge\Settings\RepositorySettings;
use App\Module\GitHub\Service\GitHubAppConfiguration;
use App\Module\GitHub\Service\HookSecretKey;
use App\Module\Workflow\Contract\RepositorySuggestion;
use App\Module\Workflow\Contract\RepositorySuggestionKey;
use App\Module\Workflow\Contract\RepositorySuggestions;
use App\Module\Workflow\Contract\SuggestionMark;
use App\Module\Workflow\Contract\WorkflowKeys;
use App\Tests\Module\GitHub\Controller\GitHubConnectionsScenario;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;
use Ubermuda\DoctrineExtra\Encryption\EncryptionKeyProvider;

final class GitHubRepositoriesComponentTest extends WebTestCase
{
    use GitHubConnectionsScenario;

    public function test_the_owner_sees_the_empty_state(): void
    {
        $client = static::createClient();
        $owner = $this->signedUpUser('empty');
        $project = $this->projectOf($owner);
        $this->em()->clear();

        $client->loginUser($owner);
        $crawler = $this->repositoriesPage($client, $project);

        self::assertResponseIsSuccessful();
        $section = $crawler->filter('#repositories');
        self::assertCount(1, $section);
        self::assertStringContainsString('reach the cards that link its pull requests', $section->text());
        self::assertCount(0, $section->filter('[data-forge-repository]'));
        self::assertCount(0, $crawler->filter('#github-hook'));
    }

    public function test_a_stranger_cannot_open_the_page_of_another_project(): void
    {
        $client = static::createClient();
        $project = $this->projectOf($this->signedUpUser('owner'));
        $stranger = $this->signedUpUser('stranger');
        $this->em()->clear();

        $client->loginUser($stranger);
        $this->repositoriesPage($client, $project);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function test_the_page_lists_the_nav_item_and_the_breadcrumb(): void
    {
        $client = static::createClient();
        $owner = $this->signedUpUser('nav');
        $project = $this->projectOf($owner);
        $this->em()->clear();

        $client->loginUser($owner);
        $crawler = $this->repositoriesPage($client, $project);

        self::assertSame('Repositories', trim($crawler->filter('.lp-settings-nav__item[aria-current="page"]')->text()));
        self::assertSame('Project settings', trim($crawler->filter('.lp-crumbs__link')->text()));
        self::assertSame('Repositories', trim($crawler->filter('.lp-crumbs__here')->text()));
    }

    public function test_each_repository_shows_its_path_and_its_health(): void
    {
        $client = static::createClient();
        $owner = $this->signedUpUser('health');
        $project = $this->projectOf($owner);
        $this->repositoryOf($project, 'acme/waiting');
        $this->repositoryOf($project, 'acme/working', new \DateTimeImmutable('-2 hours'));
        $this->repositoryOf($project, 'acme/quiet', new \DateTimeImmutable('-31 days'));
        $this->em()->clear();

        $client->loginUser($owner);
        $crawler = $this->repositoriesPage($client, $project);

        $rows = [];
        $crawler->filter('#repositories [data-forge-repository]')->each(static function ($row) use (&$rows): void {
            $rows[trim($row->filter('[data-testid="forge-repository-path"]')->text())] = trim($row->filter('[data-testid="forge-repository-health"]')->text());
        });

        self::assertSame(['acme/waiting', 'acme/working', 'acme/quiet'], array_keys($rows));
        self::assertSame('Waiting for the first delivery', $rows['acme/waiting']);
        self::assertStringStartsWith('Working', $rows['acme/working']);
        self::assertStringStartsWith('Quiet', $rows['acme/quiet']);
    }

    public function test_the_connections_page_has_no_repository_rows(): void
    {
        $client = static::createClient();
        $owner = $this->signedUpUser('connections');
        $project = $this->projectOf($owner);
        $this->repositoryOf($project, 'acme/app');
        $this->em()->clear();

        $client->loginUser($owner);
        $crawler = $this->connectPage($client, $project);

        self::assertCount(0, $crawler->filter('[data-forge-repository]'));
        self::assertCount(1, $crawler->filter('#github-connection'));
    }

    public function test_a_stored_check_draws_a_chip_a_title_and_a_detail_for_each_line(): void
    {
        $client = static::createClient();
        $this->suggest(RepositorySuggestionKey::ApprovalGate, SuggestionMark::Blocking, RepositorySuggestionKey::CiChecks, SuggestionMark::Warning, RepositorySuggestionKey::MergeMethod, SuggestionMark::Blocking);
        $owner = $this->signedUpUser('lines');
        $project = $this->projectOf($owner);
        $repository = $this->repositoryOf($project, 'acme/app');
        $this->checkOf($repository, [
            $this->item('approval-gate', 'blocking', [RepositorySuggestionCatalog::GAP_APPROVAL_CHECK_NOT_REQUIRED]),
            $this->item('ci-checks', 'warning', [RepositorySuggestionCatalog::GAP_NO_CI_CHECK]),
            $this->item('merge-method', 'blocking', []),
            $this->item(ForgeRepositoryCheck::KEY_CLASSIC_PROTECTION, 'warning', [RepositorySuggestionCatalog::GAP_CLASSIC_PROTECTION]),
        ], new \DateTimeImmutable('-2 minutes'));
        $this->em()->clear();

        $client->loginUser($owner);
        $crawler = $this->repositoriesPage($client, $project);

        $lines = $crawler->filter('[data-forge-repository] [data-testid="forge-repository-check-line"]');
        self::assertCount(4, $lines);
        self::assertCount(1, $lines->eq(0)->filter('.lp-status-chip--failed'));
        self::assertSame('Blocking', trim($lines->eq(0)->filter('.lp-status-chip')->text()));
        self::assertStringContainsString('Loupe / Approval is not required', $lines->eq(0)->text());
        self::assertStringContainsString('nothing on GitHub gates a merge', $lines->eq(0)->text());
        self::assertCount(1, $lines->eq(1)->filter('.lp-status-chip--pending'));
        self::assertCount(1, $lines->eq(2)->filter('.lp-status-chip--ok'));
        self::assertStringContainsString('Loupe merges with squash', $lines->eq(2)->text());
        self::assertStringContainsString('main uses classic branch protection', $lines->eq(3)->text());
        self::assertStringContainsString('Checked 2m ago', trim((string) preg_replace('/\s+/', ' ', $crawler->filter('[data-testid="forge-repository-checked"]')->text())));
        self::assertSame('Lifecycle workflow', trim($crawler->filter('[data-testid="forge-repository-workflow"]')->text()));
        self::assertCount(0, $crawler->filter('[data-testid="forge-repository-check-failed"]'));
    }

    public function test_a_page_with_no_stored_read_queues_one_check_and_shows_checking(): void
    {
        $client = static::createClient();
        $this->suggest(RepositorySuggestionKey::MergeMethod, SuggestionMark::Blocking);
        $owner = $this->signedUpUser('queue');
        $project = $this->projectOf($owner);
        $repository = $this->repositoryOf($project, 'acme/app');
        $this->em()->clear();

        $client->loginUser($owner);
        $crawler = $this->repositoriesPage($client, $project);

        $queued = $this->queuedChecks();
        self::assertCount(1, $queued);
        self::assertSame((string) $repository->id, $queued[0]->repositoryId);
        self::assertSame(CheckTrigger::PageOpen, $queued[0]->trigger);
        self::assertCount(1, $crawler->filter('[data-forge-repository] [data-repository-checking]'));
        self::assertSame('Checking…', trim($crawler->filter('[data-testid="forge-repository-checking"]')->text()));
    }

    public function test_a_page_reopened_within_five_minutes_dispatches_nothing_and_shows_no_checking(): void
    {
        $client = static::createClient();
        $this->suggest(RepositorySuggestionKey::MergeMethod, SuggestionMark::Blocking);
        $owner = $this->signedUpUser('reopen');
        $project = $this->projectOf($owner);
        $repository = $this->repositoryOf($project, 'acme/app');
        $this->checkOf($repository, [$this->item('merge-method', 'blocking', [])], new \DateTimeImmutable('-4 minutes'));
        $this->em()->clear();

        $client->loginUser($owner);
        $crawler = $this->repositoriesPage($client, $project);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-testid="forge-repository-check-line"]'));
        self::assertSame([], $this->queuedChecks());
        self::assertCount(0, $crawler->filter('[data-repository-checking]'));
        self::assertStringContainsString('Checked 4m ago', trim((string) preg_replace('/\s+/', ' ', $crawler->filter('[data-testid="forge-repository-checked"]')->text())));
    }

    public function test_a_stale_read_queues_a_new_check_and_keeps_the_last_lines(): void
    {
        $client = static::createClient();
        $this->suggest(RepositorySuggestionKey::MergeMethod, SuggestionMark::Blocking);
        $owner = $this->signedUpUser('stale');
        $project = $this->projectOf($owner);
        $repository = $this->repositoryOf($project, 'acme/app');
        $this->checkOf($repository, [$this->item('merge-method', 'blocking', [])], new \DateTimeImmutable('-6 minutes'));
        $this->em()->clear();

        $client->loginUser($owner);
        $crawler = $this->repositoriesPage($client, $project);

        self::assertCount(1, $this->queuedChecks());
        self::assertCount(1, $crawler->filter('[data-repository-checking]'));
        self::assertCount(1, $crawler->filter('[data-testid="forge-repository-check-line"]'));
    }

    public function test_a_project_whose_workflow_suggests_nothing_gets_no_check(): void
    {
        $client = static::createClient();
        $this->suggest();
        $owner = $this->signedUpUser('nothing');
        $project = $this->projectOf($owner);
        $this->repositoryOf($project, 'acme/app');
        $this->em()->clear();

        $client->loginUser($owner);
        $crawler = $this->repositoriesPage($client, $project);

        self::assertCount(1, $crawler->filter('[data-forge-repository]'));
        self::assertSame([], $this->queuedChecks());
        self::assertCount(0, $crawler->filter('[data-testid="forge-repository-check"]'));
    }

    public function test_a_failed_read_says_so_and_keeps_the_last_certain_lines(): void
    {
        $client = static::createClient();
        $this->suggest(RepositorySuggestionKey::MergeMethod, SuggestionMark::Blocking);
        $owner = $this->signedUpUser('failed');
        $project = $this->projectOf($owner);
        $repository = $this->repositoryOf($project, 'acme/app');
        $check = $this->checkOf($repository, [$this->item('merge-method', 'blocking', [])], new \DateTimeImmutable('-1 hour'));
        $check->attemptedAt = new \DateTimeImmutable('-1 minute');
        $check->failure = 'api_failed_timeout';
        $this->em()->flush();
        $this->em()->clear();

        $client->loginUser($owner);
        $crawler = $this->repositoriesPage($client, $project);

        self::assertSame([], $this->queuedChecks());
        self::assertStringContainsString('Loupe could not check acme/app', $crawler->filter('[data-testid="forge-repository-check-failed"]')->text());
        self::assertStringContainsString('New work is not paused', $crawler->filter('[data-testid="forge-repository-check-failed"]')->text());
        self::assertCount(1, $crawler->filter('[data-testid="forge-repository-check-line"]'));
    }

    public function test_the_repository_removal_dialog_keeps_its_form(): void
    {
        $client = static::createClient();
        $owner = $this->signedUpUser('remove');
        $project = $this->projectOf($owner);
        $repository = $this->repositoryOf($project, 'acme/app');
        $this->em()->clear();

        $client->loginUser($owner);
        $crawler = $this->repositoriesPage($client, $project);

        self::assertSame(
            '/projects/'.$project->id.'/repositories/'.$repository->id.'/remove',
            $crawler->filter('[data-forge-repository] dialog form')->attr('action'),
        );
    }

    public function test_the_page_subscribes_to_the_repository_check_topic_and_names_each_row_url(): void
    {
        $client = static::createClient();
        $owner = $this->signedUpUser('topic');
        $project = $this->projectOf($owner);
        $repository = $this->repositoryOf($project, 'acme/app');
        $this->em()->clear();

        $client->loginUser($owner);
        $crawler = $this->repositoriesPage($client, $project);

        self::assertCount(1, $crawler->filter('#repositories[data-controller="repository-checks-live"]'));
        self::assertSame(
            '/projects/'.$project->id.'/repositories/'.$repository->id.'/row',
            $crawler->filter('[data-forge-repository]')->attr('data-repository-row-url'),
        );
        self::assertStringContainsString('/projects/'.$project->id.'/repository-checks', (string) $client->getResponse()->getContent());
    }

    public function test_without_forge_connections_the_page_says_so(): void
    {
        $client = static::createClient();
        $owner = $this->signedUpUser('nokey');
        $project = $this->projectOf($owner);
        $this->em()->clear();
        static::getContainer()->set(HookSecretKey::class, new HookSecretKey(new EncryptionKeyProvider('')));
        static::getContainer()->set(GitHubAppConfiguration::class, new GitHubAppConfiguration('', '', '', '', null, null));

        $client->loginUser($owner);
        $crawler = $this->repositoriesPage($client, $project);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('The operator has not enabled forge connections', $crawler->filter('#repositories')->text());
    }

    private function suggest(RepositorySuggestionKey|SuggestionMark ...$pairs): void
    {
        $suggestions = [];
        for ($index = 0; $index < \count($pairs); $index += 2) {
            $key = $pairs[$index];
            $mark = $pairs[$index + 1];
            self::assertInstanceOf(RepositorySuggestionKey::class, $key);
            self::assertInstanceOf(SuggestionMark::class, $mark);
            $suggestions[] = new RepositorySuggestion($key, $mark);
        }
        static::getContainer()->set(RepositorySuggestions::class, new readonly class($suggestions) implements RepositorySuggestions {
            /** @param list<RepositorySuggestion> $suggestions */
            public function __construct(
                private array $suggestions,
            ) {
            }

            #[\Override]
            public function forProject(Uuid $projectId): array
            {
                return $this->suggestions;
            }
        });
        static::getContainer()->set(WorkflowKeys::class, new readonly class implements WorkflowKeys {
            #[\Override]
            public function forProject(Uuid $projectId): string
            {
                return 'lifecycle';
            }
        });
    }

    /** @return list<CheckForgeRepository> */
    private function queuedChecks(): array
    {
        $transport = static::getContainer()->get('messenger.transport.jobs');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return array_values(array_filter(
            array_map(static fn ($envelope): object => $envelope->getMessage(), $transport->getSent()),
            static fn (object $message): bool => $message instanceof CheckForgeRepository,
        ));
    }

    /**
     * @param list<array{key: string, mark: string, state: string, gaps: list<string>, parameters: array<string, mixed>}> $items
     */
    private function checkOf(ForgeRepository $repository, array $items, \DateTimeImmutable $checkedAt): ForgeRepositoryCheck
    {
        $check = new ForgeRepositoryCheck($repository, $repository->project);
        $check->items = $items;
        $check->checkedAt = $checkedAt;
        $settings = new RepositorySettings('main', ['squash'], 15368, new RepositoryRules(true, false, null, [], [], true, true), [], [], false);
        $check->settings = json_decode(json_encode($settings, \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR);
        $this->em()->persist($check);
        $this->em()->flush();

        return $check;
    }

    /**
     * @param list<string> $gaps
     *
     * @return array{key: string, mark: string, state: string, gaps: list<string>, parameters: array<string, mixed>}
     */
    private function item(string $key, string $mark, array $gaps): array
    {
        return ['key' => $key, 'mark' => $mark, 'state' => [] === $gaps ? 'fits' : 'gap', 'gaps' => $gaps, 'parameters' => []];
    }
}
