<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Repository;

use App\Module\Account\Entity\User;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardValue;
use App\Module\Board\Repository\CardValueRepository;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\Actor;
use App\Tests\Module\Board\BoardColumnFixtures;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CardValueRepositoryTest extends KernelTestCase
{
    use BoardColumnFixtures;

    private EntityManagerInterface $em;
    private CardValueRepository $values;
    private Card $card;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $values = self::getContainer()->get(CardValueRepository::class);
        self::assertInstanceOf(CardValueRepository::class, $values);
        $this->values = $values;

        $owner = new User(fullName: 'Riley', email: 'card-values-'.uniqid().'@example.com', password: 'hashed');
        $this->em->persist($owner);
        $project = new Project($owner, 'card-values-'.uniqid());
        $this->em->persist($project);
        $this->seedColumns($project);
        $this->card = new Card(project: $project, column: $this->column($project, 'backlog'), title: 'A card', body: '', number: 1);
        $this->em->persist($this->card);
        $this->em->flush();
    }

    public function test_it_finds_the_values_of_a_card_by_key(): void
    {
        $this->em->persist(new CardValue($this->card, 'b-key', true, Actor::Human));
        $this->em->persist(new CardValue($this->card, 'a-key', false, Actor::System));
        $this->em->flush();

        self::assertSame(['a-key', 'b-key'], array_map(static fn (CardValue $v): string => $v->key, $this->values->findForCard($this->card)));
        $one = $this->values->findOneForCard($this->card, 'a-key');
        self::assertInstanceOf(CardValue::class, $one);
        self::assertFalse($one->value);
        self::assertNull($this->values->findOneForCard($this->card, 'missing'));
    }

    public function test_the_fresh_lookup_reads_what_the_database_holds(): void
    {
        $row = new CardValue($this->card, 'a-key', true, Actor::Human);
        $this->em->persist($row);
        $this->em->flush();
        $this->em->getConnection()->executeStatement("UPDATE board_card_values SET value = 'false' WHERE id = :id", ['id' => (string) $row->id]);

        $fresh = $this->values->findOneForCardFresh($this->card, 'a-key');

        self::assertSame($row, $fresh);
        self::assertFalse($fresh->value);
    }

    public function test_a_card_holds_one_row_for_a_key(): void
    {
        $this->em->persist(new CardValue($this->card, 'a-key', true, Actor::Human));
        $this->em->flush();
        $this->em->persist(new CardValue($this->card, 'a-key', false, Actor::Human));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->flush();
    }

    public function test_deleting_a_card_removes_its_values(): void
    {
        $this->em->persist(new CardValue($this->card, 'a-key', true, Actor::Human));
        $this->em->flush();
        $id = (string) $this->card->id;

        $this->em->getConnection()->executeStatement('DELETE FROM board_cards WHERE id = :id', ['id' => $id]);

        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM board_card_values WHERE card_id = :id', ['id' => $id]));
    }
}
