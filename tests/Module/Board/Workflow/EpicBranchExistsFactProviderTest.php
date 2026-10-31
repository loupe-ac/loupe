<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Workflow;

use App\Module\Board\Entity\Card;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Workflow\EpicBranchExistsFactProvider;
use App\Module\Board\Workflow\EpicBranchExistsFacts;
use App\Module\Forge\Service\BranchReader;
use App\Module\Forge\Service\BranchReaders;
use App\Module\Project\Entity\Project;
use App\Module\Project\Workshop\WorkshopConnection;
use App\Module\Project\Workshop\WorkshopConnectionsProviderInterface;
use App\Module\Workflow\Contract\EpicBranches;
use App\Tests\Module\Board\Mcp\BoardToolScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
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

    /** @var array<string, list<string>> remote URL => the branches that repository holds */
    private array $branches = [];

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
        self::assertSame([self::REMOTE.' epic/1'], $this->asked->getArrayCopy());
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

    private function connection(string $id, string $remote = self::REMOTE, bool $quiet = false): WorkshopConnection
    {
        return new WorkshopConnection($id, '1.0.0', $quiet, '/agents', $id, repositoryPath: '/work/loupe', repositoryRemoteUrl: $remote);
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
        $reader = new readonly class($this->branches, $this->asked) implements BranchReader {
            /**
             * @param array<string, list<string>> $branches
             * @param \ArrayObject<int, string>   $asked
             */
            public function __construct(
                private array $branches,
                private \ArrayObject $asked,
            ) {
            }

            #[\Override]
            public function exists(Project $project, string $remoteUrl, string $branch): bool
            {
                $this->asked->append($remoteUrl.' '.$branch);

                return \in_array($branch, $this->branches[$remoteUrl] ?? [], true);
            }
        };

        return new EpicBranchExistsFactProvider($cards, $epicBranches, $workshop, new BranchReaders([$reader]));
    }
}
