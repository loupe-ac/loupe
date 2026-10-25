<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Mcp;

use App\Module\Review\Entity\DecisionAnswer;
use App\Module\Review\Entity\Document;
use App\Module\Review\Mcp\DocumentDecisionAnswerTool;
use App\Module\Review\Mcp\DocumentDecisionClearTool;
use App\Module\Review\Repository\DecisionAnswerRepository;
use App\Module\Review\Repository\DecisionSelectionRepository;
use App\Tests\Support\McpRefusalMessages;
use App\Tests\Support\McpTokenScenario;
use App\Tests\Support\RecordingAuditor;
use Mcp\Exception\ToolCallException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DocumentDecisionToolsTest extends KernelTestCase
{
    use DecisionDocumentScenario;
    use McpTokenScenario;

    private DocumentDecisionAnswerTool $answer;
    private DocumentDecisionClearTool $clear;
    private RecordingAuditor $audit;

    protected function setUp(): void
    {
        $this->bootScenario();
        $this->audit = RecordingAuditor::installedIn(self::getContainer());
        $answer = self::getContainer()->get(DocumentDecisionAnswerTool::class);
        self::assertInstanceOf(DocumentDecisionAnswerTool::class, $answer);
        $this->answer = $answer;
        $clear = self::getContainer()->get(DocumentDecisionClearTool::class);
        self::assertInstanceOf(DocumentDecisionClearTool::class, $clear);
        $this->clear = $clear;
    }

    public function test_an_answer_is_stored_for_the_owner_and_marked_as_recorded_through_an_agent(): void
    {
        $document = $this->decisionDocument();
        $this->actAsMcpTokenBoundTo($document->project);

        $result = ($this->answer)((string) $document->id, 1, 'target', [1]);

        self::assertSame(['decisionId' => 'target', 'changed' => true, 'cleared' => false], $result);
        $answers = self::getContainer()->get(DecisionAnswerRepository::class);
        self::assertInstanceOf(DecisionAnswerRepository::class, $answers);
        $row = $answers->findOneByDocumentAndDecisionId($document, 'target');
        self::assertInstanceOf(DecisionAnswer::class, $row);
        self::assertSame($document->owner, $row->answeredBy);
        self::assertTrue($row->answeredThroughAgent);
        self::assertSame(['Production'], $this->pickedLabels($document, 'target'));
        self::assertTrue($this->audit->record('review.decision_saved')->context['throughAgent']);
    }

    public function test_an_answer_with_no_note_keeps_the_note_of_the_owner(): void
    {
        $document = $this->decisionDocument();
        $this->actAsMcpTokenBoundTo($document->project);
        ($this->answer)((string) $document->id, 1, 'target', [0], 'Staging first, please.');

        ($this->answer)((string) $document->id, 1, 'target', [1]);

        self::assertSame('Staging first, please.', $this->note($document, 'target'));
        self::assertSame(['Production'], $this->pickedLabels($document, 'target'));
    }

    public function test_an_empty_note_removes_the_note(): void
    {
        $document = $this->decisionDocument();
        $this->actAsMcpTokenBoundTo($document->project);
        ($this->answer)((string) $document->id, 1, 'target', [0], 'Staging first, please.');

        ($this->answer)((string) $document->id, 1, 'target', [0], '');

        self::assertNull($this->note($document, 'target'));
    }

    public function test_a_note_over_the_limit_is_refused_with_the_limit(): void
    {
        $document = $this->decisionDocument();
        $this->actAsMcpTokenBoundTo($document->project);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('note: A decision note must be at most 2000 characters.');
        ($this->answer)((string) $document->id, 1, 'target', [0], str_repeat('a', 2001));
    }

    public function test_a_clear_drops_the_picks_and_keeps_the_note(): void
    {
        $document = $this->decisionDocument();
        $this->actAsMcpTokenBoundTo($document->project);
        ($this->answer)((string) $document->id, 1, 'target', [0], 'Staging first, please.');

        $result = ($this->clear)((string) $document->id, 1, 'target');

        self::assertSame(['decisionId' => 'target', 'changed' => true, 'cleared' => true], $result);
        self::assertSame([], $this->pickedLabels($document, 'target'));
        self::assertSame('Staging first, please.', $this->note($document, 'target'));
    }

    public function test_a_clear_of_an_unanswered_decision_changes_nothing(): void
    {
        $document = $this->decisionDocument();
        $this->actAsMcpTokenBoundTo($document->project);

        $result = ($this->clear)((string) $document->id, 1, 'target');

        self::assertFalse($result['changed']);
    }

    public function test_a_stale_version_names_the_newer_version_for_an_answer(): void
    {
        $document = $this->decisionDocument();
        $this->reviseDocument($document, 'Reworded the options');
        $this->actAsMcpTokenBoundTo($document->project);

        try {
            ($this->answer)((string) $document->id, 1, 'target', [0]);
            self::fail('The answer was not refused.');
        } catch (ToolCallException $e) {
            self::assertSame('The document has version 2 now: Reworded the options. Show it to the owner, then call again with versionNumber 2.', $e->getMessage());
        }
        self::assertSame([], $this->pickedLabels($document, 'target'));
    }

    public function test_a_stale_version_names_the_newer_version_for_a_clear(): void
    {
        $document = $this->decisionDocument();
        $this->reviseDocument($document, 'Reworded the options');
        $this->actAsMcpTokenBoundTo($document->project);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('The document has version 2 now: Reworded the options. Show it to the owner, then call again with versionNumber 2.');
        ($this->clear)((string) $document->id, 1, 'target');
    }

    public function test_the_newest_version_is_accepted_after_a_revision(): void
    {
        $document = $this->decisionDocument();
        $this->reviseDocument($document);
        $this->actAsMcpTokenBoundTo($document->project);

        $result = ($this->answer)((string) $document->id, 2, 'target', [0]);

        self::assertTrue($result['changed']);
    }

    public function test_an_unknown_decision_is_refused(): void
    {
        $document = $this->decisionDocument();
        $this->actAsMcpTokenBoundTo($document->project);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('decisionId: This version of the document holds no decision with that id.');
        ($this->answer)((string) $document->id, 1, 'missing', [0]);
    }

    public function test_a_bad_option_index_is_refused(): void
    {
        $document = $this->decisionDocument();
        $this->actAsMcpTokenBoundTo($document->project);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('optionIndexes: That decision offers no option with that index.');
        ($this->answer)((string) $document->id, 1, 'target', [9]);
    }

    public function test_two_options_on_a_single_choice_are_refused(): void
    {
        $document = $this->decisionDocument();
        $this->actAsMcpTokenBoundTo($document->project);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('optionIndexes: That decision offers no option with that index.');
        ($this->answer)((string) $document->id, 1, 'target', [0, 1]);
    }

    public function test_a_bridge_worker_is_refused_for_both_tools(): void
    {
        $document = $this->decisionDocument();
        $this->actAsMcpTokenBoundTo($document->project);
        $this->workerSession($document);

        foreach ([
            'answer' => fn () => ($this->answer)((string) $document->id, 1, 'target', [0]),
            'clear' => fn () => ($this->clear)((string) $document->id, 1, 'target'),
        ] as $verb => $call) {
            try {
                $call();
                self::fail('The worker was not refused.');
            } catch (ToolCallException $e) {
                self::assertSame(\sprintf('A bridge worker cannot %s a decision for the owner. Ask the owner with inbox_ask.', $verb), $e->getMessage());
            }
        }
        self::assertSame([], $this->pickedLabels($document, 'target'));
    }

    public function test_a_token_of_another_project_cannot_reach_the_document(): void
    {
        $document = $this->decisionDocument();
        $other = $this->decisionDocument();
        $this->actAsMcpTokenBoundTo($other->project);

        try {
            ($this->answer)((string) $document->id, 1, 'target', [0]);
            self::fail('The answer was not refused.');
        } catch (ToolCallException $e) {
            self::assertSame(\sprintf('Document "%s" not found or not accessible.', $document->id), $e->getMessage());
        }
        self::assertSame([], $this->pickedLabels($document, 'target'));
    }

    public function test_an_unbound_token_is_told_so(): void
    {
        $document = $this->decisionDocument();
        $this->actAsUnboundMcpToken($document->owner);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage(McpRefusalMessages::NO_PROJECT_REACHED);
        ($this->clear)((string) $document->id, 1, 'target');
    }

    /** @return list<string> */
    private function pickedLabels(Document $document, string $decisionId): array
    {
        $selections = self::getContainer()->get(DecisionSelectionRepository::class);
        self::assertInstanceOf(DecisionSelectionRepository::class, $selections);

        return array_values(array_map(
            static fn ($selection): string => $selection->optionLabel,
            $selections->findByDocumentAndDecisionId($document, $decisionId),
        ));
    }

    private function note(Document $document, string $decisionId): ?string
    {
        $answers = self::getContainer()->get(DecisionAnswerRepository::class);
        self::assertInstanceOf(DecisionAnswerRepository::class, $answers);

        return $answers->findOneByDocumentAndDecisionId($document, $decisionId)?->note;
    }
}
