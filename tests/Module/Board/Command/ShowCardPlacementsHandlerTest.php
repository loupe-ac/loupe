<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Command;

use App\Module\Account\Entity\User;
use App\Module\Board\Command\ShowCardPlacementsCommand;
use App\Module\Board\Command\ShowCardPlacementsHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Repository\CardDocumentRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Repository\CardSiteReviewCommentRepository;
use App\Module\Board\Service\BoardColumnCards;
use App\Module\Board\Service\BoardPlacementContextReader;
use App\Module\Board\Service\CardPlacer;
use App\Module\Board\Service\LaneDecks;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Board\BoardColumnFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Ubermuda\FeatureFlagsBundle\Reader\FeatureFlagReaderInterface;

/** Several cards are placed against one read of the board-wide values. */
final class ShowCardPlacementsHandlerTest extends KernelTestCase
{
    use BoardColumnFixtures;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        // The flag reader loads every flag once per request, so the first board read would count that query alone.
        $flags = self::getContainer()->get(FeatureFlagReaderInterface::class);
        self::assertInstanceOf(FeatureFlagReaderInterface::class, $flags);
        $flags->all();
    }

    public function test_the_placements_keep_the_order_of_the_request_and_a_missing_card_is_removed(): void
    {
        [$project, $cards] = $this->board('placements-order', 3);
        $gone = (string) Uuid::v7();
        $ids = [(string) $cards[2]->id, $gone, (string) $cards[0]->id, (string) $cards[1]->id];

        $view = $this->handler()(new ShowCardPlacementsCommand($project, $ids));

        self::assertSame($ids, array_keys($view->placements));
        self::assertSame($cards[2], $view->placements[$ids[0]]->card);
        self::assertNull($view->placements[$gone]->card);
        self::assertSame($cards[0], $view->placements[$ids[2]]->card);
        self::assertSame((string) $cards[0]->id, $view->placements[$ids[3]]->after);
    }

    public function test_a_card_of_another_project_is_removed(): void
    {
        [$project] = $this->board('placements-mine', 1);
        [, $foreign] = $this->board('placements-foreign', 1);
        $id = (string) $foreign[0]->id;

        $view = $this->handler()(new ShowCardPlacementsCommand($project, [$id]));

        self::assertNull($view->placements[$id]->card);
    }

    public function test_the_board_wide_reads_run_once_for_many_cards(): void
    {
        [$project, $cards] = $this->board('placements-reads', 5);
        $ids = array_map(static fn (Card $card): string => (string) $card->id, $cards);
        $queries = self::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(DebugDataHolder::class, $queries);

        $queries->reset();
        $this->handler()(new ShowCardPlacementsCommand($project, \array_slice($ids, 0, 1)));
        $forOne = $this->boardWideReads($queries);
        $queries->reset();
        $this->handler()(new ShowCardPlacementsCommand($project, $ids));
        $forFive = $this->boardWideReads($queries);

        // Guard: a count of zero would also pass when the reads did not run.
        self::assertGreaterThan(0, $forOne);
        self::assertSame($forOne, $forFive);
    }

    public function test_a_card_that_fails_to_place_is_left_out_and_logged_while_the_others_are_placed(): void
    {
        [$project, $cards] = $this->board('placements-failing', 3);
        $ids = array_map(static fn (Card $card): string => (string) $card->id, $cards);
        $broken = $ids[1];

        $registry = self::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);
        $failing = new class($registry, $broken) extends CardRepository {
            public function __construct(
                ManagerRegistry $registry,
                private readonly string $broken,
            ) {
                parent::__construct($registry);
            }

            #[\Override]
            public function shownColumnIdOf(Card $card, \DateTimeImmutable $since): ?string
            {
                if ((string) $card->id === $this->broken) {
                    throw new \RuntimeException('The placement of this card fails.');
                }

                return parent::shownColumnIdOf($card, $since);
            }
        };
        $placer = new CardPlacer(
            new BoardColumnCards($failing),
            $failing,
            $this->service(CardSiteReviewCommentRepository::class),
            $this->service(CardDocumentRepository::class),
            $this->service(LaneDecks::class),
        );
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with('board.placement_failed', self::callback(static fn (array $context): bool => $broken === $context['cardId']));
        $handler = new ShowCardPlacementsHandler($this->service(BoardPlacementContextReader::class), $placer, $this->service(CardRepository::class), $logger);

        $view = $handler(new ShowCardPlacementsCommand($project, $ids));

        self::assertSame([$ids[0], $ids[2]], array_keys($view->placements));
        self::assertSame($cards[0], $view->placements[$ids[0]]->card);
        self::assertSame($cards[2], $view->placements[$ids[2]]->card);
    }

    private function handler(): ShowCardPlacementsHandler
    {
        return $this->service(ShowCardPlacementsHandler::class);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function service(string $class): object
    {
        $service = self::getContainer()->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }

    /** The number of reads of the column table, which only the board-wide values touch. */
    private function boardWideReads(DebugDataHolder $queries): int
    {
        $count = 0;
        foreach ($queries->getData() as $connectionQueries) {
            foreach ($connectionQueries as $query) {
                if (1 === preg_match('/FROM board_columns\b/', (string) $query['sql'])) {
                    ++$count;
                }
            }
        }

        return $count;
    }

    /**
     * @param non-empty-string $slug
     *
     * @return array{Project, list<Card>} the project and $count cards in its in-progress column, in order
     */
    private function board(string $slug, int $count): array
    {
        $owner = new User(fullName: 'Riley', email: $slug.'@example.com', password: 'hashed');
        $this->em->persist($owner);
        $project = new Project($owner, $slug.'-'.uniqid());
        $this->em->persist($project);
        $this->seedColumns($project);
        $this->em->flush();

        $cards = [];
        for ($index = 0; $index < $count; ++$index) {
            $card = new Card(project: $project, column: $this->column($project, 'in-progress'), title: 'Card '.$index, body: '', number: $index + 1, type: 'feature', position: $index);
            $this->em->persist($card);
            $cards[] = $card;
        }
        $this->em->flush();

        return [$project, $cards];
    }
}
