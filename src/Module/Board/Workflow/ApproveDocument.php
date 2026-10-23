<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow;

use App\Exception\DomainErrors;
use App\Module\Account\Repository\UserRepository;
use App\Module\Board\Entity\CardEventKind;
use App\Module\Board\Repository\CardEventRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Review\Command\SubmitReviewCommand;
use App\Module\Review\Command\SubmitReviewHandler;
use App\Module\Review\Entity\Verdict;
use App\Module\Review\Repository\DocumentRepository;
use App\Module\Workflow\Contract\Action;
use App\Module\Workflow\Contract\ActionContext;
use App\Module\Workflow\Contract\ActionDescription;
use App\Module\Workflow\Contract\ActionOutcome;
use App\Module\Workflow\Contract\ActionTraits;
use App\Module\Workflow\Contract\Actor;
use App\Module\Workflow\Contract\DocumentFacts;
use App\Module\Workflow\Contract\Parameter;
use App\Module\Workflow\Contract\ParameterType;
use Symfony\Component\Uid\Uuid;

/**
 * Approves each document of the card that carries the tag, is in review and has a clean entry check on its newest version.
 * The approval names the version of the check and the newest review that the facts hold. A revision or a verdict in between
 * makes the handler refuse it, and so does an answer that a person cleared after the entry check. The action then writes
 * a `verdict-failed` row and ends done. It does not retry: the document stays in review for a person.
 */
final readonly class ApproveDocument implements Action
{
    public const string KEY = 'approve-document';

    private const string IN_REVIEW = 'in-review';

    public function __construct(
        private CardRepository $cards,
        private DocumentRepository $documents,
        private UserRepository $users,
        private SubmitReviewHandler $submitReview,
        private CardEventRepository $cardEvents,
    ) {
    }

    #[\Override]
    public static function key(): string
    {
        return self::KEY;
    }

    #[\Override]
    public static function source(): string
    {
        return 'workflow.source.board';
    }

    #[\Override]
    public static function parameters(): array
    {
        return [
            new Parameter('tag', ParameterType::String),
        ];
    }

    #[\Override]
    public static function traits(): ActionTraits
    {
        return new ActionTraits(refreshesFacts: true);
    }

    #[\Override]
    public function describe(array $params): ActionDescription
    {
        return new ActionDescription('workflow.settings.action.approve_document', 'workflow.panel.action.approve_document');
    }

    #[\Override]
    public function workKind(array $params): ?string
    {
        return null;
    }

    #[\Override]
    public function run(ActionContext $context): ActionOutcome
    {
        $tag = $context->string('tag');
        $checked = array_filter(
            $context->facts->get(DocumentsFacts::class)->documents,
            static fn (DocumentFacts $document): bool => self::IN_REVIEW === $document->status && \in_array($tag, $document->tags, true) && null !== $document->cleanCheckVersion,
        );
        if ([] === $checked) {
            return ActionOutcome::done();
        }

        $workflow = $this->users->workflow();
        $card = $this->cards->find($context->card->id) ?? throw new \LogicException('A stored card has an id.');
        foreach ($checked as $facts) {
            $document = $this->documents->find(Uuid::fromString($facts->id));
            if (null === $document) {
                continue;
            }
            try {
                ($this->submitReview)(new SubmitReviewCommand(
                    reviewer: $workflow,
                    document: $document,
                    verdict: Verdict::Approved->value,
                    versionNumber: $facts->cleanCheckVersion ?? throw new \LogicException('The filter keeps the documents with a clean check.'),
                    expectedReviewId: $facts->newestReviewId,
                    workflowRule: $context->ruleId,
                ));
            } catch (DomainErrors $refusal) {
                $this->cardEvents->record($card, CardEventKind::VerdictFailed, Actor::System, null, [
                    'rule' => $context->ruleId,
                    'document' => $document->title,
                    'documentId' => $facts->id,
                    'refusal' => self::refusalCode($refusal),
                ]);
            }
        }

        return ActionOutcome::done();
    }

    /** The short code of the first refusal, such as `verdict_stale`: the last part of its translation key. */
    private static function refusalCode(DomainErrors $refusal): string
    {
        $key = array_first($refusal->errors);

        return str_contains($key, '.') ? substr($key, (int) strrpos($key, '.') + 1) : $key;
    }
}
