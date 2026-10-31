<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Command;

use App\Module\Account\Entity\User;
use App\Module\Board\Command\CreateCardCommand;
use App\Module\Board\Command\CreateCardHandler;
use App\Module\Board\Command\DeleteCardCommand;
use App\Module\Board\Command\DeleteCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Event\CardDeleted;
use App\Module\Board\Event\CardDeleting;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\Actor;
use App\Tests\Module\Board\BoardColumnFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class DeleteCardEventsTest extends KernelTestCase
{
    use BoardColumnFixtures;

    public function test_the_deleting_event_fires_inside_the_transaction_before_the_row_goes(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $owner = new User(fullName: 'Riley', email: 'board-deleting-'.uniqid().'@example.com', password: 'hashed');
        $em->persist($owner);
        $project = new Project($owner, 'board-deleting-'.uniqid());
        $em->persist($project);
        $this->seedColumns($project);
        $em->flush();

        $create = self::getContainer()->get(CreateCardHandler::class);
        self::assertInstanceOf(CreateCardHandler::class, $create);
        $card = $create(new CreateCardCommand(project: $project, title: 'Card', body: 'Body', type: 'bug', column: $this->column($project, 'next'), reporter: Actor::Agent));
        $cardId = (string) $card->id;

        $seen = [];
        $connection = $em->getConnection();
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(CardDeleting::class, static function (CardDeleting $event) use (&$seen, $connection, $cardId): void {
            $seen[] = [
                'deleting',
                $event->card->id?->toRfc4122() === $cardId,
                $connection->isTransactionActive(),
                1 === (int) $connection->fetchOne('SELECT COUNT(*) FROM board_cards WHERE id = ?', [$cardId]),
            ];
        });
        $dispatcher->addListener(CardDeleted::class, static function () use (&$seen, $connection, $cardId): void {
            $seen[] = ['deleted', 0 === (int) $connection->fetchOne('SELECT COUNT(*) FROM board_cards WHERE id = ?', [$cardId])];
        });

        $delete = self::getContainer()->get(DeleteCardHandler::class);
        self::assertInstanceOf(DeleteCardHandler::class, $delete);
        $delete(new DeleteCardCommand($card, Actor::Human));

        self::assertSame([['deleting', true, true, true], ['deleted', true]], $seen);
        self::assertNull($em->find(Card::class, $cardId));
    }
}
