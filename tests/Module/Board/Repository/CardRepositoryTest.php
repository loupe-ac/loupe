<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Repository;

use App\Module\Board\Command\CreateCardCommand;
use App\Module\Board\Command\CreateCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardLink;
use App\Module\Board\Entity\CardLinkKind;
use App\Module\Board\Repository\CardRepository;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Board\Mcp\BoardToolScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CardRepositoryTest extends KernelTestCase
{
    use BoardToolScenario;

    private EntityManagerInterface $em;
    private CardRepository $cards;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $cards = self::getContainer()->get(CardRepository::class);
        self::assertInstanceOf(CardRepository::class, $cards);
        $this->cards = $cards;
    }

    private function cardIn(Project $project): Card
    {
        $handler = self::getContainer()->get(CreateCardHandler::class);
        self::assertInstanceOf(CreateCardHandler::class, $handler);

        return $handler(new CreateCardCommand($project, 'Ship it', 'Body', 'feature'));
    }

    public function test_a_number_resolves_inside_its_own_project_only(): void
    {
        $mine = $this->makeProject('repo-number-mine');
        $theirs = $this->makeProject('repo-number-theirs');
        $theirCard = $this->cardIn($theirs);
        $myCard = $this->cardIn($mine);

        // Guard: both projects hold a card 1, so a lookup that ignores the project could match either.
        self::assertSame(1, $myCard->number);
        self::assertSame(1, $theirCard->number);

        self::assertSame($myCard, $this->cards->findOneByProjectAndNumber($mine, 1));
        self::assertSame($theirCard, $this->cards->findOneByProjectAndNumber($theirs, 1));
        self::assertNull($this->cards->findOneByProjectAndNumber($mine, 2));
    }

    public function test_child_ids_are_the_children_of_that_card_only(): void
    {
        $project = $this->makeProject('repo-child-ids');
        $epic = $this->cardIn($project);
        $child = $this->cardIn($project);
        $other = $this->cardIn($project);
        $child->parent = $epic;
        $this->em->flush();

        self::assertSame([(string) $child->id], $this->cards->findChildIds($epic->id ?? throw new \LogicException('A stored card has an id.')));
        self::assertSame([], $this->cards->findChildIds($other->id ?? throw new \LogicException('A stored card has an id.')));
    }

    public function test_related_for_evaluation_are_the_parents_then_the_blocked_cards_each_once_without_the_given_ids(): void
    {
        $project = $this->makeProject('repo-related-evaluation');
        $parent = $this->cardIn($project);
        $one = $this->cardIn($project);
        $two = $this->cardIn($project);
        $lowBlocked = $this->cardIn($project);
        $highBlocked = $this->cardIn($project);
        $unrelated = $this->cardIn($project);
        $one->parent = $parent;
        $two->parent = $parent;
        $this->em->persist(new CardLink($one, $highBlocked, CardLinkKind::Blocks));
        $this->em->persist(new CardLink($two, $lowBlocked, CardLinkKind::Blocks));
        $this->em->persist(new CardLink($two, $parent, CardLinkKind::Blocks));
        $this->em->persist(new CardLink($one, $two, CardLinkKind::Blocks));
        $this->em->persist(new CardLink($unrelated, $lowBlocked, CardLinkKind::Blocks));
        $this->em->flush();

        self::assertSame(
            [(string) $parent->id, (string) $lowBlocked->id, (string) $highBlocked->id],
            $this->cards->findRelatedForEvaluation([(string) $one->id, (string) $two->id]),
        );
        self::assertSame([], $this->cards->findRelatedForEvaluation([(string) $unrelated->id, (string) $lowBlocked->id]));
        self::assertSame([], $this->cards->findRelatedForEvaluation([]));
    }

    public function test_a_card_has_a_closed_blocker_only_when_a_blocking_card_sits_in_a_terminal_column(): void
    {
        $project = $this->makeProject('repo-closed-blocker');
        $open = $this->epicIn($project, 'Open', 'next', 0, 'feature');
        $done = $this->epicIn($project, 'Done', 'done', 0, 'feature');
        $heldByOpen = $this->cardIn($project);
        $heldByDone = $this->cardIn($project);
        $follower = $this->cardIn($project);
        $this->em->persist(new CardLink($open, $heldByOpen, CardLinkKind::Blocks));
        $this->em->persist(new CardLink($done, $heldByDone, CardLinkKind::Blocks));
        $this->em->persist(new CardLink($done, $follower, CardLinkKind::HasFollowUp));
        $this->em->flush();

        // Guard: the done card blocks one card, so a query that ignores the column or the kind still shows a difference.
        self::assertCount(1, $this->cards->findBlockedBy($done));
        self::assertTrue($this->cards->hasClosedBlocker($heldByDone));
        self::assertFalse($this->cards->hasClosedBlocker($heldByOpen));
        self::assertFalse($this->cards->hasClosedBlocker($follower));
    }

    public function test_a_follow_up_row_makes_the_follow_up_related_to_the_followed_card(): void
    {
        $project = $this->makeProject('repo-follow-up-related');
        $followed = $this->cardIn($project);
        $followUp = $this->cardIn($project);
        $this->em->persist(new CardLink($followed, $followUp, CardLinkKind::HasFollowUp));
        $this->em->flush();

        self::assertSame([(string) $followUp->id], $this->cards->findRelatedForEvaluation([(string) $followed->id]));
        self::assertSame([], $this->cards->findRelatedForEvaluation([(string) $followUp->id]));
    }

    public function test_followed_cards_open_count_counts_the_cards_a_card_follows_and_those_not_finished(): void
    {
        $project = $this->makeProject('repo-follow-up-count');
        $open = $this->epicIn($project, 'Open', 'next', 0, 'feature');
        $done = $this->epicIn($project, 'Done', 'done', 0, 'feature');
        $followUp = $this->cardIn($project);
        $loner = $this->cardIn($project);
        $this->em->persist(new CardLink($open, $followUp, CardLinkKind::HasFollowUp));
        $this->em->persist(new CardLink($done, $followUp, CardLinkKind::HasFollowUp));
        $this->em->persist(new CardLink($done, $loner, CardLinkKind::Blocks));
        $this->em->flush();

        self::assertSame(['followed' => 2, 'open' => 1], $this->cards->followedCardsOpenCount($followUp));
        self::assertSame(['followed' => 0, 'open' => 0], $this->cards->followedCardsOpenCount($loner));
    }

    public function test_refresh_type_and_parent_reads_the_lane_setting_the_database_holds(): void
    {
        $card = $this->cardIn($this->makeProject('repo-refresh-lane'));
        self::assertTrue($card->laneEnabled);

        $this->em->getConnection()->executeStatement(
            "UPDATE board_cards SET lane_enabled = false, type = 'epic' WHERE id = :id",
            ['id' => (string) $card->id],
        );
        $this->cards->refreshTypeAndParent($card);

        self::assertFalse($card->laneEnabled);
        self::assertSame('epic', $card->type);
    }

    public function test_lane_epics_are_the_open_epics_with_their_lane_on_in_column_then_rank_order(): void
    {
        $project = $this->makeProject('repo-lane-epics');
        $this->epicIn($project, 'Next second', 'next', 1);
        $this->epicIn($project, 'Next first', 'next', 0);
        $this->epicIn($project, 'In progress', 'in-progress', 0);
        $this->epicIn($project, 'Waiting in the Backlog', 'backlog', 3);
        $this->epicIn($project, 'Lane off', 'next', 2)->laneEnabled = false;
        $this->epicIn($project, 'Done', 'done', 0);
        $this->epicIn($project, 'Plain card', 'backlog', 0, 'feature');
        $this->epicIn($this->makeProject('repo-lane-epics-other'), 'Other project', 'next', 0);
        $this->em->flush();
        $this->em->clear();

        $project = $this->em->find(Project::class, $project->id) ?? throw new \LogicException('The project is gone.');

        self::assertSame(
            ['Waiting in the Backlog', 'Next first', 'Next second', 'In progress'],
            array_map(static fn (Card $card): string => $card->title, $this->cards->findLaneEpics($project, ['epic'])),
        );
    }

    private function epicIn(Project $project, string $title, string $slug, int $position, string $type = 'epic'): Card
    {
        $column = $this->column($project, $slug);
        $card = new Card(project: $project, column: $column, title: $title, body: '', number: random_int(1, 1_000_000), type: $type, position: $position);
        if ($column->terminal) {
            $card->completedAt = new \DateTimeImmutable();
        }
        $this->em->persist($card);
        $this->em->flush();

        return $card;
    }
}
