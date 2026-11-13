<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox;

use App\Module\Bridge\Contract\InitRuns;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Service\InteractiveRuns;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Project\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/** Opens and closes the init run that holds the onboarding items of a project. */
trait InitRunHolds
{
    private function startInit(Project $project): WorkerRun
    {
        $managed = $this->managedProject($project);
        $projectId = $managed->requireId();
        [$run] = $this->interactiveRuns()->recordLaunch($managed, new WorkSubject(WorkSubject::PROJECT, $projectId), null, Uuid::v4(), InitRuns::WORK_KIND, Uuid::v4());

        return $run;
    }

    private function endInit(Project $project, WorkerRun $run): void
    {
        $this->interactiveRuns()->closeById($this->managedProject($project), $run->id ?? throw new \LogicException('A stored run has an id.'));
    }

    private function managedProject(Project $project): Project
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em->find(Project::class, $project->requireId()) ?? throw new \LogicException('The project is gone.');
    }

    private function interactiveRuns(): InteractiveRuns
    {
        $runs = self::getContainer()->get(InteractiveRuns::class);
        self::assertInstanceOf(InteractiveRuns::class, $runs);

        return $runs;
    }
}
