<?php

declare(strict_types=1);

namespace App\Tests\DevSeed;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardEvent;
use App\Module\Board\Entity\CardEventKind;
use App\Module\Board\Service\CardValueReader;
use App\Module\Board\Service\Dev\DevBoardSeeder;
use App\Module\Board\Service\Dev\DevInternalValueSeeder;
use App\Module\Board\View\CardValueView;
use App\Module\Workflow\Contract\Actor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\Uuid;

final class InternalValueSeedTest extends KernelTestCase
{
    public function test_the_seed_writes_an_internal_value_on_one_card_and_none_on_its_twins_once(): void
    {
        $this->seed();
        $this->seed();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $reader = self::getContainer()->get(CardValueReader::class);

        $on = $this->internalViews($em, $reader, DevInternalValueSeeder::cardId('on'));
        self::assertCount(1, $on);
        self::assertTrue($on[0]->value);
        self::assertSame(Actor::System, $on[0]->writerKind);
        self::assertCount(1, $em->getRepository(CardEvent::class)->findBy(['card' => DevInternalValueSeeder::cardId('on'), 'kind' => CardEventKind::ValueChanged]));

        $unset = $this->internalViews($em, $reader, DevInternalValueSeeder::cardId('unset'));
        self::assertCount(1, $unset);
        self::assertNull($unset[0]->value);
        self::assertNull($unset[0]->writerKind);

        self::assertSame([], $this->internalViews($em, $reader, DevBoardSeeder::cardId('lifecycle-values')));
    }

    /** @return list<CardValueView> */
    private function internalViews(EntityManagerInterface $em, CardValueReader $reader, Uuid $id): array
    {
        $card = $em->find(Card::class, $id) ?? throw new \LogicException('The card is missing.');

        return array_values(array_filter($reader->forCard($card), static fn (CardValueView $view): bool => $view->isInternal() && DevInternalValueSeeder::VALUE_KEY === $view->key));
    }

    private function seed(): void
    {
        $kernel = self::bootKernel();
        $tester = new CommandTester(new Application($kernel)->find('app:dev:seed'));
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();
    }
}
