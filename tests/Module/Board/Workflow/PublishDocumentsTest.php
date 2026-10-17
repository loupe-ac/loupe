<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Workflow;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardDocument;
use App\Module\Board\Repository\CardDocumentRepository;
use App\Module\Board\Workflow\PublishDocuments;
use App\Module\Review\Command\PublishDocumentHandler;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentStatus;
use App\Module\Review\Entity\Tag;
use App\Module\Review\Repository\DocumentRepository;
use App\Module\Workflow\Contract\ActionOutcome;
use App\Module\Workflow\Contract\DocumentFacts;
use App\Module\Workflow\Contract\Facts;
use App\Tests\Module\Workflow\Action\ActionScenario;
use App\Tests\Module\Workflow\Fact\FactsMother;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PublishDocumentsTest extends KernelTestCase
{
    use ActionScenario;

    /** @var array<string, Tag> */
    private array $tags = [];

    public function test_it_publishes_each_draft_that_carries_one_of_the_tags(): void
    {
        self::bootKernel();
        $card = $this->card($this->workflowProject('publish-tags'), 'next');
        $design = $this->document($card, 'tech-design', DocumentStatus::Draft);
        $notes = $this->document($card, 'deploy-notes', DocumentStatus::Draft);

        $outcome = $this->publish($card, ['tech-design', 'deploy-notes']);

        self::assertEquals(ActionOutcome::done(), $outcome);
        self::assertSame(DocumentStatus::InReview, $this->statusOf($design));
        self::assertSame(DocumentStatus::InReview, $this->statusOf($notes));
    }

    public function test_it_leaves_a_draft_without_the_tags_and_a_document_that_is_not_a_draft(): void
    {
        self::bootKernel();
        $card = $this->card($this->workflowProject('publish-skip'), 'next');
        $other = $this->document($card, 'notes', DocumentStatus::Draft);
        $approved = $this->document($card, 'tech-design', DocumentStatus::Approved);
        $inReview = $this->document($card, 'tech-design', DocumentStatus::InReview);

        $outcome = $this->publish($card, ['tech-design']);

        self::assertEquals(ActionOutcome::done(), $outcome);
        self::assertSame(DocumentStatus::Draft, $this->statusOf($other));
        self::assertSame(DocumentStatus::Approved, $this->statusOf($approved));
        self::assertSame(DocumentStatus::InReview, $this->statusOf($inReview));
    }

    public function test_a_draft_the_facts_do_not_list_is_not_published(): void
    {
        self::bootKernel();
        $card = $this->card($this->workflowProject('publish-facts'), 'next');
        $draft = $this->document($card, 'tech-design', DocumentStatus::Draft);

        $outcome = $this->publishWith($card, ['tech-design'], FactsMother::facts(card: FactsMother::card(documents: [])));

        self::assertEquals(ActionOutcome::done(), $outcome);
        self::assertSame(DocumentStatus::Draft, $this->statusOf($draft));
    }

    public function test_an_archived_draft_is_a_refusal(): void
    {
        self::bootKernel();
        $card = $this->card($this->workflowProject('publish-archived'), 'next');
        $draft = $this->document($card, 'tech-design', DocumentStatus::Draft);
        $draft->archivedAt = new \DateTimeImmutable();
        $this->em()->flush();

        $outcome = $this->publishWith($card, ['tech-design'], $this->facts($card, withArchived: $draft));

        self::assertEquals(ActionOutcome::refused('document-publish-refused'), $outcome);
        self::assertSame(DocumentStatus::Draft, $this->statusOf($draft));
    }

    /** @param list<string> $tags */
    private function publish(Card $card, array $tags): ActionOutcome
    {
        return $this->publishWith($card, $tags, $this->facts($card));
    }

    /** @param list<string> $tags */
    private function publishWith(Card $card, array $tags, Facts $facts): ActionOutcome
    {
        $rule = $this->rule('publish-documents', [], lists: ['tags' => $tags]);

        return $this->runAction(
            new PublishDocuments($this->service(DocumentRepository::class), $this->service(PublishDocumentHandler::class)),
            $rule,
            $card->snapshot(),
            $facts,
            $this->state($card, $rule->id),
        );
    }

    private function facts(Card $card, ?Document $withArchived = null): Facts
    {
        $rows = $this->service(CardDocumentRepository::class)->findStatusesAndTagsForCard($card);
        $documents = array_map(static fn (array $row): DocumentFacts => new DocumentFacts($row['tags'], $row['status'], $row['id']), $rows);
        if (null !== $withArchived && [] === array_filter($documents, static fn (DocumentFacts $document): bool => $document->id === (string) $withArchived->id)) {
            $documents[] = new DocumentFacts(['tech-design'], 'draft', (string) $withArchived->id);
        }

        return FactsMother::facts(card: FactsMother::card(documents: $documents));
    }

    private function statusOf(Document $document): DocumentStatus
    {
        $this->em()->clear();

        return ($this->em()->find(Document::class, $document->id) ?? throw new \LogicException('The document exists.'))->status;
    }

    private function document(Card $card, string $tagName, DocumentStatus $status): Document
    {
        $document = new Document($card->project->owner, $card->project, 'Design');
        $document->status = $status;
        $tag = $this->tags[$card->project->id.'/'.$tagName] ??= new Tag($card->project, $tagName);
        $this->em()->persist($tag);
        $document->tags->add($tag);
        $this->em()->persist($document);
        $this->em()->persist(new CardDocument($card, $document));
        $this->em()->flush();

        return $document;
    }
}
