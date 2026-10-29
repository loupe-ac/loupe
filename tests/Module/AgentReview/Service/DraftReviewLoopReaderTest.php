<?php

declare(strict_types=1);

namespace App\Tests\Module\AgentReview\Service;

use App\Module\AgentReview\Entity\AgentReviewCategory;
use App\Module\AgentReview\Entity\AgentReviewConclusion;
use App\Module\AgentReview\Entity\AgentReviewFinding;
use App\Module\AgentReview\Entity\AgentReviewSeverity;
use App\Module\AgentReview\Entity\DocumentAgentReview;
use App\Module\AgentReview\Repository\DocumentAgentReviewRepository;
use App\Module\AgentReview\Service\DraftReviewLoopReader;
use App\Module\Board\Command\PauseCardCommand;
use App\Module\Board\Command\PauseCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardDocument;
use App\Module\Board\Repository\CardDocumentRepository;
use App\Module\Board\Repository\CardPauseRepository;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentStatus;
use App\Module\Review\Entity\Tag;
use App\Module\Workflow\Contract\DesignReviewRoundLimit;
use App\Module\Workflow\Contract\DesignReviewTags;
use App\Module\Workflow\Contract\PauseKind;
use App\Module\Workflow\Contract\PauseReasonText;
use App\Module\Workflow\Entity\WorkflowBinding;
use App\Module\Workflow\Repository\WorkflowBindingRepository;
use App\Tests\Module\AgentReview\DocumentReviewScenario;
use App\Tests\Module\Workflow\WorkflowProjects;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DraftReviewLoopReaderTest extends KernelTestCase
{
    use DocumentReviewScenario;
    use WorkflowProjects;

    private EntityManagerInterface $em;
    private Project $project;

    /** @var array<string, Tag> */
    private array $tags = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = $this->em();
        $this->project = $this->workflowProject('draft-review-loop');
        $this->bindLifecycle($this->project);
    }

    public function test_a_draft_with_no_failed_review_is_in_round_one(): void
    {
        $card = $this->cardIn('tech-design', 4);
        $document = $this->draft($card, ['tech-design']);

        $loop = $this->loops()->of($document);

        self::assertNotNull($loop);
        self::assertSame([1, 6, null, (string) $card->id, 4], [$loop->round, $loop->limit, $loop->pauseReason, $loop->cardId, $loop->cardNumber]);
        self::assertFalse($loop->paused());
    }

    public function test_the_round_is_the_failed_count_plus_one(): void
    {
        $card = $this->cardIn('tech-design');
        $document = $this->draft($card, ['tech-design']);
        $this->documentReview($this->project, $card, $document, 1, 1);
        $this->documentReview($this->project, $card, $document, 1, 2);

        self::assertSame(3, $this->loops()->of($document)?->round);
    }

    public function test_the_round_stops_at_the_limit(): void
    {
        $card = $this->cardIn('tech-design');
        $document = $this->draft($card, ['tech-design']);
        for ($version = 1; $version <= 7; ++$version) {
            $this->documentReview($this->project, $card, $document, 1, $version);
        }

        $loop = $this->loops()->of($document);

        self::assertSame([6, 6], [$loop?->round, $loop?->limit]);
    }

    public function test_a_pass_with_a_finding_for_the_owner_counts_as_a_round(): void
    {
        $card = $this->cardIn('tech-design');
        $document = $this->draft($card, ['tech-design']);
        $this->documentReview($this->project, $card, $document, 1, 1);
        $this->pass($card, $document, 2, needsPerson: true);

        self::assertSame(3, $this->loops()->of($document)?->round);
    }

    public function test_a_pass_with_no_finding_for_the_owner_does_not_count(): void
    {
        $card = $this->cardIn('tech-design');
        $document = $this->draft($card, ['tech-design']);
        $this->documentReview($this->project, $card, $document, 1, 1);
        $this->pass($card, $document, 2, needsPerson: false);

        self::assertSame(2, $this->loops()->of($document)?->round);
    }

    public function test_passes_for_the_owner_and_failed_reviews_stop_at_the_limit(): void
    {
        $card = $this->cardIn('tech-design');
        $document = $this->draft($card, ['tech-design']);
        for ($version = 1; $version <= 3; ++$version) {
            $this->documentReview($this->project, $card, $document, 1, $version);
        }
        for ($version = 4; $version <= 6; ++$version) {
            $this->pass($card, $document, $version, needsPerson: true);
        }

        $loop = $this->loops()->of($document);

        self::assertSame([6, 6], [$loop?->round, $loop?->limit]);
    }

    public function test_failed_reviews_of_another_card_do_not_count(): void
    {
        $card = $this->cardIn('tech-design', 1);
        $other = $this->cardIn('tech-design', 2);
        $document = $this->draft($card, ['tech-design']);
        $this->em->persist(new CardDocument($other, $document));
        $this->em->flush();
        $this->documentReview($this->project, $other, $document, 1, 1);
        $this->documentReview($this->project, $other, $document, 1, 2);

        $loop = $this->loops()->of($document);

        self::assertSame([1, 1], [$loop?->cardNumber, $loop?->round]);
    }

    public function test_a_paused_card_gives_the_text_of_its_pause_reason(): void
    {
        $card = $this->cardIn('tech-design');
        $document = $this->draft($card, ['tech-design']);
        $this->pause($card, 'repair-failed');

        $loop = $this->loops()->of($document);

        self::assertNotNull($loop);
        self::assertSame('The repair worker could not fix the failure.', $loop->pauseReason);
        self::assertTrue($loop->paused());
    }

    public function test_a_pause_reason_with_no_text_shows_as_it_is(): void
    {
        $card = $this->cardIn('tech-design');
        $document = $this->draft($card, ['tech-design']);
        $this->pause($card, 'forge-said-no');

        self::assertSame('forge-said-no', $this->loops()->of($document)?->pauseReason);
    }

    public function test_a_card_in_a_column_that_reviews_another_tag_gives_no_loop(): void
    {
        $card = $this->cardIn('product-design');
        $document = $this->draft($card, ['tech-design']);

        self::assertNull($this->loops()->of($document));
    }

    public function test_a_card_in_a_column_with_no_design_review_gives_no_loop(): void
    {
        $card = $this->cardIn('next');
        $document = $this->draft($card, ['tech-design']);

        self::assertNull($this->loops()->of($document));
    }

    public function test_a_card_in_a_terminal_column_gives_no_loop(): void
    {
        $card = $this->cardIn('done');
        $document = $this->draft($card, ['tech-design']);

        self::assertNull($this->loops()->of($document));
    }

    public function test_a_document_with_no_design_review_tag_gives_no_loop(): void
    {
        $card = $this->cardIn('tech-design');

        self::assertNull($this->loops()->of($this->draft($card, ['deploy-notes'])));
        self::assertNull($this->loops()->of($this->draft($card, [])));
    }

    public function test_a_document_that_is_not_a_draft_gives_no_loop(): void
    {
        $card = $this->cardIn('tech-design');

        foreach ([DocumentStatus::InReview, DocumentStatus::Approved] as $status) {
            self::assertNull($this->loops()->of($this->draft($card, ['tech-design'], $status)));
        }
    }

    public function test_a_template_with_no_round_limit_gives_no_loop(): void
    {
        $binding = $this->bindingOf($this->project);
        $definition = $binding->definition;
        $definition['rules'] = array_values(array_filter(
            $definition['rules'],
            static fn (array $rule): bool => !isset($rule['then']['publish-documents']),
        ));
        $binding->definition = $definition;
        $this->em->flush();
        $card = $this->cardIn('tech-design');
        $document = $this->draft($card, ['tech-design']);

        self::assertNull($this->loops()->of($document));
    }

    public function test_the_card_that_is_not_paused_comes_before_a_paused_one(): void
    {
        $paused = $this->cardIn('tech-design', 1);
        $running = $this->cardIn('tech-design', 5);
        $document = $this->draft($paused, ['tech-design']);
        $this->em->persist(new CardDocument($running, $document));
        $this->em->flush();
        $this->pause($paused, 'repair-failed');

        self::assertSame(5, $this->loops()->of($document)?->cardNumber);
    }

    public function test_the_lowest_card_number_wins_inside_a_group(): void
    {
        $high = $this->cardIn('tech-design', 9);
        $low = $this->cardIn('tech-design', 3);
        $document = $this->draft($high, ['tech-design']);
        $this->em->persist(new CardDocument($low, $document));
        $this->em->flush();

        self::assertSame(3, $this->loops()->of($document)?->cardNumber);

        $this->pause($high, 'repair-failed');
        $this->pause($low, 'repair-failed');

        self::assertSame(3, $this->loops()->of($document)?->cardNumber);
    }

    public function test_a_card_outside_a_reviewing_column_does_not_hide_a_reviewing_one(): void
    {
        $outside = $this->cardIn('next', 1);
        $reviewing = $this->cardIn('tech-design', 2);
        $document = $this->draft($outside, ['tech-design']);
        $this->em->persist(new CardDocument($reviewing, $document));
        $this->em->flush();

        self::assertSame(2, $this->loops()->of($document)?->cardNumber);
    }

    private function loops(): DraftReviewLoopReader
    {
        return new DraftReviewLoopReader(
            $this->service(DesignReviewTags::class),
            $this->service(DesignReviewRoundLimit::class),
            $this->service(CardDocumentRepository::class),
            $this->service(DocumentAgentReviewRepository::class),
            $this->service(CardPauseRepository::class),
            $this->service(PauseReasonText::class),
        );
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

    private function cardIn(string $slug, int $number = 1): Card
    {
        $card = new Card($this->project, $this->column($this->project, $slug), 'Ship it', '', $number);
        $this->em->persist($card);
        $this->em->flush();

        return $card;
    }

    /** @param list<string> $tags */
    private function draft(Card $card, array $tags, DocumentStatus $status = DocumentStatus::Draft): Document
    {
        $document = $this->linkedDocument($card, $status);
        foreach ($tags as $name) {
            $tag = $this->tags[$name] ??= new Tag($this->project, $name);
            $this->em->persist($tag);
            $document->tags->add($tag);
        }
        $this->em->flush();

        return $document;
    }

    private function pass(Card $card, Document $document, int $version, bool $needsPerson): void
    {
        $this->em->persist(new DocumentAgentReview(
            project: $this->project,
            card: $card,
            document: $document,
            versionNumber: $version,
            summary: 'Passed.',
            conclusion: AgentReviewConclusion::Success,
            findings: [new AgentReviewFinding(null, null, null, AgentReviewSeverity::Nit, 'Pick one', 'Body', AgentReviewCategory::Spec, $needsPerson)],
        ));
        $this->em->flush();
    }

    private function pause(Card $card, string $reason): void
    {
        $handler = self::getContainer()->get(PauseCardHandler::class);
        self::assertInstanceOf(PauseCardHandler::class, $handler);
        $handler(new PauseCardCommand($card, $reason, 'tech-design-review', PauseKind::Retries));
    }

    private function bindingOf(Project $project): WorkflowBinding
    {
        $binding = $this->service(WorkflowBindingRepository::class)->findOneBy(['project' => $project]);
        self::assertNotNull($binding);

        return $binding;
    }
}
