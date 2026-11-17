<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Command;

use App\Module\Account\Entity\User;
use App\Module\Board\Command\ShowCardPlacementCommand;
use App\Module\Board\Command\ShowCardPlacementHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardDocument;
use App\Module\Board\Entity\CardPullRequest;
use App\Module\Board\Repository\CardRepository;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use App\Tests\Module\Board\BoardColumnFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Ubermuda\FeatureFlagsBundle\Reader\FeatureFlagReaderInterface;

/** The placement of one card reads that card, so a bigger board costs it no more queries. */
final class ShowCardPlacementQueryCountTest extends KernelTestCase
{
    use BoardColumnFixtures;

    private EntityManagerInterface $em;
    private DebugDataHolder $queries;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $queries = self::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(DebugDataHolder::class, $queries);
        $this->queries = $queries;

        // The flag reader loads every flag once per request, so the first board read would count that query alone.
        $flags = self::getContainer()->get(FeatureFlagReaderInterface::class);
        self::assertInstanceOf(FeatureFlagReaderInterface::class, $flags);
        $flags->all();
    }

    public function test_a_bigger_board_costs_the_placement_no_more_queries(): void
    {
        $small = $this->board('placement-q-small', 1);
        $big = $this->board('placement-q-big', 10);
        $this->em->clear();

        $forSmall = $this->statementsOfPlacing($small);
        $forBig = $this->statementsOfPlacing($big);

        self::assertNotEmpty($forSmall);
        self::assertSame(\count($forSmall), \count($forBig), "The placement query count grew with the board:\n".implode("\n", $forBig));
        // The markers are stored on the card, so the placement reads no pull request, pause or hold for them.
        $stateReads = array_values(array_filter($forBig, static fn (string $sql): bool => 1 === preg_match('/board_card_pull_requests|forge_pull_requests|card_pauses|bridge_card_holds/', $sql)));
        self::assertSame([], $stateReads, 'The placement read what the stored markers replace.');
    }

    public function test_the_placement_of_an_ordinary_card_reads_no_other_card_body_and_no_whole_template(): void
    {
        [$project, $epic] = $this->board('placement-q-narrow', 2);
        $epic->laneEnabled = true;
        $this->em->flush();
        $cards = self::getContainer()->get(CardRepository::class);
        self::assertInstanceOf(CardRepository::class, $cards);
        $child = $cards->findOneBy(['parent' => $epic, 'type' => 'feature']);
        self::assertInstanceOf(Card::class, $child);
        $this->em->clear();

        $statements = $this->placementStatements($project, $child);

        // Guards: the placement ran, read the lane epics and the card types.
        self::assertNotEmpty($statements);
        self::assertNotEmpty(array_filter($statements, static fn (string $sql): bool => str_contains($sql, 'board_cards') && str_contains($sql, 'laneEnabled') || str_contains($sql, 'lane_enabled')));
        self::assertNotEmpty(array_filter($statements, static fn (string $sql): bool => str_contains($sql, 'workflow_bindings')));
        $bodyReads = array_values(array_filter($statements, static fn (string $sql): bool => 1 === preg_match('/FROM board_cards/', $sql) && 1 === preg_match('/\bbody\b/', explode(' FROM ', $sql)[0])));
        self::assertSame([], $bodyReads, 'The placement read the body of another card.');
        $wholeTemplate = array_values(array_filter($statements, static fn (string $sql): bool => str_contains($sql, 'workflow_bindings') && !str_contains($sql, 'definition->')));
        self::assertSame([], $wholeTemplate, 'The placement read the whole stored template.');
    }

    /**
     * An epic first in the in-progress column, with a triage column and an
     * empty column before it, and $perColumn cards in triage, in progress and done.
     *
     * @param non-empty-string $slug
     *
     * @return array{Project, Card}
     */
    private function board(string $slug, int $perColumn): array
    {
        $owner = new User(fullName: 'Riley', email: $slug.'@example.com', password: 'hashed');
        $this->em->persist($owner);
        $project = new Project($owner, $slug.'-'.uniqid());
        $this->em->persist($project);
        $this->seedColumns($project);
        $this->addTriageColumn($project);
        $this->em->flush();

        $number = 1;
        $epic = $this->card($project, 'in-progress', $number++, 0, 'epic');
        for ($i = 0; $i < $perColumn; ++$i) {
            foreach (['triage', 'in-progress', 'done'] as $column) {
                $card = $this->card($project, $column, $number++, $i + 1);
                $card->parent = $epic;
                $this->em->persist(new CardPullRequest($card, 'https://github.com/acme/app/pull/'.$number));
                $document = new Document(owner: $owner, project: $project, title: 'doc '.$number);
                $this->em->persist($document);
                $this->em->persist(new CardDocument($card, $document));
            }
        }
        $this->em->flush();

        return [$project, $epic];
    }

    private function card(Project $project, string $slug, int $number, int $position, string $type = 'feature'): Card
    {
        $column = $this->column($project, $slug);
        $card = new Card(project: $project, column: $column, title: 'Card '.$number, body: '', number: $number, type: $type, position: $position);
        if ($column->terminal) {
            $card->completedAt = new \DateTimeImmutable();
        }
        $this->em->persist($card);

        return $card;
    }

    /**
     * @param array{Project, Card} $board
     *
     * @return list<string>
     */
    private function statementsOfPlacing(array $board): array
    {
        $project = $this->em->find(Project::class, $board[0]->id);
        $card = $this->em->find(Card::class, $board[1]->id);
        self::assertInstanceOf(Project::class, $project);
        self::assertInstanceOf(Card::class, $card);
        $handler = self::getContainer()->get(ShowCardPlacementHandler::class);
        self::assertInstanceOf(ShowCardPlacementHandler::class, $handler);

        $this->queries->reset();
        $view = $handler(new ShowCardPlacementCommand($project, $card));

        // Guard: the counts mean nothing unless the card was placed and read its children.
        self::assertSame($card, $view->card);
        self::assertNotNull($view->rowAfter);
        self::assertGreaterThan(0, $view->progress?->total);

        return $this->collected();
    }

    /** @return list<string> */
    private function placementStatements(Project $project, Card $card): array
    {
        $project = $this->em->find(Project::class, $project->id);
        $card = $this->em->find(Card::class, $card->id);
        self::assertInstanceOf(Project::class, $project);
        self::assertInstanceOf(Card::class, $card);
        $handler = self::getContainer()->get(ShowCardPlacementHandler::class);
        self::assertInstanceOf(ShowCardPlacementHandler::class, $handler);

        $this->queries->reset();
        $view = $handler(new ShowCardPlacementCommand($project, $card));
        self::assertSame($card, $view->card);

        return $this->collected();
    }

    /** @return list<string> */
    private function collected(): array
    {
        $statements = [];
        foreach ($this->queries->getData() as $connectionQueries) {
            foreach ($connectionQueries as $query) {
                $statements[] = (string) $query['sql'];
            }
        }

        return $statements;
    }
}
