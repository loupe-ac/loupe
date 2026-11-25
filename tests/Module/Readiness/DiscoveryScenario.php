<?php

declare(strict_types=1);

namespace App\Tests\Module\Readiness;

use App\Module\Board\Entity\Card;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Project\Entity\Project;
use App\Module\Readiness\Entity\DiscoveryRun;
use App\Module\Readiness\Entity\DiscoveryRunState;
use App\Tests\Module\Workflow\WorkflowProjects;
use App\Tests\Support\AgentCredential;
use Symfony\Component\Uid\Uuid;

/** Projects, discovery runs and the bridges and work requests of the database tests of discovery. */
trait DiscoveryScenario
{
    use WorkflowProjects;

    private int $cardNumber = 0;

    private function card(Project $project, string $column = 'backlog'): Card
    {
        $card = new Card($project, $this->column($project, $column), 'A card', '', ++$this->cardNumber);
        $card->type = 'tooling';
        $this->em()->persist($card);
        $this->em()->flush();

        return $card;
    }

    private function discoveryRun(Project $project, DiscoveryRunState $state = DiscoveryRunState::Requested, string $createdAt = '2026-10-02 12:00:00', ?Card $card = null): DiscoveryRun
    {
        $run = new DiscoveryRun($project, new \DateTimeImmutable($createdAt), $card);
        $run->state = $state;
        $this->em()->persist($run);
        $this->em()->flush();

        return $run;
    }

    /** @param list<string>|null $workKinds null makes a bridge that runs app prompts, and a list makes one that runs only those kinds */
    private function discoveryBridge(Project $project, ?array $workKinds = null, \DateTimeImmutable $lastSeenAt = new \DateTimeImmutable()): Bridge
    {
        $bridge = new Bridge(AgentCredential::managed($this->em(), $project->owner, $project->owner->id), Uuid::v4(), [(string) $project->id], 'b4e39aa7', $lastSeenAt);
        $bridge->capabilities = [Bridge::CAPABILITY_WORK_REQUESTS, Bridge::CAPABILITY_WAITS, ...(null === $workKinds ? [Bridge::CAPABILITY_APP_PROMPTS] : [])];
        $bridge->workKinds = $workKinds ?? [];
        $this->em()->persist($bridge);
        $this->em()->flush();

        return $bridge;
    }

    /** @return list<WorkRequest> the requests about the run, oldest first */
    private function discoveryRequests(DiscoveryRun $run): array
    {
        $repository = self::getContainer()->get(WorkRequestRepository::class);
        self::assertInstanceOf(WorkRequestRepository::class, $repository);

        return array_values($repository->findBy(
            ['subjectType' => DiscoveryRun::SUBJECT_TYPE, 'subjectId' => $run->id],
            ['createdAt' => 'ASC', 'id' => 'ASC'],
        ));
    }

    private function discoveryRequest(DiscoveryRun $run, string $kind = 'discovery'): WorkRequest
    {
        $request = new WorkRequest($run->project, DiscoveryRun::SUBJECT_TYPE, $run->id ?? throw new \LogicException('A stored run has an id.'), null, $kind, 'subject-discovery', $kind, new \DateTimeImmutable());
        $this->em()->persist($request);
        $this->em()->flush();

        return $request;
    }

    /** The row as the database holds it, whatever the entity manager holds. */
    private function storedRun(DiscoveryRun $run): DiscoveryRun
    {
        $id = $run->id ?? throw new \LogicException('A stored run has an id.');
        $this->em()->clear();

        return $this->em()->find(DiscoveryRun::class, $id) ?? throw new \LogicException('The run is stored.');
    }
}
