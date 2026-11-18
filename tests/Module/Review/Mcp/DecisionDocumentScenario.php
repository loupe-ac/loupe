<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Mcp;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\Service\CallerRun;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\OAuth\Scope\ApiScope;
use App\Module\Project\Entity\Project;
use App\Module\Review\Command\CreateDocumentCommand;
use App\Module\Review\Command\CreateDocumentHandler;
use App\Module\Review\Command\ReviseDocumentCommand;
use App\Module\Review\Command\ReviseDocumentHandler;
use App\Module\Review\Entity\Document;
use App\Security\AuthenticatedCredential;
use App\Tests\Module\Bridge\Support\CallsAsWorkerRun;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;

/**
 * KernelTestCase helper: a project with a document that holds two decisions,
 * and a worker session that the MCP call can carry.
 */
trait DecisionDocumentScenario
{
    use CallsAsWorkerRun;

    private const string DECISION_MARKDOWN = "<!-- decision: features -->\n\n- [ ] Import\n- [ ] Export\n- [ ] Search\n\n<!-- /decision -->\n\n<!-- decision: target -->\n\n- ( ) Staging\n- ( ) Production\n\n<!-- /decision -->";

    private EntityManagerInterface $em;

    private function bootScenario(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
    }

    private function newOwner(): User
    {
        $user = new User(fullName: 'Owner', email: 'owner-'.uniqid().'@example.com', password: 'hashed');
        $this->em->persist($user);

        return $user;
    }

    private function decisionDocument(?User $owner = null): Document
    {
        $owner ??= $this->newOwner();
        $project = new Project($owner, 'p-'.uniqid());
        $this->em->persist($project);
        $this->em->flush();

        $handler = self::getContainer()->get(CreateDocumentHandler::class);
        self::assertInstanceOf(CreateDocumentHandler::class, $handler);

        return $handler(new CreateDocumentCommand($project, 'Decisions', self::DECISION_MARKDOWN));
    }

    private function reviseDocument(Document $document, string $description = 'Reworded the options.'): void
    {
        $handler = self::getContainer()->get(ReviseDocumentHandler::class);
        self::assertInstanceOf(ReviseDocumentHandler::class, $handler);
        $handler(new ReviseDocumentCommand(document: $document, markdown: self::DECISION_MARKDOWN."\n\nMore.", description: $description));
    }

    /** Calls as the worker of a run: with its run credential, or with the session header alone. */
    private function workerSession(Document $document, bool $credential = true): void
    {
        $session = Uuid::v4();
        $subject = Uuid::v7();
        $request = new WorkRequest($document->project, WorkSubject::CARD, $subject, 1, 'tech-design-revise', null, 'rule', new \DateTimeImmutable());
        $request->state = WorkRequestState::Claimed;
        $this->em->persist($request);
        $this->em->flush();
        $run = new WorkerRun(
            project: $document->project,
            bridgeId: Uuid::v4(),
            subjectType: WorkSubject::CARD,
            subjectId: $subject,
            cardNumber: 1,
            workKind: 'tech-design-revise',
            state: WorkerRunState::Running,
            runKey: Uuid::v4(),
            sessionId: $session,
            workRequestId: $request->id,
        );
        $this->em->persist($run);
        $this->em->flush();
        if ($credential) {
            $this->callWithCredential(new AuthenticatedCredential(
                id: 'worker-run-'.$run->runKey?->toRfc4122(),
                roles: [ApiScope::Mcp->role(), ApiScope::WorkerRun->role()],
                projectId: $document->project->id,
                workerBridgeId: $run->bridgeId,
                workerRunKey: $run->runKey,
                workerSessionId: $session,
            ));
        }

        $call = Request::create('/mcp', Request::METHOD_POST);
        $call->headers->set(CallerRun::SESSION_HEADER, (string) $session);
        $requests = self::getContainer()->get(RequestStack::class);
        self::assertInstanceOf(RequestStack::class, $requests);
        $requests->push($call);
    }
}
