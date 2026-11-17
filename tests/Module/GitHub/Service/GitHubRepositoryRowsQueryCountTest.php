<?php

declare(strict_types=1);

namespace App\Tests\Module\GitHub\Service;

use App\Module\Forge\Entity\ForgeRepository;
use App\Module\Forge\Entity\ForgeRepositoryCheck;
use App\Module\Forge\Messenger\CheckForgeRepository;
use App\Module\GitHub\Service\GitHubRepositoryRows;
use App\Module\Workflow\Contract\RepositorySuggestion;
use App\Module\Workflow\Contract\RepositorySuggestionKey;
use App\Module\Workflow\Contract\RepositorySuggestions;
use App\Module\Workflow\Contract\SuggestionMark;
use App\Tests\Module\GitHub\Controller\GitHubConnectionsScenario;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;

/** A row render reads the stored check of its repository once, also when it queues a new check. */
final class GitHubRepositoryRowsQueryCountTest extends KernelTestCase
{
    use GitHubConnectionsScenario;

    public function test_a_page_render_of_a_stale_row_reads_the_stored_check_once(): void
    {
        self::bootKernel();
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
        $project = $this->projectOf($this->signedUpUser('rows-q'));
        $repository = $this->repositoryOf($project, 'acme/app');
        $check = new ForgeRepositoryCheck($repository, $repository->project);
        $check->checkedAt = new \DateTimeImmutable('-2 hours');
        $this->em()->persist($check);
        $this->em()->flush();
        $this->em()->clear();
        $repository = $this->em()->find(ForgeRepository::class, $repository->id) ?? throw new \LogicException('The repository is stored.');
        $queries = self::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(DebugDataHolder::class, $queries);
        $rows = self::getContainer()->get(GitHubRepositoryRows::class);
        self::assertInstanceOf(GitHubRepositoryRows::class, $rows);

        $queries->reset();
        $row = $rows->forRepository($repository, requestCheck: true);

        $reads = [];
        foreach ($queries->getData() as $connectionQueries) {
            foreach ($connectionQueries as $query) {
                if (str_contains((string) $query['sql'], 'FROM forge_repository_checks')) {
                    $reads[] = (string) $query['sql'];
                }
            }
        }
        // Guards: the row holds the stored check and queued a new one, so the stale path ran.
        self::assertNotNull($row->check);
        self::assertTrue($row->checking);
        $transport = static::getContainer()->get('messenger.transport.jobs');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        self::assertCount(1, array_filter(array_map(static fn ($envelope): object => $envelope->getMessage(), $transport->getSent()), static fn (object $message): bool => $message instanceof CheckForgeRepository));
        self::assertCount(1, $reads, implode("\n", $reads));
    }
}
