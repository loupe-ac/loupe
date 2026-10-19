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

final class CardEventRepositoryKindsOfCardsTest extends KernelTestCase
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

        $owner = new User(fullName: 'Riley', email: 'kinds-of-cards-'.uniqid().'@example.com', password: 'hashed');
        $this->em->persist($owner);
        $this->project = new Project($owner, 'kinds-of-cards-'.uniqid());
        $this->em->persist($this->project);
        $this->seedColumns($this->project);
        $this->em->flush();
    }

    public function test_it_returns_the_events_of_a_card_oldest_first_whatever_the_insert_order(): void
    {
        $card = $this->card();
        $this->record($card, 'later', '2026-10-02 11:00:00');
        $this->record($card, 'earlier', '2026-10-02 09:00:00');
        $this->record($card, 'middle', '2026-10-02 10:00:00');

        $rows = $this->events->findKindsOfCards($this->project, [$card->id ?? throw new \LogicException('A flushed card has an id.')], [CardEventKind::FixRequested]);

        self::assertSame(['earlier', 'middle', 'later'], array_map(static fn (array $row): mixed => $row['detail']['reason'], $rows));
    }

    private function record(Card $card, string $reason, string $at): void
    {
        $this->events->record($card, CardEventKind::FixRequested, Actor::System, null, ['reason' => $reason], new \DateTimeImmutable($at));
        $this->em->flush();
    }

    private function card(): Card
    {
        $card = new Card($this->project, $this->column($this->project, 'backlog'), 'A card', '', 1, 'feature');
        $this->em->persist($card);
        $this->em->flush();

        return $card;
    }
}
