<?php

declare(strict_types=1);

namespace App\Tests\DevSeed;

use App\Module\Board\Entity\Card;
use App\Module\Board\Service\Dev\DevMergeQuestionMarkerSeeder;
use App\Module\Board\Service\StoredCardMarkers;
use App\Module\Workflow\Contract\MarkerLink;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class MergeQuestionMarkerSeedTest extends KernelTestCase
{
    public function test_the_seed_writes_one_card_with_the_merge_question_marker_that_the_minute_task_leaves_alone(): void
    {
        $this->seed();
        $this->seed();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        $card = $em->find(Card::class, DevMergeQuestionMarkerSeeder::cardId()) ?? throw new \LogicException('The card is missing.');
        self::assertSame('implementation', $card->column->slug);
        self::assertSame([], $card->inboxMarkers);
        self::assertNull($card->markersDueAt);

        $marker = StoredCardMarkers::of($card)->primary();
        self::assertNotNull($marker);
        self::assertSame('waits-for-merge-approval-answer', $marker->key);
        self::assertSame(MarkerLink::Inbox, $marker->link);
    }

    private function seed(): void
    {
        $kernel = self::bootKernel();
        $tester = new CommandTester(new Application($kernel)->find('app:dev:seed'));
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();
    }
}
