<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\EventListener;

use App\Module\Account\Entity\User;
use App\Module\Account\Repository\UserRepository;
use App\Module\Board\Command\CreateCardCommand;
use App\Module\Board\Command\CreateCardHandler;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardEvent;
use App\Module\Board\Entity\CardEventKind;
use App\Module\Board\Repository\CardEventRepository;
use App\Module\Project\Entity\Project;
use App\Module\Review\Command\CreateDocumentCommand;
use App\Module\Review\Command\CreateDocumentHandler;
use App\Module\Review\Command\SubmitReviewCommand;
use App\Module\Review\Command\SubmitReviewHandler;
use App\Module\Review\Command\UndoVerdictCommand;
use App\Module\Review\Command\UndoVerdictHandler;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\Review;
use App\Module\Workflow\Contract\Actor;
use App\Security\ApiKeyActor;
use App\Tests\Module\Board\BoardColumnFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class WriteCardEventOnDocumentVerdictTest extends KernelTestCase
{
    use BoardColumnFixtures;

    private EntityManagerInterface $em;
    private User $owner;
    private User $workflow;
    private Project $project;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $users = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);
        $this->workflow = $users->workflow();

        $this->owner = new User(fullName: 'Riley Chen', email: 'verdict-history-'.uniqid().'@example.com', password: 'hashed');
        $this->em->persist($this->owner);
        $this->project = new Project($this->owner, 'verdict-history-'.uniqid());
        $this->em->persist($this->project);
        $this->seedColumns($this->project);
        $this->em->persist(new BoardColumn(project: $this->project, label: 'tech-design', slug: 'tech-design', position: 4));
        $this->em->flush();
    }

    public function test_a_verdict_of_a_rule_writes_one_row_for_each_linked_card(): void
    {
        $document = $this->document();
        $first = $this->card($document);
        $second = $this->card($document);

        $this->submit($document, 'approved', 'tech-design-auto-approve');

        foreach ([$first, $second] as $card) {
            $row = $this->onlyRow($card, CardEventKind::DocumentVerdict);
            self::assertSame(Actor::System, $row->actorKind);
            self::assertNull($row->actorUser);
            self::assertEquals(['rule' => 'tech-design-auto-approve', 'document' => 'Tech design', 'documentId' => (string) $document->id, 'verdict' => 'approved'], $row->detail);
        }
    }

    public function test_a_verdict_of_a_person_writes_no_row(): void
    {
        $document = $this->document();
        $card = $this->card($document);

        $this->submit($document, 'changes-requested', null, $this->owner, 'Rework it.');

        self::assertSame([], $this->rows($card, CardEventKind::DocumentVerdict));
    }

    public function test_a_withdrawal_writes_a_row_that_names_the_person_and_the_verdict(): void
    {
        $document = $this->document();
        $card = $this->card($document);
        $approval = $this->submit($document, 'approved', 'tech-design-auto-approve');

        $undo = self::getContainer()->get(UndoVerdictHandler::class);
        self::assertInstanceOf(UndoVerdictHandler::class, $undo);
        $undo(new UndoVerdictCommand($document, $this->owner, (string) $approval->id));

        $rows = $this->rows($card, CardEventKind::DocumentVerdict);
        self::assertCount(2, $rows);
        $withdrawal = array_find($rows, static fn (CardEvent $row): bool => 'withdrawn' === $row->detail['verdict']);
        self::assertInstanceOf(CardEvent::class, $withdrawal);
        self::assertSame(Actor::Human, $withdrawal->actorKind);
        self::assertSame((string) $this->owner->id, (string) $withdrawal->actorUser?->id);
        self::assertEquals(['rule' => null, 'document' => 'Tech design', 'documentId' => (string) $document->id, 'verdict' => 'withdrawn', 'withdrawn' => 'approved'], $withdrawal->detail);
    }

    public function test_a_withdrawal_through_an_api_key_writes_a_row_that_names_the_key_and_its_owner(): void
    {
        $document = $this->document();
        $card = $this->card($document);
        $approval = $this->submit($document, 'approved', 'tech-design-auto-approve');
        $key = new ApiKeyActor(Uuid::v7(), 'CI');

        $undo = self::getContainer()->get(UndoVerdictHandler::class);
        self::assertInstanceOf(UndoVerdictHandler::class, $undo);
        $undo(new UndoVerdictCommand($document, $this->owner, (string) $approval->id, apiKey: $key));

        $withdrawal = array_find($this->rows($card, CardEventKind::DocumentVerdict), static fn (CardEvent $row): bool => 'withdrawn' === $row->detail['verdict']);
        self::assertInstanceOf(CardEvent::class, $withdrawal);
        self::assertSame(Actor::ApiKey, $withdrawal->actorKind);
        self::assertSame((string) $this->owner->id, (string) $withdrawal->actorUser?->id);
        self::assertEquals($key->keyId, $withdrawal->apiKeyId);
        self::assertSame('CI', $withdrawal->apiKeyName);
    }

    private function submit(Document $document, string $verdict, ?string $rule, ?User $reviewer = null, ?string $note = null): Review
    {
        $handler = self::getContainer()->get(SubmitReviewHandler::class);
        self::assertInstanceOf(SubmitReviewHandler::class, $handler);

        return $handler(new SubmitReviewCommand($reviewer ?? $this->workflow, $document, $verdict, 1, $note, null, $rule));
    }

    private function document(): Document
    {
        $handler = self::getContainer()->get(CreateDocumentHandler::class);
        self::assertInstanceOf(CreateDocumentHandler::class, $handler);

        return $handler(new CreateDocumentCommand($this->project, 'Tech design', '# Tech design'));
    }

    private function card(Document $document): Card
    {
        $handler = self::getContainer()->get(CreateCardHandler::class);
        self::assertInstanceOf(CreateCardHandler::class, $handler);

        return $handler(new CreateCardCommand(
            project: $this->project,
            title: 'A card',
            body: 'Body',
            type: 'feature',
            column: $this->column($this->project, 'tech-design'),
            documentIds: [(string) $document->id],
        ));
    }

    /** @return list<CardEvent> */
    private function rows(Card $card, CardEventKind $kind): array
    {
        $events = self::getContainer()->get(CardEventRepository::class);
        self::assertInstanceOf(CardEventRepository::class, $events);
        $this->em->clear();

        return $events->findBy(['card' => $card->id, 'kind' => $kind]);
    }

    private function onlyRow(Card $card, CardEventKind $kind): CardEvent
    {
        $rows = $this->rows($card, $kind);
        self::assertCount(1, $rows);

        return $rows[0];
    }
}
