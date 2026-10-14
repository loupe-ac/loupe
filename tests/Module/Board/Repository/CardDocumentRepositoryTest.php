<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Repository;

use App\Module\Account\Entity\User;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardDocument;
use App\Module\Board\Repository\CardDocumentRepository;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\Tag;
use App\Tests\Module\Board\BoardColumnFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\PersistentCollection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class CardDocumentRepositoryTest extends KernelTestCase
{
    use BoardColumnFixtures;

    private EntityManagerInterface $em;
    private CardDocumentRepository $links;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $links = self::getContainer()->get(CardDocumentRepository::class);
        self::assertInstanceOf(CardDocumentRepository::class, $links);
        $this->links = $links;
    }

    public function test_in_review_rows_come_one_per_link_with_the_tags_and_the_column_loaded(): void
    {
        $owner = new User(fullName: 'Riley', email: 'card-documents-'.uniqid().'@example.com', password: 'hashed');
        $this->em->persist($owner);
        $project = new Project($owner, 'card-documents-'.uniqid());
        $this->em->persist($project);
        $this->seedColumns($project);
        $first = new Card(project: $project, column: $this->column($project, 'backlog'), title: 'One', body: 'Body', number: 1);
        $second = new Card(project: $project, column: $this->column($project, 'next'), title: 'Two', body: 'Body', number: 2);
        $this->em->persist($first);
        $this->em->persist($second);
        $document = new Document($owner, $project, 'Tech design');
        $document->addVersion('# One', '<h1>One</h1>');
        $document->addVersion('# Two', '<h1>Two</h1>');
        $document->tags->add(new Tag($project, 'design'));
        $document->tags->add(new Tag($project, 'decisions'));
        foreach ($document->tags as $tag) {
            $this->em->persist($tag);
        }
        $this->em->persist($document);
        $this->em->persist(new CardDocument($first, $document));
        $this->em->persist(new CardDocument($second, $document));
        $this->em->flush();
        $this->em->clear();

        $rows = $this->links->findInReviewForCards($project, [$this->id($first), $this->id($second)]);

        self::assertCount(2, $rows);
        self::assertSame(['1', '2'], array_map(static fn (array $row): string => (string) $row['link']->card->number, $rows));
        self::assertSame(['backlog', 'next'], array_map(static fn (array $row): string => $row['link']->card->column->slug, $rows));
        foreach ($rows as $row) {
            self::assertSame(2, $row['versionNumber']);
            $tags = $row['link']->document->tags;
            self::assertInstanceOf(PersistentCollection::class, $tags);
            self::assertTrue($tags->isInitialized());
            self::assertCount(2, $tags);
        }
    }

    public function test_links_made_in_the_same_second_come_in_id_order(): void
    {
        $owner = new User(fullName: 'Riley', email: 'card-documents-tie-'.uniqid().'@example.com', password: 'hashed');
        $this->em->persist($owner);
        $project = new Project($owner, 'card-documents-tie-'.uniqid());
        $this->em->persist($project);
        $this->seedColumns($project);
        $document = new Document($owner, $project, 'Tech design');
        $document->addVersion('# One', '<h1>One</h1>');
        $this->em->persist($document);
        $linkedAt = new \DateTimeImmutable('2026-01-01 00:00:00');
        foreach ([1, 2, 3, 4] as $number) {
            $card = new Card(project: $project, column: $this->column($project, 'backlog'), title: 'Card '.$number, body: 'Body', number: $number);
            $this->em->persist($card);
            $this->em->persist(new CardDocument($card, $document, $linkedAt));
        }
        $this->em->flush();
        $ids = [Uuid::v7(), Uuid::v7(), Uuid::v7(), Uuid::v7()];
        foreach ([4, 3, 2, 1] as $index => $number) {
            $this->em->getConnection()->executeStatement(
                'UPDATE board_card_documents SET id = ? WHERE card_id = (SELECT id FROM board_cards WHERE number = ? AND project_id = ?)',
                [(string) $ids[$index], $number, (string) $project->id],
            );
        }
        $this->em->clear();
        $document = $this->em->find(Document::class, $document->id) ?? self::fail('No document');

        foreach ([$this->links->findForDocument($document), $this->links->findForDocuments([$document])] as $links) {
            $order = array_map(static fn (CardDocument $link): string => (string) $link->id, $links);
            $sorted = $order;
            sort($sorted);
            self::assertCount(4, $order);
            self::assertSame($sorted, $order);
        }
    }

    private function id(Card $card): Uuid
    {
        return $card->id ?? throw new \LogicException('A stored card has an id.');
    }
}
