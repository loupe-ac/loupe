<?php

declare(strict_types=1);

namespace App\Module\Inbox\Workflow;

use App\Module\Board\Repository\CardDocumentRepository;
use App\Module\Inbox\Repository\InboxReviewRepository;
use App\Module\Inbox\Service\InboxAvailability;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\CardSnapshot;
use App\Module\Workflow\Contract\FactProvider;
use Doctrine\ORM\EntityManagerInterface;

/** An inbox that is off holds no review item, so no agent review is open. */
final readonly class AgentReviewOpenFactProvider implements FactProvider
{
    public function __construct(
        private CardDocumentRepository $cardDocuments,
        private InboxReviewRepository $inboxReviews,
        private InboxAvailability $availability,
        private EntityManagerInterface $em,
    ) {
    }

    #[\Override]
    public function factsClass(): string
    {
        return AgentReviewOpenFacts::class;
    }

    #[\Override]
    public function isOn(): bool
    {
        return true;
    }

    #[\Override]
    public function build(CardSnapshot $card): object
    {
        if (!$this->availability->isEnabled()) {
            return new AgentReviewOpenFacts([]);
        }

        $project = $this->em->getReference(Project::class, $card->projectId) ?? throw new \LogicException('The project is stored.');
        $documents = [];
        foreach ($this->cardDocuments->findInReviewForCards($project, [$card->id]) as $row) {
            $documents[(string) $row['link']->document->id] = $row['link']->document;
        }
        $ids = $this->inboxReviews->findDocumentIdsUnderAgentReview(array_values($documents));
        sort($ids);

        return new AgentReviewOpenFacts($ids);
    }

    #[\Override]
    public function fingerprint(object $facts): mixed
    {
        $facts instanceof AgentReviewOpenFacts || throw new \LogicException('The provider fingerprints its own facts.');

        return $facts->documentIds;
    }

    #[\Override]
    public function source(): string
    {
        return 'workflow.source.inbox';
    }
}
