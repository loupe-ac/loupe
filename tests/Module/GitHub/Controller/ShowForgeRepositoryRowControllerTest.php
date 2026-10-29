<?php

declare(strict_types=1);

namespace App\Tests\Module\GitHub\Controller;

use App\Module\Forge\Entity\ForgeRepository;
use App\Module\Forge\Entity\ForgeRepositoryCheck;
use App\Module\Forge\Messenger\CheckForgeRepository;
use App\Module\Forge\Service\RepositorySuggestionCatalog;
use App\Module\Workflow\Contract\RepositorySuggestion;
use App\Module\Workflow\Contract\RepositorySuggestionKey;
use App\Module\Workflow\Contract\RepositorySuggestions;
use App\Module\Workflow\Contract\SuggestionMark;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;

final class ShowForgeRepositoryRowControllerTest extends WebTestCase
{
    use GitHubConnectionsScenario;

    private const string STREAM = 'text/vnd.turbo-stream.html';

    public function test_the_owner_gets_a_stream_that_replaces_the_row_with_the_stored_result(): void
    {
        $client = static::createClient();
        $this->suggestMergeMethod();
        $owner = $this->signedUpUser('row');
        $project = $this->projectOf($owner);
        $repository = $this->repositoryOf($project, 'acme/app');
        $this->checkOf($repository, [$this->gap('approval-gate', 'blocking', RepositorySuggestionCatalog::GAP_NO_PULL_REQUEST_RULE)], new \DateTimeImmutable('-1 minute'));
        $this->em()->clear();

        $client->loginUser($owner);
        $crawler = $this->fetchRow($client, $project->id, $repository->id);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', self::STREAM.'; charset=UTF-8');
        $stream = $crawler->filter('turbo-stream');
        self::assertCount(1, $stream);
        self::assertSame('replace', $stream->attr('action'));
        self::assertSame('forge-repository-'.$repository->id, $stream->attr('target'));
        $content = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('id="forge-repository-'.$repository->id.'"', $content);
        self::assertStringContainsString('acme/app', $content);
        self::assertStringContainsString('No rule requires a pull request into the default branch', $content);
    }

    /** The page queued the read, so only the page may say "Checking…". The row answers the stored result. */
    public function test_the_row_of_a_stale_read_never_says_checking_and_queues_nothing(): void
    {
        $client = static::createClient();
        $this->suggestMergeMethod();
        $owner = $this->signedUpUser('stale-row');
        $project = $this->projectOf($owner);
        $repository = $this->repositoryOf($project, 'acme/app');
        $this->checkOf($repository, [$this->gap('merge-method', 'blocking', RepositorySuggestionCatalog::GAP_NO_MERGE_METHOD)], new \DateTimeImmutable('-2 hours'));
        $this->em()->clear();

        $client->loginUser($owner);
        $this->fetchRow($client, $project->id, $repository->id);

        $content = (string) $client->getResponse()->getContent();
        // Guard: the row drew its check list, so the absence below is about "Checking…".
        self::assertStringContainsString('No merge method is usable', $content);
        self::assertStringNotContainsString('data-repository-checking', $content);
        self::assertStringNotContainsString('Checking…', $content);
        self::assertSame([], $this->queuedChecks());
    }

    public function test_a_request_that_does_not_ask_for_a_stream_goes_to_the_page(): void
    {
        $client = static::createClient();
        $owner = $this->signedUpUser('plain');
        $project = $this->projectOf($owner);
        $repository = $this->repositoryOf($project, 'acme/app');
        $this->em()->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/repositories/'.$repository->id.'/row');

        self::assertResponseRedirects('/projects/'.$project->id.'/settings/repositories');
    }

    public function test_a_removed_repository_is_not_found(): void
    {
        $client = static::createClient();
        $owner = $this->signedUpUser('gone');
        $project = $this->projectOf($owner);
        $repository = $this->repositoryOf($project, 'acme/app');
        $id = $repository->id;
        $this->em()->remove($repository);
        $this->em()->flush();
        $this->em()->clear();

        $client->loginUser($owner);
        $this->fetchRow($client, $project->id, $id);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function test_a_repository_of_another_project_is_not_found(): void
    {
        $client = static::createClient();
        $owner = $this->signedUpUser('mine');
        $project = $this->projectOf($owner);
        $theirs = $this->repositoryOf($this->projectOf($this->signedUpUser('theirs')), 'rival/app');
        $this->em()->clear();

        $client->loginUser($owner);
        $this->fetchRow($client, $project->id, $theirs->id);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function test_a_stranger_is_forbidden_and_gets_no_row(): void
    {
        $client = static::createClient();
        $project = $this->projectOf($this->signedUpUser('owner'));
        $repository = $this->repositoryOf($project, 'acme/secret');
        $stranger = $this->signedUpUser('stranger');
        $this->em()->clear();

        $client->loginUser($stranger);
        $this->fetchRow($client, $project->id, $repository->id);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertStringNotContainsString('acme/secret', (string) $client->getResponse()->getContent());
    }

    private function fetchRow(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client, ?Uuid $projectId, ?Uuid $repositoryId): \Symfony\Component\DomCrawler\Crawler
    {
        return $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/repositories/'.$repositoryId.'/row', server: ['HTTP_ACCEPT' => self::STREAM]);
    }

    private function suggestMergeMethod(): void
    {
        static::getContainer()->set(RepositorySuggestions::class, new readonly class implements RepositorySuggestions {
            #[\Override]
            public function forProject(Uuid $projectId): array
            {
                return [new RepositorySuggestion(RepositorySuggestionKey::MergeMethod, SuggestionMark::Blocking)];
            }

            #[\Override]
            public function forProjectFresh(Uuid $projectId): array
            {
                return $this->forProject($projectId);
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
        $this->em()->persist($check);
        $this->em()->flush();

        return $check;
    }

    /** @return array{key: string, mark: string, state: string, gaps: list<string>, parameters: array<string, mixed>} */
    private function gap(string $key, string $mark, string $gap): array
    {
        return ['key' => $key, 'mark' => $mark, 'state' => 'gap', 'gaps' => [$gap], 'parameters' => []];
    }
}
