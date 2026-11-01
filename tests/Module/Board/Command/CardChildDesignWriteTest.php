<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Command;

use App\Exception\DomainErrors;
use App\Module\Board\Command\CardManaged;
use App\Module\Board\Command\ChildDesignRefused;
use App\Module\Board\Command\CreateCardCommand;
use App\Module\Board\Command\CreateCardHandler;
use App\Module\Board\Command\UpdateCardCommand;
use App\Module\Board\Command\UpdateCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardDocument;
use App\Module\Board\Repository\CardDocumentRepository;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentStatus;
use App\Module\Review\Entity\Tag;
use App\Module\Workflow\Contract\Actor;
use App\Tests\Module\Workflow\WorkflowProjects;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CardChildDesignWriteTest extends KernelTestCase
{
    use WorkflowProjects;

    private Project $project;
    private Card $epic;
    private Document $design;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->project = $this->workflowProject('child-design-handler');
        $this->bindLifecycle($this->project);
        $this->epic = $this->card('Epic', 'epic');
        $this->design = $this->documentOf($this->epic, DocumentStatus::Approved);
    }

    public function test_create_inherit_links_the_design_of_the_parent(): void
    {
        $card = $this->create(parentCardId: (string) $this->epic->id, childDesign: 'inherit');

        self::assertSame('backlog', $card->column->slug);
        self::assertSame([(string) $this->design->id], $this->linkedDocumentIds($card));
    }

    public function test_create_own_moves_the_card_to_tech_design(): void
    {
        $card = $this->create(parentCardId: (string) $this->epic->id, childDesign: 'own');

        self::assertSame('tech-design', $card->column->slug);
        self::assertSame([], $this->linkedDocumentIds($card));
    }

    public function test_create_without_a_choice_is_refused_only_when_the_caller_requires_one(): void
    {
        try {
            $this->create(parentCardId: (string) $this->epic->id, requireChoice: true);
            self::fail('The call needs a choice.');
        } catch (ChildDesignRefused $e) {
            self::assertStringContainsString('"inherit"', $e->getMessage());
        }
        self::assertSame(0, $this->titleCount('Child'));

        $card = $this->create(parentCardId: (string) $this->epic->id);
        self::assertSame('backlog', $card->column->slug);
    }

    public function test_create_with_a_choice_and_no_parent_is_refused(): void
    {
        $this->expectException(ChildDesignRefused::class);
        $this->expectExceptionMessage('no parent');

        $this->create(childDesign: 'own');
    }

    public function test_create_own_with_a_column_is_refused(): void
    {
        try {
            $this->create(parentCardId: (string) $this->epic->id, childDesign: 'own', slug: 'next');
            self::fail('The call should be refused.');
        } catch (ChildDesignRefused $e) {
            self::assertStringContainsString('pass no status', $e->getMessage());
        }
        self::assertSame(0, $this->titleCount('Child'));
    }

    public function test_an_unknown_choice_is_refused(): void
    {
        $this->expectException(ChildDesignRefused::class);
        $this->expectExceptionMessage('"inherit" or "own"');

        $this->create(parentCardId: (string) $this->epic->id, childDesign: 'both');
    }

    public function test_update_sets_the_parent_and_inherits_in_one_call(): void
    {
        $card = $this->card('Loose', 'feature');

        $this->update(new UpdateCardCommand($card, Actor::Agent, parentCardId: (string) $this->epic->id, childDesign: 'inherit'));

        self::assertSame([(string) $this->design->id], $this->linkedDocumentIds($card));
    }

    public function test_update_that_sets_a_parent_needs_the_choice_only_when_required(): void
    {
        $card = $this->card('Loose', 'feature');

        try {
            $this->update(new UpdateCardCommand($card, Actor::Agent, parentCardId: (string) $this->epic->id, requireChildDesign: true));
            self::fail('The call needs a choice.');
        } catch (ChildDesignRefused $e) {
            self::assertStringContainsString('"inherit"', $e->getMessage());
        }

        $this->update(new UpdateCardCommand($card, Actor::Agent, parentCardId: (string) $this->epic->id));
        self::assertSame((string) $this->epic->id, (string) $this->reload($card)->parent?->id);
    }

    public function test_update_that_resends_the_parent_it_has_needs_no_choice(): void
    {
        $card = $this->card('Kept child', 'feature', $this->epic);

        $this->update(new UpdateCardCommand($card, Actor::Agent, title: 'Renamed', parentCardId: (string) $this->epic->id, requireChildDesign: true));

        self::assertSame('Renamed', $this->reload($card)->title);
    }

    public function test_update_own_moves_a_child_that_sits_in_next(): void
    {
        $child = $this->card('Child', 'feature', $this->epic, 'next');

        $this->update(new UpdateCardCommand($child, Actor::Agent, childDesign: 'own'));

        self::assertSame('tech-design', $this->reload($child)->column->slug);
    }

    public function test_a_refused_own_leaves_the_other_fields_of_the_call_unwritten(): void
    {
        $child = $this->card('In review', 'feature', $this->epic, 'in-review');

        try {
            $this->update(new UpdateCardCommand($child, Actor::Agent, title: 'Renamed', childDesign: 'own'));
            self::fail('The workflow does not move this card.');
        } catch (CardManaged) {
            // Expected: the workflow does not allow the move.
        }
        self::assertSame('In review', $this->reload($child)->title);
        self::assertSame('in-review', $this->reload($child)->column->slug);
    }

    public function test_clear_parent_removes_the_parent(): void
    {
        $card = $this->card('Child', 'feature', $this->epic);

        $this->update(new UpdateCardCommand($card, Actor::Agent, clearParent: true));

        self::assertNull($this->reload($card)->parent);
    }

    public function test_clear_parent_false_keeps_the_parent(): void
    {
        $card = $this->card('Child', 'feature', $this->epic);

        $this->update(new UpdateCardCommand($card, Actor::Agent, title: 'Renamed', clearParent: false));

        self::assertSame((string) $this->epic->id, (string) $this->reload($card)->parent?->id);
    }

    public function test_clear_parent_with_a_parent_card_id_is_refused_and_writes_nothing(): void
    {
        $card = $this->card('Child', 'feature', $this->epic);
        $other = $this->card('Other epic', 'epic');

        try {
            $this->update(new UpdateCardCommand($card, Actor::Agent, title: 'Renamed', parentCardId: (string) $other->id, clearParent: true));
            self::fail('Expected a refusal.');
        } catch (DomainErrors $e) {
            self::assertContains(UpdateCardHandler::PARENT_CLEAR_CONFLICT, $e->errors);
        }

        $after = $this->reload($card);
        self::assertSame((string) $this->epic->id, (string) $after->parent?->id);
        self::assertSame('Child', $after->title);
    }

    private function create(?string $parentCardId = null, ?string $childDesign = null, bool $requireChoice = false, ?string $slug = null): Card
    {
        $handler = self::getContainer()->get(CreateCardHandler::class);
        self::assertInstanceOf(CreateCardHandler::class, $handler);

        return $handler(new CreateCardCommand(
            project: $this->project,
            title: 'Child',
            body: 'Body',
            type: 'feature',
            column: null === $slug ? null : $this->column($this->project, $slug),
            parentCardId: $parentCardId,
            actor: Actor::Agent,
            childDesign: $childDesign,
            requireChildDesign: $requireChoice,
        ));
    }

    private function update(UpdateCardCommand $command): void
    {
        $handler = self::getContainer()->get(UpdateCardHandler::class);
        self::assertInstanceOf(UpdateCardHandler::class, $handler);

        $handler($command);
    }

    private function card(string $title, string $type, ?Card $parent = null, string $slug = 'next'): Card
    {
        $handler = self::getContainer()->get(CreateCardHandler::class);
        self::assertInstanceOf(CreateCardHandler::class, $handler);

        return $handler(new CreateCardCommand(
            project: $this->project,
            title: $title,
            body: 'Body',
            type: $type,
            column: $this->column($this->project, $slug),
            reporter: Actor::Human,
            parentCardId: null === $parent ? null : (string) $parent->id,
        ));
    }

    private function documentOf(Card $card, DocumentStatus $status): Document
    {
        $document = new Document($this->project->owner, $this->project, 'Design');
        $document->status = $status;
        $tag = new Tag($this->project, 'tech-design');
        $this->em()->persist($tag);
        $document->tags->add($tag);
        $this->em()->persist($document);
        $this->em()->persist(new CardDocument($card, $document));
        $this->em()->flush();

        return $document;
    }

    private function reload(Card $card): Card
    {
        $this->em()->clear();

        return $this->em()->find(Card::class, $card->id) ?? throw new \LogicException('The card exists.');
    }

    /** @return list<string> */
    private function linkedDocumentIds(Card $card): array
    {
        $card = $this->reload($card);

        return array_map(
            static fn (array $row): string => $row['id'],
            self::getContainer()->get(CardDocumentRepository::class)->findStatusesAndTagsForCard($card),
        );
    }

    private function titleCount(string $title): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM board_cards WHERE title = ?', [$title]);
    }
}
