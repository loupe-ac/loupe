<?php

declare(strict_types=1);

namespace App\Module\Bridge\Workflow;

use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\CardDirectory;
use App\Module\Workflow\Contract\CardSnapshot;
use App\Module\Workflow\Contract\FactProvider;
use Doctrine\ORM\EntityManagerInterface;

final readonly class RunNeedsPersonFactProvider implements FactProvider
{
    public function __construct(
        private WorkerRunRepository $workerRuns,
        private CardDirectory $cards,
        private EntityManagerInterface $em,
    ) {
    }

    #[\Override]
    public function factsClass(): string
    {
        return RunNeedsPersonFacts::class;
    }

    #[\Override]
    public function isOn(): bool
    {
        return true;
    }

    #[\Override]
    public function build(CardSnapshot $card): object
    {
        $project = $this->em->getReference(Project::class, $card->projectId) ?? throw new \LogicException('The project is stored.');
        $row = $this->workerRuns->findLatestRunRows($project, [$card->id])[0] ?? null;
        if (null === $row) {
            return new RunNeedsPersonFacts(null, null, null, null);
        }

        $state = WorkerRunState::tryFrom($row['state']);
        $endedAt = null === $row['ended_at'] ? null : new \DateTimeImmutable($row['ended_at']);

        return new RunNeedsPersonFacts($row['id'], $state, $endedAt, null === $endedAt ? null : $this->cards->newestMoveAt($card->id));
    }

    #[\Override]
    public function fingerprint(object $facts): mixed
    {
        $facts instanceof RunNeedsPersonFacts || throw new \LogicException('The provider fingerprints its own facts.');

        return $facts->needsPerson() ? [$facts->runId, $facts->state?->value] : null;
    }

    #[\Override]
    public function source(): string
    {
        return 'workflow.source.bridge';
    }
}
