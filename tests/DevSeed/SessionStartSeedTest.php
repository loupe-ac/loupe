<?php

declare(strict_types=1);

namespace App\Tests\DevSeed;

use App\Module\Account\Entity\User;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardValue;
use App\Module\Board\Service\Dev\DevSessionStartSeeder;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\Review;
use App\Module\Review\Entity\Verdict;
use App\Module\Review\Service\Dev\DevDocumentSeeder;
use App\Module\Workflow\Contract\Actor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class SessionStartSeedTest extends KernelTestCase
{
    public function test_the_seed_writes_the_two_session_start_cards_once(): void
    {
        $this->seed();
        $this->seed();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        foreach (['ask', 'approved'] as $state) {
            $card = $em->find(Card::class, DevSessionStartSeeder::cardId($state)) ?? throw new \LogicException('The card is missing.');
            self::assertSame('tech-design', $card->column->slug);
            self::assertCount(1, $card->documents);

            $switch = $em->getRepository(CardValue::class)->findOneBy(['card' => $card, 'key' => DevSessionStartSeeder::SWITCH_KEY]);
            self::assertInstanceOf(CardValue::class, $switch);
            self::assertTrue($switch->value);

            $link = $card->documents->first();
            self::assertNotFalse($link);
            $reviews = $em->getRepository(Review::class)->findBy(['version' => $link->document->currentVersion()]);
            self::assertCount(1, $reviews);
            self::assertSame(Verdict::Approved, $reviews[0]->verdict);
            self::assertSame(User::WORKFLOW_ID, (string) $reviews[0]->reviewer->id);
        }

        $ask = $em->find(Card::class, DevSessionStartSeeder::cardId('ask')) ?? throw new \LogicException('The card is missing.');
        self::assertNotInstanceOf(CardValue::class, $em->getRepository(CardValue::class)->findOneBy(['card' => $ask, 'key' => DevSessionStartSeeder::APPROVED_KEY]));

        $approved = $em->find(Card::class, DevSessionStartSeeder::cardId('approved')) ?? throw new \LogicException('The card is missing.');
        $value = $em->getRepository(CardValue::class)->findOneBy(['card' => $approved, 'key' => DevSessionStartSeeder::APPROVED_KEY]);
        self::assertInstanceOf(CardValue::class, $value);
        self::assertTrue($value->value);
        self::assertSame(Actor::System, $value->writerKind);
    }

    public function test_the_documents_belong_to_the_lifecycle_project(): void
    {
        $this->seed();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        foreach (array_keys(DevDocumentSeeder::SESSION_START_SLUGS) as $slug) {
            $document = $em->find(Document::class, DevDocumentSeeder::documentId($slug));
            self::assertNotNull($document);
            self::assertSame(['product-design'], array_map(static fn ($tag): string => $tag->name, $document->tags->toArray()));
        }
    }

    private function seed(): void
    {
        $kernel = self::bootKernel();
        $tester = new CommandTester(new Application($kernel)->find('app:dev:seed'));
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();
    }
}
