<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Repository;

use App\Module\Account\Entity\User;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardEvent;
use App\Module\Board\Entity\CardEventKind;
use App\Module\Board\Repository\CardEventRepository;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\Actor;
use App\Tests\Module\Board\BoardColumnFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CardEventRepositoryHistoryPageTest extends KernelTestCase
{
    use BoardColumnFixtures;

    public function test_the_pages_of_the_history_walk_every_row_of_one_second(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $events = self::getContainer()->get(CardEventRepository::class);
        self::assertInstanceOf(CardEventRepository::class, $events);

        $owner = new User(fullName: 'Riley', email: 'history-page-'.uniqid().'@example.com', password: 'hashed');
        $em->persist($owner);
        $project = new Project($owner, 'history-page-'.uniqid());
        $em->persist($project);
        $this->seedColumns($project);
        $card = new Card($project, $this->column($project, 'backlog'), 'A card', '', 1, 'feature');
        $em->persist($card);
        foreach (['10:00:00.100000', '10:00:00.500000', '10:00:00.900000'] as $time) {
            $events->record($card, CardEventKind::Moved, Actor::Human, null, [], new \DateTimeImmutable('2026-10-22 '.$time));
        }
        $em->flush();
        $em->clear();

        $seen = [];
        $last = null;
        do {
            $page = $events->findPageBeforeForCard($card, $last?->occurredAt, $last?->id?->toRfc4122(), 1);
            $last = $page[0] ?? null;
            if ($last instanceof CardEvent) {
                $seen[] = $last->occurredAt->format('H:i:s.u');
            }
        } while (null !== $last && \count($seen) < 4);

        self::assertSame(['10:00:00.900000', '10:00:00.500000', '10:00:00.100000'], $seen);
    }
}
