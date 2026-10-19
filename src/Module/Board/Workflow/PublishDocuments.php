<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow;

use App\Exception\DomainErrors;
use App\Module\Review\Command\PublishDocumentCommand;
use App\Module\Review\Command\PublishDocumentHandler;
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

/** Sends each draft document of the card that carries one of the tags to review. A document that is not a draft stays as it is. */
final readonly class PublishDocuments implements Action
{
    public const string PUBLISH_REFUSED = 'document-publish-refused';

    public const string KEY = 'publish-documents';
    private const string DRAFT = 'draft';

    public function __construct(
        private DocumentRepository $documents,
        private PublishDocumentHandler $publishDocument,
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
        ];
    }

    #[\Override]
    public static function traits(): ActionTraits
    {
        return new ActionTraits(refreshesFacts: true, publishesDocuments: true);
    }

    #[\Override]
    public function describe(array $params): ActionDescription
    {
        return new ActionDescription('workflow.settings.action.publish_documents', 'workflow.panel.action.publish_documents');
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
        $drafts = array_filter(
            $context->facts->get(DocumentsFacts::class)->documents,
            static fn (DocumentFacts $document): bool => self::DRAFT === $document->status && [] !== array_intersect($tags, $document->tags),
        );

        $refused = false;
        foreach ($drafts as $facts) {
            $document = $this->documents->find(Uuid::fromString($facts->id));
            if (null === $document) {
                continue;
            }
            try {
                ($this->publishDocument)(new PublishDocumentCommand($document));
            } catch (DomainErrors) {
                $refused = true;
            }
        }

        return $refused ? ActionOutcome::refused(self::PUBLISH_REFUSED) : ActionOutcome::done();
    }
}
