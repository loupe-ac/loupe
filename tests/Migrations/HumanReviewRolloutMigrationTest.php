<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Module\Account\Entity\User;
use App\Module\Artifact\Entity\Artifact;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardEvent;
use App\Module\Board\Entity\CardEventKind;
use App\Module\Board\Repository\CardEventRepository;
use App\Module\Project\Entity\Project;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20261026175200;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__.'/../../migrations/Version20261026175200.php';

final class HumanReviewRolloutMigrationTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->connection = $em->getConnection();
    }

    public function test_it_turns_the_switch_on_for_each_open_card_that_an_artifact_reaches_and_leaves_the_others(): void
    {
        $owner = new User(fullName: 'Riley', email: 'rollout-'.uniqid().'@example.com', password: 'hashed');
        $this->em->persist($owner);
        $project = new Project($owner, 'rollout-'.uniqid());
        $this->em->persist($project);
        $open = new BoardColumn(project: $project, label: 'Next', slug: 'next', position: 0, terminal: false);
        $done = new BoardColumn(project: $project, label: 'Done', slug: 'done', position: 1, terminal: true);
        $this->em->persist($open);
        $this->em->persist($done);

        $epic = $this->card($project, $open, 1);
        $child = $this->card($project, $open, 2);
        $child->parent = $epic;
        $second = $this->card($project, $open, 3);
        $second->parent = $epic;
        $plain = $this->card($project, $open, 4);
        $finished = $this->card($project, $done, 5);
        $already = $this->card($project, $open, 6);
        $already->requiresHumanReview = true;

        $lent = $this->artifact($project, 'Lent');
        $own = $this->artifact($project, 'Own');
        $epic->linkArtifact($lent);
        $child->linkArtifact($own);
        $finished->linkArtifact($own);
        $already->linkArtifact($own);
        $this->em->flush();
        $ids = array_map(static fn (Card $card): string => (string) $card->id, [$epic, $child, $second, $plain, $finished, $already]);
        $this->em->clear();

        $this->migrate();
        $this->em->clear();

        [$epicId, $childId, $secondId, $plainId, $finishedId, $alreadyId] = $ids;
        self::assertEquals([true, [(string) $lent->id]], $this->state($epicId));
        self::assertEquals([true, self::sorted([(string) $lent->id, (string) $own->id])], $this->state($childId));
        self::assertEquals([true, [(string) $lent->id]], $this->state($secondId));
        self::assertEquals([false, []], $this->state($plainId), 'No artifact reaches it.');
        self::assertEquals([false, []], $this->state($finishedId), 'A card in a terminal column keeps its state.');
        self::assertEquals([true, []], $this->state($alreadyId), 'A card whose switch is on keeps its seen list.');

        $row = $this->rows($childId);
        self::assertCount(1, $row);
        self::assertEquals(['value' => true, 'cause' => 'rollout', 'ruleId' => null, 'artifactIds' => self::sorted([(string) $lent->id, (string) $own->id])], $row[0]->detail);
        self::assertSame('system', $row[0]->actorKind->value);
        self::assertCount(1, $this->rows($epicId));
        self::assertSame([], $this->rows($plainId));
        self::assertSame([], $this->rows($finishedId));
        self::assertSame([], $this->rows($alreadyId));

        $this->migrate();

        self::assertCount(1, $this->rows($childId), 'A second run writes no second row.');
    }

    private function card(Project $project, BoardColumn $column, int $number): Card
    {
        $card = new Card(project: $project, column: $column, title: 'Card '.$number, body: '', number: $number);
        $this->em->persist($card);

        return $card;
    }

    private function artifact(Project $project, string $name): Artifact
    {
        $artifact = new Artifact($project, $name);
        $this->em->persist($artifact);

        return $artifact;
    }

    /** @return array{bool, list<string>} */
    private function state(string $cardId): array
    {
        $row = $this->connection->fetchAssociative('SELECT requires_human_review, human_review_seen_artifacts FROM board_cards WHERE id = ?', [$cardId]);
        self::assertIsArray($row);

        return [(bool) $row['requires_human_review'], json_decode((string) $row['human_review_seen_artifacts'], true, flags: \JSON_THROW_ON_ERROR)];
    }

    /** @return list<CardEvent> */
    private function rows(string $cardId): array
    {
        $card = $this->em->find(Card::class, $cardId) ?? throw new \LogicException('The card exists.');
        $repository = self::getContainer()->get(CardEventRepository::class);
        self::assertInstanceOf(CardEventRepository::class, $repository);

        return array_values(array_filter($repository->findForCard($card), static fn (CardEvent $event): bool => CardEventKind::HumanReviewChanged === $event->kind));
    }

    /**
     * @param list<string> $ids
     *
     * @return list<string>
     */
    private static function sorted(array $ids): array
    {
        sort($ids);

        return $ids;
    }

    private function migrate(): void
    {
        $migration = new Version20261026175200($this->connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }
}
