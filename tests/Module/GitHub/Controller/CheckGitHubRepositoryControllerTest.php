<?php

declare(strict_types=1);

namespace App\Tests\Module\GitHub\Controller;

use App\Module\Forge\Entity\ForgeRepository;
use App\Module\Forge\Entity\ForgeRepositoryCheck;
use App\Module\Forge\Entity\ForgeRepositorySource;
use App\Module\Forge\Repository\ForgeRepositoryCheckRepository;
use App\Module\Forge\Service\RepositorySettingsReaders;
use App\Module\Forge\Service\RepositorySettingsUnreadable;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\RepositorySuggestion;
use App\Module\Workflow\Contract\RepositorySuggestionKey;
use App\Module\Workflow\Contract\RepositorySuggestions;
use App\Module\Workflow\Contract\SuggestionMark;
use App\Tests\Module\Forge\FakeRepositorySettingsReader;
use App\Tests\Support\RecordingAuditor;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\MockHub;
use Symfony\Component\Mercure\Update;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Uid\Uuid;
use Ubermuda\AuditBundle\AuditOutcome;

final class CheckGitHubRepositoryControllerTest extends WebTestCase
{
    use GitHubConnectionsScenario;

    private FakeRepositorySettingsReader $reader;

    private KernelBrowser $client;

    #[\Override]
    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $container = static::getContainer();
        // Before anything builds the hub: the container hands the replacement only to what it builds afterwards.
        $container->set('mercure.hub.default', new MockHub('http://mercure/.well-known/mercure', new StaticTokenProvider('token'), static fn (Update $update): string => 'id'));
        $this->reader = new FakeRepositorySettingsReader();
        $container->set(RepositorySettingsReaders::class, new RepositorySettingsReaders([$this->reader]));
        $container->set(RepositorySuggestions::class, new readonly class implements RepositorySuggestions {
            #[\Override]
            public function forProject(Uuid $projectId): array
            {
                return [new RepositorySuggestion(RepositorySuggestionKey::BranchProtection, SuggestionMark::Blocking)];
            }
        });
    }

    public function test_check_again_reads_the_forge_in_the_request_and_redirects_to_the_fresh_result(): void
    {
        $audit = RecordingAuditor::installedIn(static::getContainer());
        $owner = $this->signedUpUser('again');
        $project = $this->projectOf($owner);
        $repository = $this->forgeRepositoryOf($project, 'acme/app');
        $repositoryId = $repository->id;
        $this->reader->answers = [FakeRepositorySettingsReader::blocking(), FakeRepositorySettingsReader::clear()];
        $this->em()->clear();
        $this->client->loginUser($owner);

        $this->post($project, $repositoryId);
        self::assertResponseRedirects('/projects/'.$project->id.'/settings/repositories');
        self::assertSame('gap', $this->storedCheck($repositoryId)->items[0]['state']);

        // A read younger than five minutes does not stop Check again from reading.
        $this->post($project, $repositoryId);
        self::assertSame(2, $this->reader->reads);
        self::assertSame('fits', $this->storedCheck($repositoryId)->items[0]['state']);
        self::assertSame([AuditOutcome::Success, AuditOutcome::Success], array_map(static fn ($event): AuditOutcome => $event->outcome, $audit->records('github.repository_check_requested')));
    }

    public function test_an_unreadable_read_still_redirects_and_the_row_shows_the_failed_read_banner(): void
    {
        $owner = $this->signedUpUser('unreadable');
        $project = $this->projectOf($owner);
        $repositoryId = $this->forgeRepositoryOf($project, 'acme/app')->id;
        $this->reader->answers = [new RepositorySettingsUnreadable('api_failed_500')];
        $this->em()->clear();
        $this->client->loginUser($owner);

        $this->post($project, $repositoryId);

        self::assertResponseRedirects('/projects/'.$project->id.'/settings/repositories');
        self::assertSame('api_failed_500', $this->storedCheck($repositoryId)->failure);
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Loupe could not check acme/app.', $crawler->filter('[data-testid="forge-repository-check-failed"]')->text());
        self::assertCount(1, $crawler->filter('[data-testid="forge-repository-check-failed"] [data-testid="forge-repository-check-again"]'));
    }

    public function test_the_post_needs_a_csrf_token(): void
    {
        $owner = $this->signedUpUser('csrf');
        $project = $this->projectOf($owner);
        $repositoryId = $this->forgeRepositoryOf($project, 'acme/app')->id;
        $this->reader->answers = [FakeRepositorySettingsReader::clear()];
        $this->em()->clear();
        $this->client->loginUser($owner);

        $this->client->request(Request::METHOD_POST, '/projects/'.$project->id.'/repositories/'.$repositoryId.'/check');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame(0, $this->reader->reads);
    }

    public function test_a_stranger_is_forbidden_and_reads_nothing(): void
    {
        $project = $this->projectOf($this->signedUpUser('owner'));
        $repositoryId = $this->forgeRepositoryOf($project, 'acme/app')->id;
        $stranger = $this->signedUpUser('stranger');
        $this->reader->answers = [FakeRepositorySettingsReader::clear()];
        $this->em()->clear();
        $this->client->loginUser($stranger);

        $this->post($project, $repositoryId);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame(0, $this->reader->reads);
    }

    public function test_a_repository_of_another_project_is_not_found(): void
    {
        $owner = $this->signedUpUser('mine');
        $project = $this->projectOf($owner);
        $theirs = $this->forgeRepositoryOf($this->projectOf($this->signedUpUser('theirs')), 'rival/app')->id;
        $this->reader->answers = [FakeRepositorySettingsReader::clear()];
        $this->em()->clear();
        $this->client->loginUser($owner);

        $this->post($project, $theirs);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertSame(0, $this->reader->reads);
    }

    public function test_the_seventh_click_inside_a_minute_reads_nothing_and_flashes_the_time_of_the_last_read(): void
    {
        static::getContainer()->set('limiter.forge_repository_check', new RateLimiterFactory(
            ['id' => 'forge_repository_check', 'policy' => 'sliding_window', 'limit' => 6, 'interval' => '1 minute'],
            new InMemoryStorage(),
        ));
        $audit = RecordingAuditor::installedIn(static::getContainer());
        $owner = $this->signedUpUser('limit');
        $project = $this->projectOf($owner);
        $repositoryId = $this->forgeRepositoryOf($project, 'acme/app')->id;
        $other = $this->forgeRepositoryOf($project, 'acme/other')->id;
        $this->reader->answers = array_fill(0, 7, FakeRepositorySettingsReader::clear());
        $this->em()->clear();
        $this->client->loginUser($owner);

        for ($click = 1; $click <= 6; ++$click) {
            $this->post($project, $repositoryId);
        }
        self::assertSame(6, $this->reader->reads);
        $before = $this->storedCheck($repositoryId)->checkedAt;

        $this->post($project, $repositoryId);

        self::assertResponseRedirects('/projects/'.$project->id.'/settings/repositories');
        self::assertSame(6, $this->reader->reads, 'The refused click reads no GitHub.');
        self::assertEquals($before, $this->storedCheck($repositoryId)->checkedAt);
        self::assertStringContainsString('Loupe checked a moment ago, at '.$before?->format('H:i'), $this->client->followRedirect()->filter('.lp-flash')->text());
        self::assertSame(AuditOutcome::Refused, $audit->records('github.repository_check_requested')[6]->outcome);

        $this->post($project, $other);
        self::assertSame(7, $this->reader->reads, 'Another repository has its own allowance.');
    }

    private function post(Project $project, ?Uuid $repositoryId): void
    {
        $this->postAction($this->client, '/projects/'.$project->id.'/repositories/'.$repositoryId.'/check');
    }

    private function forgeRepositoryOf(Project $project, string $path): ForgeRepository
    {
        $repository = new ForgeRepository($project, FakeRepositorySettingsReader::FORGE, (string) random_int(1, 1_000_000_000), $path, ForgeRepositorySource::Hook);
        $this->em()->persist($repository);
        $this->em()->flush();

        return $repository;
    }

    private function storedCheck(?Uuid $repositoryId): ForgeRepositoryCheck
    {
        $this->em()->clear();
        $repository = $this->em()->find(ForgeRepository::class, $repositoryId);
        self::assertNotNull($repository);
        $check = static::getContainer()->get(ForgeRepositoryCheckRepository::class)->findOneOfRepository($repository);
        self::assertNotNull($check);

        return $check;
    }
}
