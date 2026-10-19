<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow;

use App\Exception\DomainErrors;
use App\Module\Board\Repository\CardRepository;
use App\Module\Bridge\Command\OpenWorkRequestCommand;
use App\Module\Bridge\Command\OpenWorkRequestHandler;
use App\Module\Bridge\Command\WorkRequestAlreadyLive;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Workflow\Contract\ActionContext;
use App\Module\Workflow\Contract\ActionOutcome;
use App\Module\Workflow\Contract\DocumentFacts;
use App\Module\Workflow\Contract\ParameterNames;
use App\Module\Workflow\Contract\RetryNotes;
use App\Module\Workflow\Contract\WorkOpener;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Opens a work request of a card for a rule, with what the card holds now as its context.
 * A live request of the kind already does the work.
 */
#[AsAlias(WorkOpener::class)]
final readonly class WorkRequestOpener implements WorkOpener
{
    public function __construct(
        private OpenWorkRequestHandler $openWorkRequest,
        private CardRepository $cards,
        private WorkRequestContexts $contexts,
        private RetryNotes $retryNotes,
    ) {
    }

    #[\Override]
    public function open(ActionContext $context, string $kind, ?string $capability, ?string $reason = null, ?string $role = null): ActionOutcome
    {
        $facts = $context->facts;
        $card = $this->cards->find($context->card->id) ?? throw new \LogicException('A stored card has an id.');
        $documentId = null;
        $tag = $context->optionalString(ParameterNames::DOCUMENT_TAG);
        if (null !== $tag) {
            $status = $context->optionalString(ParameterNames::DOCUMENT_STATUS);
            $documents = array_values(array_filter(
                $facts->get(DocumentsFacts::class)->documents,
                static fn (DocumentFacts $document): bool => \in_array($tag, $document->tags, true) && (null === $status || $status === $document->status),
            ));
            if (1 !== \count($documents)) {
                return ActionOutcome::refused([] === $documents ? 'document-not-found' : 'document-ambiguous');
            }
            $documentId = $documents[0]->id;
        }

        $retry = $this->retryNotes->forRuleWork($card->id ?? throw new \LogicException('A stored card has an id.'), $context->ruleId);
        try {
            $request = ($this->openWorkRequest)(new OpenWorkRequestCommand(
                project: $card->project,
                subject: WorkSubject::card($card->id ?? throw new \LogicException('A stored card has an id.')),
                cardNumber: $card->number,
                kind: $kind,
                capability: $capability,
                ruleId: $context->ruleId,
                context: $this->contexts->forCard($card, $facts->pullRequest, $documentId, $reason),
                prompt: $context->prompt,
                inboxActionId: $retry?->actionId,
                inboxItemNumber: $retry?->itemNumber,
                inboxItemId: $retry?->itemId,
                role: $role,
            ));
        } catch (WorkRequestAlreadyLive $e) {
            return ActionOutcome::alreadyLive($e->ruleId);
        } catch (DomainErrors) {
            return ActionOutcome::refused('invalid-work-request');
        }

        return ActionOutcome::done($request->id);
    }
}
