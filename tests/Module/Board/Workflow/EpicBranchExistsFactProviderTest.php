<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Workflow;

use App\Module\Board\Entity\Card;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Workflow\EpicBranchExistsFactProvider;
use App\Module\Board\Workflow\EpicBranchExistsFacts;
use App\Module\Forge\Entity\ForgeRepository;
use App\Module\Forge\Entity\ForgeRepositorySource;
use App\Module\Forge\Messenger\CheckForgeBranch;
use App\Module\Forge\Service\BranchPresence;
use App\Module\Forge\Service\BranchReader;
use App\Module\Forge\Service\BranchReaders;
use App\Module\Forge\Service\ForgeBranchPresence;
use App\Module\Project\Entity\Project;
use App\Module\Project\Workshop\WorkshopConnection;
use App\Module\Project\Workshop\WorkshopConnectionsProviderInterface;
use App\Module\Workflow\Contract\EpicBranches;
use App\Tests\Module\Board\Mcp\BoardToolScenario;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;

final class EpicBranchExistsFactProviderTest extends KernelTestCase
{
    use BoardToolScenario;

    private const string REMOTE = 'https://github.com/loupe-ac/loupe.git';
    private const string OTHER_REMOTE = 'https://github.com/loupe-ac/other.git';

    private EntityManagerInterface $em;
    private Project $project;
    private int $number = 0;

    /** @var list<WorkshopConnection> */
    private array $connections = [];

    /** @var array<string, list<string>> readable remote URL => the branches whose stored answer is "exists" */
    private array $branches = [];

    /** @var array<string, list<string>> readable remote URL => the branches whose stored answer is "does not exist" */
    private array $absent = [];

    /** @var list<string> */
    private array $unreadable = [];

    /** @var array<string, ForgeRepository> */
    private array $rows = [];

    /** @var array<string, true> */
    private array $seeded = [];

    private MockClock $clock;
    private ?ForgeBranchPresence $presence = null;

    private ?string $pattern = 'epic/{number}';

    /** @var \ArrayObject<int, string> */
    private \ArrayObject $asked;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->asked = new \ArrayObject();
        $this->clock = new MockClock('2026-11-16 12:00:00');
        $this->project = $this->makeProject('epic-branch-exists');
    }

    public function test_a_card_with_no_parent_reads_true_and_asks_nothing(): void
    {
        $card = $this->card();
        $this->em->flush();

        self::assertTrue($this->exists($card));
        self::assertSame([], $this->asked->getArrayCopy());
    }

    public function test_a_template_with_no_epic_branch_reads_true_and_asks_nothing(): void
    {
        $this->pattern = null;
        $child = $this->child();
        $this->connections = [$this->connection('a')];

        self::assertTrue($this->exists($child));
        self::assertSame([], $this->asked->getArrayCopy());
    }

    public function test_a_child_in_the_backlog_or_a_finished_column_reads_true_and_asks_nothing(): void
    {
        $epic = $this->card();
        $backlog = $this->card('backlog', $epic);
        $done = $this->card('done', $epic);
        $this->em->flush();
        $this->connections = [$this->connection('a')];

        self::assertTrue($this->exists($backlog));
        self::assertTrue($this->exists($done));
        self::assertSame([], $this->asked->getArrayCopy());
    }

    public function test_no_bridge_reads_false(): void
    {
        self::assertFalse($this->exists($this->child()));
    }

    public function test_only_quiet_bridges_read_false(): void
    {
        $child = $this->child();
        $this->connections = [$this->connection('a', quiet: true)];
        $this->branches = [self::REMOTE => ['epic/1']];

        self::assertFalse($this->exists($child));
        self::assertSame([], $this->asked->getArrayCopy());
    }

    public function test_a_live_bridge_that_reports_no_folder_reads_false(): void
    {
        $child = $this->child();
        $this->connections = [new WorkshopConnection('a', '1.0.0', false, '/agents', 'a')];
        $this->branches = [self::REMOTE => ['epic/1']];

        self::assertFalse($this->exists($child));
    }

    public function test_a_live_bridge_whose_folder_has_no_remote_reads_false(): void
    {
        $child = $this->child();
        $this->connections = [$this->connection('a', remote: '')];

        self::assertFalse($this->exists($child));
        self::assertSame([], $this->asked->getArrayCopy());
    }

    public function test_a_remote_the_reader_does_not_know_reads_false(): void
    {
        $child = $this->child();
        $this->connections = [$this->connection('a', remote: 'https://example.com/loupe-ac/loupe.git')];
        $this->branches = [self::REMOTE => ['epic/1']];

        self::assertFalse($this->exists($child));
    }

    public function test_a_branch_only_in_another_repository_reads_false(): void
    {
        $child = $this->child();
        $this->connections = [$this->connection('a')];
        $this->branches = [self::OTHER_REMOTE => ['epic/1'], self::REMOTE => ['main']];

        self::assertFalse($this->exists($child));
    }

    public function test_a_branch_in_the_repository_of_the_bridge_reads_true(): void
    {
        $child = $this->child();
        $this->connections = [$this->connection('a')];
        $this->branches = [self::REMOTE => ['epic/1']];

        self::assertTrue($this->exists($child));
        self::assertSame([self::REMOTE], $this->asked->getArrayCopy());
    }

    public function test_two_bridges_read_true_only_when_both_repositories_hold_the_branch(): void
    {
        $child = $this->child();
        $this->connections = [$this->connection('a'), $this->connection('b', remote: self::OTHER_REMOTE)];
        $this->branches = [self::REMOTE => ['epic/1'], self::OTHER_REMOTE => ['main']];

        self::assertFalse($this->exists($child));

        $this->branches[self::OTHER_REMOTE] = ['epic/1'];

        self::assertTrue($this->exists($child));
    }

    public function test_a_quiet_bridge_with_another_repository_does_not_count(): void
    {
        $child = $this->child();
        $this->connections = [$this->connection('a'), $this->connection('b', remote: self::OTHER_REMOTE, quiet: true)];
        $this->branches = [self::REMOTE => ['epic/1']];

        self::assertTrue($this->exists($child));
    }

    public function test_two_bridges_on_one_repository_ask_the_forge_once(): void
    {
        $child = $this->child();
        $this->connections = [$this->connection('a'), $this->connection('b')];
        $this->branches = [self::REMOTE => ['epic/1']];

        self::assertTrue($this->exists($child));
        self::assertCount(1, $this->asked);
    }

    public function test_the_time_is_the_earliest_quiet_time_of_the_live_bridges_with_a_repository(): void
    {
        $child = $this->child();
        $this->connections = [
            $this->connection('a', quietAt: new \DateTimeImmutable('2026-11-16 12:10:01')),
            $this->connection('b', quietAt: new \DateTimeImmutable('2026-11-16 12:05:01')),
            $this->connection('c', quiet: true, quietAt: new \DateTimeImmutable('2026-11-16 12:01:01')),
            new WorkshopConnection('d', '1.0.0', false, '/agents', 'd', quietAt: new \DateTimeImmutable('2026-11-16 12:00:01')),
        ];
        $this->branches = [self::REMOTE => ['epic/1']];

        $facts = $this->provider()->build($child->snapshot());

        self::assertInstanceOf(EpicBranchExistsFacts::class, $facts);
        self::assertTrue($facts->exists);
        self::assertEquals(new \DateTimeImmutable('2026-11-16 12:05:01'), $facts->changesAt());
    }

    public function test_the_answer_flips_at_the_returned_time_and_not_one_second_before(): void
    {
        $child = $this->child();
        $this->branches = [self::REMOTE => ['epic/1']];
        $early = new \DateTimeImmutable('2026-11-16 12:05:01');
        $late = new \DateTimeImmutable('2026-11-16 12:10:01');
        $at = static fn (string $time): \DateTimeImmutable => new \DateTimeImmutable($time);
        $build = function (\DateTimeImmutable $now) use ($child, $early, $late): EpicBranchExistsFacts {
            $this->connections = [
                $this->connection('a', quiet: $early <= $now, quietAt: $early),
                $this->connection('b', quiet: $late <= $now, quietAt: $late),
            ];
            $facts = $this->provider()->build($child->snapshot());
            self::assertInstanceOf(EpicBranchExistsFacts::class, $facts);

            return $facts;
        };

        $before = $build($at('2026-11-16 12:05:00'));
        self::assertTrue($before->exists);
        self::assertEquals($early, $before->changesAt());

        $afterFirst = $build($early);
        self::assertTrue($afterFirst->exists, 'the other bridge still holds the branch');
        self::assertEquals($late, $afterFirst->changesAt());

        $beforeLast = $build($at('2026-11-16 12:10:00'));
        self::assertTrue($beforeLast->exists);

        $afterLast = $build($late);
        self::assertFalse($afterLast->exists, 'no live bridge is left');
        self::assertNull($afterLast->changesAt());
    }

    public function test_the_time_stays_when_the_branch_is_missing_and_is_null_with_no_live_bridge(): void
    {
        $child = $this->child();
        $this->connections = [$this->connection('a', quietAt: new \DateTimeImmutable('2026-11-16 12:10:01'))];

        $missing = $this->provider()->build($child->snapshot());
        self::assertInstanceOf(EpicBranchExistsFacts::class, $missing);
        self::assertFalse($missing->exists);
        self::assertEquals(new \DateTimeImmutable('2026-11-16 12:10:01'), $missing->changesAt());

        $this->connections = [$this->connection('a', quiet: true)];
        $none = $this->provider()->build($child->snapshot());
        self::assertInstanceOf(EpicBranchExistsFacts::class, $none);
        self::assertNull($none->changesAt());
    }

    public function test_the_time_is_left_out_of_the_fingerprint(): void
    {
        self::assertTrue($this->provider()->fingerprint(new EpicBranchExistsFacts(true, new \DateTimeImmutable('2026-11-16 12:00:00'))));
    }

    public function test_a_missing_answer_reads_false_and_queues_one_check(): void
    {
        $child = $this->child();
        $this->connections = [$this->connection('a'), $this->connection('b')];
        $this->branches = [self::REMOTE => []];

        self::assertFalse($this->exists($child));
        self::assertFalse($this->exists($child));

        $checks = $this->checks();
        self::assertCount(1, $checks);
        self::assertSame('epic/1', $checks[0]->branch);
        self::assertSame((string) $this->project->id, $checks[0]->projectId);
    }

    public function test_a_missing_answer_queues_another_check_after_the_request_window(): void
    {
        $child = $this->child();
        $this->connections = [$this->connection('a')];
        $this->branches = [self::REMOTE => []];

        $this->exists($child);
        $this->clock->sleep(ForgeBranchPresence::REQUEST_WINDOW_SECONDS - 1);
        $this->exists($child);
        self::assertCount(1, $this->checks());

        $this->clock->sleep(1);
        $this->exists($child);
        self::assertCount(2, $this->checks());
    }

    public function test_every_missing_answer_of_two_repositories_queues_its_check(): void
    {
        $child = $this->child();
        $this->connections = [$this->connection('a'), $this->connection('b', remote: self::OTHER_REMOTE)];
        $this->branches = [self::REMOTE => [], self::OTHER_REMOTE => []];

        self::assertFalse($this->exists($child));
        self::assertCount(2, $this->checks());
    }

    public function test_a_stored_answer_of_absent_reads_false_and_refreshes_after_ten_minutes(): void
    {
        $child = $this->child();
        $this->connections = [$this->connection('a')];
        $this->absent = [self::REMOTE => ['epic/1']];

        self::assertFalse($this->exists($child));
        self::assertSame([], $this->checks());

        $this->clock->sleep(ForgeBranchPresence::ABSENT_REFRESH_SECONDS);

        self::assertFalse($this->exists($child));
        self::assertCount(1, $this->checks());
    }

    public function test_a_stored_answer_of_exists_keeps_reading_true_and_refreshes_after_a_day(): void
    {
        $child = $this->child();
        $this->connections = [$this->connection('a')];
        $this->branches = [self::REMOTE => ['epic/1']];
        self::assertTrue($this->exists($child));

        $this->clock->sleep(ForgeBranchPresence::PRESENT_REFRESH_SECONDS - 1);
        self::assertTrue($this->exists($child));
        self::assertSame([], $this->checks());

        $this->clock->sleep(1);
        self::assertTrue($this->exists($child));
        self::assertCount(1, $this->checks());
    }

    public function test_a_repository_the_project_cannot_read_reads_false_whatever_the_store_holds(): void
    {
        $child = $this->child();
        $this->connections = [$this->connection('a')];
        $this->branches = [self::REMOTE => ['epic/1']];
        self::assertTrue($this->exists($child));

        $this->unreadable = [self::REMOTE];

        self::assertFalse($this->exists($child));
        self::assertSame([], $this->checks());
    }

    public function test_an_access_change_voids_the_stored_answer(): void
    {
        $child = $this->child();
        $this->connections = [$this->connection('a')];
        $this->branches = [self::REMOTE => ['epic/1']];
        self::assertTrue($this->exists($child));

        $this->clock->sleep(5);
        $row = $this->rows[self::REMOTE];
        $this->presence?->markAccessChanged($row);

        self::assertFalse($this->exists($child));
        self::assertCount(1, $this->checks());
    }

    public function test_the_fingerprint_is_the_exists_flag(): void
    {
        self::assertTrue($this->provider()->fingerprint(new EpicBranchExistsFacts(true)));
        self::assertFalse($this->provider()->fingerprint(new EpicBranchExistsFacts(false)));
    }

    private function child(): Card
    {
        $epic = $this->card();
        $child = $this->card('next', $epic);
        $this->em->flush();

        return $child;
    }

    private function card(string $column = 'in-progress', ?Card $parent = null): Card
    {
        $card = new Card($this->project, $this->column($this->project, $column), 'Card', '', ++$this->number);
        $card->parent = $parent;
        $this->em->persist($card);

        return $card;
    }

    private function connection(string $id, string $remote = self::REMOTE, bool $quiet = false, ?\DateTimeImmutable $quietAt = null): WorkshopConnection
    {
        return new WorkshopConnection($id, '1.0.0', $quiet, '/agents', $id, repositoryPath: '/work/loupe', repositoryRemoteUrl: $remote, quietAt: $quietAt);
    }

    private function exists(Card $card): bool
    {
        $facts = $this->provider()->build($card->snapshot());
        self::assertInstanceOf(EpicBranchExistsFacts::class, $facts);

        return $facts->exists;
    }

    private function provider(): EpicBranchExistsFactProvider
    {
        $cards = self::getContainer()->get(CardRepository::class);
        self::assertInstanceOf(CardRepository::class, $cards);

        $pattern = $this->pattern;
        $epicBranches = new readonly class($pattern) implements EpicBranches {
            public function __construct(
                private ?string $pattern,
            ) {
            }

            #[\Override]
            public function of(Uuid $projectId, int $number): ?string
            {
                return null === $this->pattern ? null : str_replace('{number}', (string) $number, $this->pattern);
            }
        };
        $workshop = new readonly class($this->connections) implements WorkshopConnectionsProviderInterface {
            /** @param list<WorkshopConnection> $connections */
            public function __construct(
                private array $connections,
            ) {
            }

            #[\Override]
            public function forProject(Project $project): array
            {
                return $this->connections;
            }

            #[\Override]
            public function forOwnerOf(Project $project): array
            {
                return [];
            }
        };
        $reader = new readonly class($this) implements BranchReader {
            public function __construct(
                private EpicBranchExistsFactProviderTest $test,
            ) {
            }

            #[\Override]
            public function readableRepository(Project $project, string $remoteUrl): ?ForgeRepository
            {
                return $this->test->readable($project, $remoteUrl);
            }

            #[\Override]
            public function readableRepositoryAt(Project $project, string $forge, string $path): ?ForgeRepository
            {
                return null;
            }

            #[\Override]
            public function presence(Project $project, ForgeRepository $repository, string $branch): BranchPresence
            {
                throw new \LogicException('The provider reads the store and never the forge.');
            }
        };

        $connection = self::getContainer()->get(Connection::class);
        $bus = self::getContainer()->get(MessageBusInterface::class);
        self::assertInstanceOf(Connection::class, $connection);
        self::assertInstanceOf(MessageBusInterface::class, $bus);
        $presence = $this->presence ??= new ForgeBranchPresence($connection, $bus, new ArrayAdapter(), $this->clock);
        $this->seed();

        return new EpicBranchExistsFactProvider($cards, $epicBranches, $workshop, new BranchReaders([$reader]), $presence);
    }

    /** Called by the reader stub. */
    public function readable(Project $project, string $remoteUrl): ?ForgeRepository
    {
        $this->asked->append($remoteUrl);
        if (\in_array($remoteUrl, $this->unreadable, true) || !(\array_key_exists($remoteUrl, $this->branches) || \array_key_exists($remoteUrl, $this->absent))) {
            return null;
        }

        return $this->rows[$remoteUrl] ??= $this->row($project, $remoteUrl);
    }

    private function row(Project $project, string $remoteUrl): ForgeRepository
    {
        $row = new ForgeRepository($project, 'github', (string) (90_000 + \count($this->rows)), basename($remoteUrl, '.git'), ForgeRepositorySource::Installation, '1');
        $this->em->persist($row);
        $this->em->flush();

        return $row;
    }

    private function seed(): void
    {
        $presence = $this->presence ?? throw new \LogicException('The store exists.');
        foreach ([[$this->branches, true], [$this->absent, false]] as [$map, $exists]) {
            foreach ($map as $remote => $branches) {
                foreach ($branches as $branch) {
                    $key = $remote.' '.$branch;
                    if (isset($this->seeded[$key])) {
                        continue;
                    }
                    $this->seeded[$key] = true;
                    $presence->record($this->rows[$remote] ??= $this->row($this->project, $remote), $branch, $exists, $this->clock->now());
                }
            }
        }
    }

    /** @return list<CheckForgeBranch> */
    private function checks(): array
    {
        $transport = self::getContainer()->get('messenger.transport.jobs');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        $checks = [];
        foreach ($transport->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof CheckForgeBranch) {
                $checks[] = $message;
            }
        }

        return $checks;
    }
}
