<?php

declare(strict_types=1);

namespace App\Tests\Module\AgentReview;

use App\Module\AgentReview\Entity\AgentReview;
use App\Module\AgentReview\Entity\AgentReviewConclusion;
use App\Module\AgentReview\Entity\AgentReviewFinding;
use App\Module\AgentReview\Entity\AgentReviewSeverity;
use App\Module\AgentReview\Service\AgentReviewCheckPublisher;
use App\Module\Board\Entity\Card;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Service\CallerRun;
use App\Module\Bridge\ValueObject\WorkerRunKey;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\OAuth\Scope\ApiScope;
use App\Module\Project\Entity\Project;
use App\Security\AuthenticatedCredential;
use App\Tests\Module\Board\Mcp\BoardToolScenario;
use App\Tests\Module\Bridge\Support\CallsAsWorkerRun;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;

/** Persists and does not flush, except makeProject(), so a test decides when the rows commit. */
trait AgentReviewScenario
{
    use BoardToolScenario;
    use CallsAsWorkerRun;

    /** Calls with the run credential of $run, bound to its project. The header names $headerSession when given. */
    private function callAsRun(WorkerRun $run, ?Uuid $headerSession = null): WorkerRunKey
    {
        $bridgeId = $run->bridgeId ?? throw new \LogicException('A test run has a bridge.');
        $runKey = $run->runKey ?? throw new \LogicException('A test run has a run key.');
        $this->callWithCredential(new AuthenticatedCredential(
            id: 'worker-run-'.$runKey->toRfc4122(),
            roles: [ApiScope::Mcp->role(), ApiScope::WorkerRun->role()],
            projectId: $run->project->id,
            workerBridgeId: $bridgeId,
            workerRunKey: $runKey,
            workerSessionId: $run->sessionId,
        ));
        $this->sendHeader($headerSession ?? $run->sessionId);

        return new WorkerRunKey($bridgeId, $runKey);
    }

    /** Pushes a request that carries the session header only. A header names no run. */
    private function sendHeader(?Uuid $session): void
    {
        $request = Request::create('/mcp', Request::METHOD_POST);
        if (null !== $session) {
            $request->headers->set(CallerRun::SESSION_HEADER, (string) $session);
        }
        $requests = self::getContainer()->get(RequestStack::class);
        self::assertInstanceOf(RequestStack::class, $requests);
        $requests->push($request);
    }

    private function card(Project $project, int $number = 1): Card
    {
        $card = new Card($project, $this->column($project, 'backlog'), 'Ship it', '', $number);
        $this->em->persist($card);

        return $card;
    }

    private function pullRequest(Project $project, int $number = 7): ForgePullRequest
    {
        $pullRequest = new ForgePullRequest($project, 'github', 'acme/widgets', $number);
        $pullRequest->headSha = str_repeat('a', 40);
        $this->em->persist($pullRequest);

        return $pullRequest;
    }

    private function review(Card $card, ForgePullRequest $pullRequest, ?\DateTimeImmutable $postedAt = null): AgentReview
    {
        $review = new AgentReview(
            project: $card->project,
            card: $card,
            pullRequest: $pullRequest,
            headSha: $pullRequest->headSha ?? str_repeat('b', 40),
            summary: 'One important finding.',
            conclusion: AgentReviewConclusion::Failure,
            findings: [new AgentReviewFinding('src/Foo.php', 3, 5, AgentReviewSeverity::Important, 'Null read', 'The value can be null here.')],
        );
        $review->postedAt = $postedAt;
        $review->checkName = null === $postedAt ? null : AgentReviewCheckPublisher::NAME;
        $this->em->persist($review);

        return $review;
    }
}
