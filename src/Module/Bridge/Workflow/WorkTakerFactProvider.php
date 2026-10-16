<?php

declare(strict_types=1);

namespace App\Module\Bridge\Workflow;

use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Bridge\Service\WorkTakers;
use App\Module\Bridge\ValueObject\WorkTakerReading;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\CardSnapshot;
use App\Module\Workflow\Contract\FactProvider;
use App\Module\Workflow\Contract\FingerprintValue;
use Doctrine\ORM\EntityManagerInterface;

final readonly class WorkTakerFactProvider implements FactProvider
{
    public function __construct(
        private WorkRequestRepository $workRequests,
        private WorkTakers $takers,
        private EntityManagerInterface $em,
    ) {
    }

    #[\Override]
    public function factsClass(): string
    {
        return WorkTakerFacts::class;
    }

    #[\Override]
    public function isOn(): bool
    {
        return true;
    }

    #[\Override]
    public function build(CardSnapshot $card): object
    {
        $requests = $this->workRequests->findLiveForCard($card->id);
        if ([] === $requests) {
            return new WorkTakerFacts([]);
        }
        $project = $this->em->getReference(Project::class, $card->projectId) ?? throw new \LogicException('The project is stored.');
        $readings = $this->takers->forRequests($project, $requests);

        $byKind = [];
        foreach ($requests as $request) {
            $reading = $readings[($request->id ?? throw new \LogicException('A stored request has an id.'))->toRfc4122()] ?? null;
            if (null !== $reading) {
                $byKind[$request->kind] = $reading;
            }
        }

        return new WorkTakerFacts($byKind);
    }

    #[\Override]
    public function fingerprint(object $facts): mixed
    {
        $facts instanceof WorkTakerFacts || throw new \LogicException('The provider fingerprints its own facts.');

        return FingerprintValue::sorted(array_map(
            static fn (string $kind, WorkTakerReading $reading): array => [$kind, $reading->state->value],
            array_keys($facts->readings),
            array_values($facts->readings),
        ));
    }

    #[\Override]
    public function source(): string
    {
        return 'workflow.source.bridge';
    }
}
