<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Repository;

use App\Module\Account\Entity\User;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardEventKind;
use App\Module\Board\Repository\CardEventRepository;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\Actor;
use App\Tests\Module\Board\BoardColumnFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CardEventRepositoryNewestMoveTest extends KernelTestCase
{
    use BoardColumnFixtures;

    private EntityManagerInterface $em;
    private CardEventRepository $events;
    private Project $project;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $events = self::getContainer()->get(CardEventRepository::class);
        self::assertInstanceOf(CardEventRepository::class, $events);
        $this->events = $events;

        $owner = new User(fullName: 'Riley', email: 'newest-move-'.uniqid().'@example.com', password: 'hashed');
        $this->em->persist($owner);
        $this->project = new Project($owner, 'newest-move-'.uniqid());
        $this->em->persist($this->project);
        $this->seedColumns($this->project);
        $this->em->flush();
    }

    public function test_it_returns_the_newest_move_of_each_card(): void
    {
        $card = $this->card(1);
        $other = $this->card(2);
        $this->move($card, CardEventKind::Moved, '2026-10-02 09:00:00');
        $this->move($card, CardEventKind::Moved, '2026-10-02 11:00:00');
        $this->move($card, CardEventKind::Moved, '2026-10-02 10:00:00');
        $this->move($other, CardEventKind::Moved, '2026-10-03 08:00:00');

        $found = $this->events->findNewestMoveAtOfCards([(string) $card->id, (string) $other->id]);

        self::assertCount(2, $found);
        self::assertEquals(new \DateTimeImmutable('2026-10-02 11:00:00'), $found[(string) $card->id]);
        self::assertEquals(new \DateTimeImmutable('2026-10-03 08:00:00'), $found[(string) $other->id]);
    }

    public function test_a_card_with_no_move_has_no_key_and_other_kinds_do_not_count(): void
    {
        $card = $this->card(1);
        $this->move($card, CardEventKind::Created, '2026-10-02 09:00:00');

        self::assertSame([], $this->events->findNewestMoveAtOfCards([(string) $card->id]));
    }

    public function test_no_ids_read_nothing(): void
    {
        self::assertSame([], $this->events->findNewestMoveAtOfCards([]));
    }

    private function move(Card $card, CardEventKind $kind, string $at): void
    {
        $this->events->record($card, $kind, Actor::Human, null, [], new \DateTimeImmutable($at));
        $this->em->flush();
    }

    private function card(int $number): Card
    {
        $card = new Card($this->project, $this->column($this->project, 'backlog'), 'A card', '', $number, 'feature');
        $this->em->persist($card);
        $this->em->flush();

        return $card;
    }
}
