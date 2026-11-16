<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Engine;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardDocument;
use App\Module\Board\Entity\CardPullRequest;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentStatus;
use App\Module\Review\Entity\Tag;
use App\Module\Workflow\Command\EvaluateWorkflowCardCommand;
use App\Module\Workflow\Command\EvaluateWorkflowCardHandler;
use App\Module\Workflow\Engine\Engine;
use App\Tests\Module\Workflow\Action\ActionScenario;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

/** One evaluation of a card reads each shared row once, so a new fact source that repeats a read fails here. */
final class EvaluationStatementCountTest extends KernelTestCase
{
    use ActionScenario;

    public function test_one_evaluation_sends_no_statement_twice_inside_a_fact_pass(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('evaluate-count');
        $this->bindLifecycle($project);
        $parent = $this->card($project, 'in-progress');
        $card = $this->card($project, 'in-progress');
        $card->parent = $parent;
        $document = new Document($project->owner, $project, 'Tech design');
        $document->status = DocumentStatus::Approved;
        $tag = new Tag($project, 'tech-design');
        $this->em()->persist($tag);
        $document->tags->add($tag);
        $this->em()->persist($document);
        $this->em()->persist(new CardDocument($card, $document));
        $this->em()->persist(new CardPullRequest($card, 'https://github.com/acme/app/pull/1'));
        $this->em()->flush();
        $this->em()->clear();

        $holder = self::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(DebugDataHolder::class, $holder);
        $holder->reset();
        (new EvaluateWorkflowCardHandler($this->service(Engine::class), new MockClock('2026-10-02 12:00:00')))(new EvaluateWorkflowCardCommand($card->id ?? throw new \LogicException('A stored card has an id.')));

        $statements = [];
        foreach ($holder->getData() as $queries) {
            foreach ($queries as $query) {
                $statements[] = preg_replace('/\s+/', ' ', $query['sql'].' '.json_encode($query['params'] ?? [])) ?? '';
            }
        }

        // Guard: the evaluation ran and opened at least one fact pass, so an empty duplicate list proves the memo and not a skipped run.
        self::assertContains('SAVEPOINT workflow_fact_provider []', $statements);
        self::assertSame([], $this->repeatedInsideAPass($statements));
    }

    /**
     * @param list<string> $statements
     *
     * @return list<string>
     */
    private function repeatedInsideAPass(array $statements): array
    {
        $repeated = [];
        $seen = null;
        foreach ($statements as $statement) {
            if (str_starts_with($statement, 'SAVEPOINT workflow_fact_provider')) {
                $seen = [];
            } elseif (str_starts_with($statement, 'RELEASE SAVEPOINT workflow_fact_provider')) {
                $seen = null;
            } elseif (null !== $seen) {
                if (isset($seen[$statement])) {
                    $repeated[] = substr($statement, 0, 240);
                }
                $seen[$statement] = true;
            }
        }

        return $repeated;
    }
}
