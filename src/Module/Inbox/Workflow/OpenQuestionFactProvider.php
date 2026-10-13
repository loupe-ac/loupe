<?php

declare(strict_types=1);

namespace App\Module\Inbox\Workflow;

use App\Module\Inbox\Repository\InboxItemRepository;
use App\Module\Inbox\Service\InboxAvailability;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\CardSnapshot;
use App\Module\Workflow\Contract\FactProvider;
use Doctrine\ORM\EntityManagerInterface;

final readonly class OpenQuestionFactProvider implements FactProvider
{
    public function __construct(
        private InboxItemRepository $inboxItems,
        private InboxAvailability $availability,
        private EntityManagerInterface $em,
    ) {
    }

    #[\Override]
    public function factsClass(): string
    {
        return OpenQuestionFacts::class;
    }

    #[\Override]
    public function isOn(): bool
    {
        return $this->availability->isEnabled();
    }

    #[\Override]
    public function build(CardSnapshot $card): object
    {
        $project = $this->em->getReference(Project::class, $card->projectId) ?? throw new \LogicException('The project is stored.');
        $row = $this->inboxItems->findOpenAsksOfCards($project, [$card->id->toRfc4122()])[0] ?? null;

        return new OpenQuestionFacts(null === $row ? null : new OpenQuestion((int) $row['number'], $row['title'], new \DateTimeImmutable($row['created_at'])));
    }

    #[\Override]
    public function fingerprint(object $facts): mixed
    {
        $facts instanceof OpenQuestionFacts || throw new \LogicException('The provider fingerprints its own facts.');

        return $facts->question?->number;
    }

    #[\Override]
    public function source(): string
    {
        return 'workflow.source.inbox';
    }
}
