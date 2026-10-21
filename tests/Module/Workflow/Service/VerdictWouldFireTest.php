<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Service;

use App\Module\Board\Command\CreateCardCommand;
use App\Module\Board\Command\CreateCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardDocument;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentStatus;
use App\Module\Review\Entity\Tag;
use App\Module\Workflow\Condition\CardDocumentApprovalMoves;
use App\Module\Workflow\Contract\Actor;
use App\Module\Workflow\Repository\WorkflowBindingRepository;
use App\Module\Workflow\Service\FactsBuilder;
use App\Module\Workflow\Service\VerdictWouldFire;
use App\Module\Workflow\Template\ProjectTemplateCopy;
use App\Module\Workflow\Template\TemplateSource;
use App\Tests\Module\Workflow\Fact\ProvidedFacts;
use App\Tests\Module\Workflow\Fact\ProvidedFactsProvider;
use App\Tests\Module\Workflow\Fact\ProvidedFactsReady;
use App\Tests\Module\Workflow\WorkflowProjects;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class VerdictWouldFireTest extends KernelTestCase
{
    use WorkflowProjects;

    private Project $project;

    private Card $card;

    private ?Tag $tag = null;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->project = $this->workflowProject('verdict-would-fire');
        $this->bindLifecycle($this->project);
        $this->card = $this->service(CreateCardHandler::class)(new CreateCardCommand(
            project: $this->project,
            title: 'Card',
            body: 'Body',
            type: 'feature',
            column: $this->column($this->project, 'tech-design'),
            reporter: Actor::Human,
        ));
    }

    public function test_an_approval_that_turns_a_rule_true_counts(): void
    {
        $this->define(['card.document.approved' => ['tag' => 'design']]);
        $document = $this->document(DocumentStatus::InReview);

        self::assertSame([$document], $this->forCard([$document]));
    }

    public function test_a_change_request_that_turns_a_rule_true_counts(): void
    {
        $this->define(['card.document.changes_requested' => ['tag' => 'design']]);
        $document = $this->document(DocumentStatus::InReview);

        self::assertSame([$document], $this->forCard([$document]));
    }

    public function test_a_rule_that_is_already_true_does_not_count(): void
    {
        $this->define(['card.document.linked' => ['tag' => 'design']]);
        $document = $this->document(DocumentStatus::InReview);

        self::assertSame([], $this->forCard([$document]));
    }

    public function test_a_rule_whose_other_condition_stays_false_does_not_count(): void
    {
        $this->define(['all' => [['card.document.approved' => ['tag' => 'design']], ['card.parent.exists' => []]]]);
        $document = $this->document(DocumentStatus::InReview);

        self::assertSame([], $this->forCard([$document]));
    }

    public function test_a_rule_on_unreadable_facts_does_not_count(): void
    {
        $this->define(['all' => [['card.document.approved' => ['tag' => 'design']], [ProvidedFactsReady::KEY => []]]]);
        $document = $this->document(DocumentStatus::InReview);
        $provider = $this->service(ProvidedFactsProvider::class);
        $provider->facts = new ProvidedFacts(ready: true);
        self::assertSame([$document], $this->forCard([$document]), 'The rule counts while its facts read.');

        $provider->failure = new \RuntimeException('The source is down.');

        self::assertSame([], $this->forCard([$document]));
    }

    public function test_the_approval_moves_condition_holds_while_a_verdict_on_a_tagged_document_fires_a_rule(): void
    {
        $this->define(['card.document.approved' => ['tag' => 'design']]);
        $this->document(DocumentStatus::InReview);

        self::assertTrue($this->approvalMoves());
    }

    public function test_the_approval_moves_condition_fails_for_a_document_not_in_review(): void
    {
        $this->define(['card.document.approved' => ['tag' => 'design']]);
        $this->document(DocumentStatus::Approved);

        self::assertFalse($this->approvalMoves());
    }

    public function test_the_approval_moves_condition_fails_when_only_a_change_request_fires_a_rule(): void
    {
        $this->define(['card.document.changes_requested' => ['tag' => 'design']]);
        $document = $this->document(DocumentStatus::InReview);

        self::assertSame([$document], $this->forCard([$document]));
        self::assertFalse($this->approvalMoves());
    }

    public function test_a_rule_that_reads_the_condition_is_left_out_of_the_question(): void
    {
        $this->define(['card.document.approval_moves' => []]);
        $this->document(DocumentStatus::InReview);

        self::assertFalse($this->approvalMoves());
    }

    private function approvalMoves(): bool
    {
        $facts = $this->service(FactsBuilder::class)->build($this->card->snapshot(), new \DateTimeImmutable());

        return new CardDocumentApprovalMoves($this->service(VerdictWouldFire::class), $this->service(TemplateSource::class))->evaluate($facts, []);
    }

    /** @param array<string, mixed> $when */
    private function define(array $when): void
    {
        $binding = $this->service(WorkflowBindingRepository::class)->findOneByProjectId($this->project->id ?? throw new \LogicException('A flushed project has an id.'));
        self::assertNotNull($binding);
        $binding->definition = [
            'key' => 'test',
            'version' => 1,
            'defaultType' => 'feature',
            'types' => [
                ['key' => 'feature', 'label' => 'board.card.type.feature', 'tone' => 'lime'],
                ['key' => 'bug', 'label' => 'board.card.type.bug', 'tone' => 'amber'],
                ['key' => 'security', 'label' => 'board.card.type.security', 'tone' => 'red'],
                ['key' => 'tooling', 'label' => 'board.card.type.tooling', 'tone' => 'neutral'],
                ['key' => 'docs', 'label' => 'board.card.type.docs', 'tone' => 'green'],
                ['key' => 'idea', 'label' => 'board.card.type.idea', 'tone' => 'purple'],
                ['key' => 'epic', 'label' => 'board.card.type.epic', 'tone' => 'blue', 'capabilities' => ['children', 'lane']],
            ],
            'slots' => [['key' => 'tech-design', 'label' => 'workflow.slot.tech_design'], ['key' => 'implementation', 'label' => 'workflow.slot.implementation']],
            'manualMoves' => [],
            'backoffMinutes' => [10],
            'workTimeoutMinutes' => 120,
            'rules' => [['id' => 'verdict', 'slot' => 'tech-design', 'when' => $when, 'then' => ['move' => ['to' => 'implementation']]]],
        ];
        $this->service(ProjectTemplateCopy::class)->reset();
        $this->em()->flush();
    }

    private function document(DocumentStatus $status): string
    {
        $document = new Document($this->project->owner, $this->project, 'Design');
        $document->status = $status;
        if (null === $this->tag) {
            $this->tag = new Tag($this->project, 'design');
            $this->em()->persist($this->tag);
        }
        $document->tags->add($this->tag);
        $this->em()->persist($document);
        $this->em()->persist(new CardDocument($this->card, $document));
        $this->em()->flush();

        return ($document->id ?? throw new \LogicException('A flushed document has an id.'))->toRfc4122();
    }

    /**
     * @param list<string> $documentIds
     *
     * @return list<string>
     */
    private function forCard(array $documentIds): array
    {
        $template = $this->service(TemplateSource::class)->forProject($this->project->id ?? throw new \LogicException('A flushed project has an id.'));

        return $this->service(VerdictWouldFire::class)->forCard($this->card->snapshot(), $template, $documentIds);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function service(string $class): object
    {
        $service = self::getContainer()->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }
}
