<?php

declare(strict_types=1);

namespace App\Module\Inbox\Command;

use App\Exception\DomainErrors;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Workflow\WorkRequestContexts;
use App\Module\Bridge\Command\OpenWorkRequestCommand;
use App\Module\Bridge\Command\OpenWorkRequestHandler;
use App\Module\Bridge\Command\RequestBridgeCommandCommand;
use App\Module\Bridge\Command\RequestBridgeCommandHandler;
use App\Module\Bridge\Command\WorkRequestAlreadyLive;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\ValueObject\BridgeCommandCause;
use App\Module\Bridge\ValueObject\BridgeCommandKind;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Inbox\Entity\InboxAction;
use App\Module\Inbox\Entity\InboxActionKind;
use App\Module\Inbox\Entity\InboxCardWait;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\Service\ItemActions;
use App\Module\Workflow\Command\ReleaseWorkflowPauseCommand;
use App\Module\Workflow\Command\ReleaseWorkflowPauseHandler;
use App\Module\Workflow\Contract\Actor;
use App\Module\Workflow\Contract\CardDirectory;
use App\Module\Workflow\Contract\PersonWork;
use Doctrine\ORM\EntityManagerInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/**
 * Runs one action that the owner chose on an inbox item. A resume asks the bridge, a retry releases
 * the pause, send an agent opens a fresh work request, and a dismiss closes the item. The note stays in Loupe: no command carries its text.
 * A dismiss records no row, because the closed item holds the note as its close note.
 */
final readonly class RunInboxActionHandler
{
    public const string NOTE_TOO_LONG = 'inbox.action.error.note_too_long';
    public const string NOT_AVAILABLE = 'inbox.action.error.not_available';

    public function __construct(
        private ItemActions $itemActions,
        private RequestBridgeCommandHandler $requestBridgeCommand,
        private OpenWorkRequestHandler $openWorkRequest,
        private WorkRequestContexts $contexts,
        private CardRepository $cards,
        private ReleaseWorkflowPauseHandler $releasePause,
        private DeclineInboxItemHandler $declineItem,
        private WorkerRunRepository $workerRuns,
        private CardDirectory $cardDirectory,
        private EntityManagerInterface $em,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(RunInboxActionCommand $command): ?InboxAction
    {
        $item = $command->item;
        $kind = $command->kind;
        $note = trim($command->note);
        if (mb_strlen($note) > InboxAction::MAX_NOTE_LENGTH) {
            throw new DomainErrors(['note' => self::NOTE_TOO_LONG]);
        }
        if (InboxItemState::Open !== $item->state || !self::runsHere($kind) || !array_any($this->itemActions->forItem($item), static fn ($action): bool => $action->kind === $kind)) {
            throw new DomainErrors(['action' => self::NOT_AVAILABLE]);
        }

        try {
            $action = match ($kind) {
                InboxActionKind::ResumeRun => $this->resume($command, $note),
                InboxActionKind::RetryCard => $this->retry($command, $note),
                InboxActionKind::SendAgent => $this->sendAgent($command, $note),
                default => $this->dismiss($command, $note),
            };
        } catch (DomainErrors $e) {
            throw new DomainErrors(['action' => array_first($e->errors)]);
        }

        $context = [
            'itemId' => (string) $item->id,
            'projectId' => (string) $item->project->id,
            'kind' => $kind->value,
            'hasNote' => '' !== $note,
        ];
        if (null !== $action) {
            $context['actionId'] = (string) $action->id;
        }
        // After the commits, so a refused action leaves no record. No note text, because a person wrote it.
        $this->auditor->record('inbox.action_requested', AuditOutcome::Success, $context, new AuditSubject('inbox_item', (string) $item->id));

        return $action;
    }

    /** The kinds this handler runs. Open and review are links, and a new session belongs to a later card. */
    private static function runsHere(InboxActionKind $kind): bool
    {
        return \in_array($kind, [InboxActionKind::ResumeRun, InboxActionKind::RetryCard, InboxActionKind::SendAgent, InboxActionKind::Dismiss], true);
    }

    private function resume(RunInboxActionCommand $command, string $note): InboxAction
    {
        $wait = $this->waitOf($command, InboxActionKind::ResumeRun);
        $run = $this->workerRuns->find($wait->runId ?? throw new \LogicException('A run wait has a run.'))
            ?? throw new DomainErrors(['action' => self::NOT_AVAILABLE]);

        // The row commits first, so no queued command lacks a row. A refused request removes it again.
        $action = new InboxAction($command->item, InboxActionKind::ResumeRun, $command->user, $note);
        $this->em->persist($action);
        $this->em->flush();

        try {
            $bridgeCommand = ($this->requestBridgeCommand)(new RequestBridgeCommandCommand($run, BridgeCommandKind::ResumeRun, $command->user, null, BridgeCommandCause::InboxAction, $action->id, $command->item->number));
        } catch (DomainErrors $e) {
            // The bridge closes the entity manager when a race trips its unique index, so delete through the connection.
            $this->em->getConnection()->delete('inbox_actions', ['id' => $action->id?->toRfc4122()]);
            throw $e;
        }

        $action->bridgeCommandId = $bridgeCommand->id;
        $this->em->flush();

        return $action;
    }

    private function retry(RunInboxActionCommand $command, string $note): InboxAction
    {
        $wait = $this->waitOf($command, InboxActionKind::RetryCard);
        $card = $this->cardDirectory->findInProject($command->item->project->id ?? throw new \LogicException('A stored project has an id.'), $wait->watch->cardId)
            ?? throw new DomainErrors(['action' => self::NOT_AVAILABLE]);

        ($this->releasePause)(new ReleaseWorkflowPauseCommand($card, $command->user->id ?? throw new \LogicException('A stored user has an id.'), Actor::Human, $wait->pauseId));

        $action = new InboxAction($command->item, InboxActionKind::RetryCard, $command->user, $note);
        $action->pauseId = $wait->pauseId;
        $this->em->persist($action);
        $this->em->flush();

        return $action;
    }

    private function sendAgent(RunInboxActionCommand $command, string $note): InboxAction
    {
        $item = $command->item;
        $target = $this->itemActions->sendAgentFor($item) ?? throw new DomainErrors(['action' => self::NOT_AVAILABLE]);
        $card = $this->cards->find($target->cardId) ?? throw new DomainErrors(['action' => self::NOT_AVAILABLE]);

        // The row commits first, so the request names an action that exists. A refused request removes it again.
        $action = new InboxAction($item, InboxActionKind::SendAgent, $command->user, $note);
        $this->em->persist($action);
        $this->em->flush();

        try {
            $request = ($this->openWorkRequest)(new OpenWorkRequestCommand(
                project: $item->project,
                subject: WorkSubject::card($target->cardId),
                cardNumber: $card->number,
                kind: $target->kind,
                capability: null,
                ruleId: PersonWork::RULE_PREFIX.'send-agent',
                context: $this->contexts->forCard($card, null),
                fresh: true,
                inboxActionId: $action->id,
                inboxItemNumber: $item->number,
            ));
        } catch (WorkRequestAlreadyLive|DomainErrors) {
            // A race can trip the unique index of live requests, which closes the entity manager.
            $this->em->getConnection()->delete('inbox_actions', ['id' => $action->id?->toRfc4122()]);
            throw new DomainErrors(['action' => self::NOT_AVAILABLE]);
        }

        $action->workRequestId = $request->id;
        $this->em->flush();

        return $action;
    }

    private function dismiss(RunInboxActionCommand $command, string $note): null
    {
        ($this->declineItem)(new DeclineInboxItemCommand($command->item, $note));

        return null;
    }

    private function waitOf(RunInboxActionCommand $command, InboxActionKind $kind): InboxCardWait
    {
        return $this->itemActions->waitFor($command->item, $kind) ?? throw new DomainErrors(['action' => self::NOT_AVAILABLE]);
    }
}
