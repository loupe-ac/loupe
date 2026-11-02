<?php

declare(strict_types=1);

namespace App\Tests\DevSeed;

use App\Module\Board\Entity\CardEvent;
use App\Module\Board\Service\Dev\DevBoardSeeder;
use App\Module\Review\Entity\Comment;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentVersion;
use App\Module\Review\Entity\Review;
use App\Module\Review\Service\Dev\DevDocumentSeeder;
use App\Module\Workflow\Contract\Actor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class ApiKeyRecordsSeedTest extends KernelTestCase
{
    public function test_the_seed_writes_each_record_of_an_api_key_and_its_twin_once(): void
    {
        $this->seed();
        $this->seed();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        $keyCard = $em->getRepository(CardEvent::class)->findBy(['card' => DevBoardSeeder::cardId('api-key-history')]);
        self::assertCount(1, $keyCard);
        self::assertSame(Actor::ApiKey, $keyCard[0]->actorKind);
        self::assertSame('CI', $keyCard[0]->apiKeyName);
        self::assertEquals(DevBoardSeeder::apiKeyId(), $keyCard[0]->apiKeyId);

        $personCard = $em->getRepository(CardEvent::class)->findBy(['card' => DevBoardSeeder::cardId('person-history')]);
        self::assertCount(1, $personCard);
        self::assertSame(Actor::Human, $personCard[0]->actorKind);
        self::assertNull($personCard[0]->apiKeyId);

        $keyedReviews = ['key-verdict' => true, 'key-comment' => false, 'person-twin' => false];
        foreach ($keyedReviews as $slug => $byKey) {
            $reviews = $em->getRepository(Review::class)->findBy(['version' => $this->versionOf($em, $slug)]);
            self::assertCount(1, $reviews, $slug);
            self::assertSame($byKey, null !== $reviews[0]->apiKeyId, $slug);
        }

        $keyedComments = ['key-verdict' => false, 'key-comment' => true, 'person-twin' => false];
        foreach ($keyedComments as $slug => $byKey) {
            $comments = $em->getRepository(Comment::class)->findBy(['version' => $this->versionOf($em, $slug)]);
            self::assertCount(1, $comments, $slug);
            self::assertSame($byKey, null !== $comments[0]->apiKeyId, $slug);
        }
    }

    private function versionOf(EntityManagerInterface $em, string $slug): DocumentVersion
    {
        return ($em->find(Document::class, DevDocumentSeeder::documentId($slug)) ?? throw new \LogicException('The document '.$slug.' is missing.'))->currentVersion();
    }

    private function seed(): void
    {
        $kernel = self::bootKernel();
        $tester = new CommandTester(new Application($kernel)->find('app:dev:seed'));
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();
    }
}
