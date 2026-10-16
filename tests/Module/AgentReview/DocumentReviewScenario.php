<?php

declare(strict_types=1);

namespace App\Tests\Module\AgentReview;

use App\Module\AgentReview\Entity\AgentReviewConclusion;
use App\Module\AgentReview\Entity\AgentReviewFinding;
use App\Module\AgentReview\Entity\AgentReviewSeverity;
use App\Module\AgentReview\Entity\DocumentAgentReview;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardDocument;
use App\Module\Board\Mcp\AgentRunCause;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentStatus;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;

/** Persists and flushes, so a test reads the rows at once. Needs $this->em. */
trait DocumentReviewScenario
{
    use AgentReviewScenario;

    private function linkedDocument(Card $card, DocumentStatus $status = DocumentStatus::Draft, int $versions = 1): Document
    {
        $document = new Document($card->project->owner, $card->project, 'Tech design');
        $document->status = $status;
        for ($version = 1; $version <= $versions; ++$version) {
            $document->addVersion('Text '.$version, '<p>Text '.$version.'</p>');
        }
        $this->em->persist($document);
        $this->em->persist(new CardDocument($card, $document));
        $this->em->flush();

        return $document;
    }

    private function workRun(Project $project, Card $card, Uuid $sessionId, string $workKind, WorkerRunState $state = WorkerRunState::Running, ?WorkRequestState $requestState = null): WorkerRun
    {
        $requestId = Uuid::v7();
        if (null !== $requestState) {
            $request = new WorkRequest($project, WorkSubject::CARD, $card->id ?? throw new \LogicException('A flushed card has an id.'), 1, $workKind, null, 'rule', new \DateTimeImmutable());
            $request->state = $requestState;
            $this->em->persist($request);
            $this->em->flush();
            $requestId = $request->id ?? throw new \LogicException('A flushed request has an id.');
        }
        $run = new WorkerRun(
            project: $project,
            bridgeId: Uuid::v4(),
            subjectType: WorkSubject::CARD,
            subjectId: $card->id ?? throw new \LogicException('A flushed card has an id.'),
            cardNumber: 1,
            workKind: $workKind,
            state: $state,
            sessionId: $sessionId,
            workRequestId: $requestId,
        );
        $this->em->persist($run);
        $this->em->flush();

        return $run;
    }

    private function documentReview(Project $project, Card $card, Document $document, int $findings = 2, int $version = 1): DocumentAgentReview
    {
        $review = new DocumentAgentReview(
            project: $project,
            card: $card,
            document: $document,
            versionNumber: $version,
            summary: 'Findings.',
            conclusion: AgentReviewConclusion::Failure,
            findings: array_map(
                static fn (int $index): AgentReviewFinding => new AgentReviewFinding(null, null, null, AgentReviewSeverity::Important, 'Finding '.$index, 'Body '.$index, \App\Module\AgentReview\Entity\AgentReviewCategory::Spec),
                $findings > 0 ? range(1, $findings) : [],
            ),
        );
        $this->em->persist($review);
        $this->em->flush();

        return $review;
    }

    private function sendSession(?Uuid $session): void
    {
        $request = Request::create('/mcp', Request::METHOD_POST);
        if (null !== $session) {
            $request->headers->set(AgentRunCause::SESSION_HEADER, (string) $session);
        }
        $requests = self::getContainer()->get(RequestStack::class);
        self::assertInstanceOf(RequestStack::class, $requests);
        $requests->push($request);
    }
}
