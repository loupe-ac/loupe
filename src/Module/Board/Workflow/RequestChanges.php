<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow;

use App\Exception\DomainErrors;
use App\Module\Account\Repository\UserRepository;
use App\Module\Review\Command\SubmitReviewCommand;
use App\Module\Review\Command\SubmitReviewHandler;
use App\Module\Review\Entity\Verdict;
use App\Module\Review\Repository\DocumentRepository;
use App\Module\Workflow\Contract\Action;
use App\Module\Workflow\Contract\ActionContext;
use App\Module\Workflow\Contract\ActionDescription;
use App\Module\Workflow\Contract\ActionOutcome;
use App\Module\Workflow\Contract\ActionTraits;
use App\Module\Workflow\Contract\DocumentFacts;
use App\Module\Workflow\Contract\Parameter;
use App\Module\Workflow\Contract\ParameterType;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Requests changes on each approved or in-review document of the card that carries one of the tags.
 * The note is a translation key, translated when the verdict is written, because the revise worker reads the note.
 */
final readonly class RequestChanges implements Action
{
    public const string VERDICT_REFUSED = 'document-verdict-refused';

    public const string KEY = 'request-changes';

    private const array STATUSES = ['approved', 'in-review'];

    public function __construct(
        private DocumentRepository $documents,
        private UserRepository $users,
        private SubmitReviewHandler $submitReview,
        private TranslatorInterface $translator,
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
            new Parameter('tags', ParameterType::List),
            new Parameter('note', ParameterType::String),
        ];
    }

    #[\Override]
    public static function traits(): ActionTraits
    {
        return new ActionTraits(refreshesFacts: true, listable: true);
    }

    #[\Override]
    public function describe(array $params): ActionDescription
    {
        return new ActionDescription('workflow.settings.action.request_changes', 'workflow.panel.action.request_changes');
    }

    #[\Override]
    public function workKind(array $params): ?string
    {
        return null;
    }

    #[\Override]
    public function run(ActionContext $context): ActionOutcome
    {
        $tags = $context->stringList('tags');
        $targets = array_filter(
            $context->facts->get(DocumentsFacts::class)->documents,
            static fn (DocumentFacts $document): bool => \in_array($document->status, self::STATUSES, true) && [] !== array_intersect($tags, $document->tags) && null !== $document->version,
        );
        if ([] === $targets) {
            return ActionOutcome::done();
        }

        $workflow = $this->users->workflow();
        $note = $this->translator->trans($context->string('note'));
        $refused = false;
        foreach ($targets as $facts) {
            $document = $this->documents->find(Uuid::fromString($facts->id));
            if (null === $document) {
                continue;
            }
            try {
                ($this->submitReview)(new SubmitReviewCommand(
                    reviewer: $workflow,
                    document: $document,
                    verdict: Verdict::ChangesRequested->value,
                    versionNumber: $facts->version ?? throw new \LogicException('The filter keeps the documents with a version.'),
                    note: $note,
                    expectedReviewId: $facts->newestReviewId,
                    workflowRule: $context->ruleId,
                ));
            } catch (DomainErrors) {
                $refused = true;
            }
        }

        return $refused ? ActionOutcome::refused(self::VERDICT_REFUSED) : ActionOutcome::done();
    }
}
