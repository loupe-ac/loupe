<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Service;

use App\Module\Bridge\Contract\InitRuns;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\Service\InteractiveRuns;
use App\Module\Bridge\Service\OpenInitRuns;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Tests\Module\Bridge\BridgeScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class OpenInitRunsTest extends KernelTestCase
{
    use BridgeScenario;

    public function test_it_is_open_only_while_the_project_has_an_open_init_run(): void
    {
        self::bootKernel();
        $owner = $this->user($this->em(), 'init-runs@example.com');
        $project = $this->project($this->em(), $owner, 'Init runs');
        $other = $this->project($this->em(), $owner, 'Init runs other');
        $projectId = $project->id ?? throw new \LogicException('A stored project has an id.');
        $workerRuns = self::getContainer()->get(WorkerRunRepository::class);
        self::assertInstanceOf(WorkerRunRepository::class, $workerRuns);
        $initRuns = new OpenInitRuns($workerRuns);
        $runs = self::getContainer()->get(InteractiveRuns::class);
        self::assertInstanceOf(InteractiveRuns::class, $runs);

        self::assertFalse($initRuns->isOpen($projectId));

        $runs->open($project, Uuid::v7(), 1, Uuid::v4(), InitRuns::WORK_KIND);
        $runs->recordLaunch($project, new WorkSubject(WorkSubject::PROJECT, $projectId), null, Uuid::v4(), 'other-session', Uuid::v4());
        self::assertFalse($initRuns->isOpen($projectId), 'Only a project run of work kind init holds.');

        [$init] = $runs->recordLaunch($project, new WorkSubject(WorkSubject::PROJECT, $projectId), null, Uuid::v4(), InitRuns::WORK_KIND, Uuid::v4());
        self::assertTrue($initRuns->isOpen($projectId));
        self::assertFalse($initRuns->isOpen($other->id ?? throw new \LogicException('A stored project has an id.')));

        $runs->closeById($project, $init->id ?? throw new \LogicException('A stored run has an id.'));
        self::assertFalse($initRuns->isOpen($projectId));
        self::assertTrue($initRuns->hasClosed($projectId));
    }

    public function test_it_has_closed_only_after_an_init_run_of_the_project_ended(): void
    {
        self::bootKernel();
        $owner = $this->user($this->em(), 'init-closed@example.com');
        $project = $this->project($this->em(), $owner, 'Init closed');
        $other = $this->project($this->em(), $owner, 'Init closed other');
        $projectId = $project->id ?? throw new \LogicException('A stored project has an id.');
        $workerRuns = self::getContainer()->get(WorkerRunRepository::class);
        self::assertInstanceOf(WorkerRunRepository::class, $workerRuns);
        $initRuns = new OpenInitRuns($workerRuns);
        $runs = self::getContainer()->get(InteractiveRuns::class);
        self::assertInstanceOf(InteractiveRuns::class, $runs);

        self::assertFalse($initRuns->hasClosed($projectId));

        [$init] = $runs->recordLaunch($project, new WorkSubject(WorkSubject::PROJECT, $projectId), null, Uuid::v4(), InitRuns::WORK_KIND, Uuid::v4());
        self::assertFalse($initRuns->hasClosed($projectId), 'An open init run is not closed.');

        $otherId = $other->id ?? throw new \LogicException('A stored project has an id.');
        [$otherInit] = $runs->recordLaunch($other, new WorkSubject(WorkSubject::PROJECT, $otherId), null, Uuid::v4(), InitRuns::WORK_KIND, Uuid::v4());
        $runs->closeById($other, $otherInit->id ?? throw new \LogicException('A stored run has an id.'));
        self::assertTrue($initRuns->hasClosed($otherId));
        self::assertFalse($initRuns->hasClosed($projectId), 'A closed init run of another project does not count.');

        $runs->closeById($project, $init->id ?? throw new \LogicException('A stored run has an id.'));
        self::assertTrue($initRuns->hasClosed($projectId));
    }
}
