<?php

declare(strict_types=1);

namespace App\Tests\Module\Readiness\WorkSubject;

use App\Module\Board\Entity\Card;
use App\Module\Bridge\Command\ReportInteractiveLaunchCommand;
use App\Module\Bridge\Command\ReportInteractiveLaunchHandler;
use App\Module\Bridge\Command\ReportInteractiveLaunchResult;
use App\Module\Bridge\Command\ReportWorkerRunStateCommand;
use App\Module\Bridge\Command\ReportWorkerRunStateHandler;
use App\Module\Bridge\Command\ReportWorkerRunStateResult;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Project\Entity\Project;
use App\Module\Readiness\Entity\DiscoveryRun;
use App\Tests\Module\Readiness\DiscoveryScenario;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/** A bridge that claimed a card request before the upgrade reports its worker with the card after the request moved to the run. */
final class FormerDiscoverySubjectTest extends KernelTestCase
{
    use DiscoveryScenario;

    private Project $project;

    private Card $oldCard;

    private DiscoveryRun $run;

    private WorkRequest $request;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->project = $this->workflowProject('discovery-former');
        $this->oldCard = $this->card($this->project);
        $this->run = $this->discoveryRun($this->project, card: $this->oldCard);
        $this->request = $this->discoveryRequest($this->run);
    }

    /** @return iterable<string, array{string}> */
    public static function reports(): iterable
    {
        yield 'a worker run' => ['worker'];
        yield 'an interactive launch' => ['interactive'];
    }

    #[DataProvider('reports')]
    public function test_a_report_that_names_the_former_card_is_stored_about_the_run(string $report): void
    {
        $result = $this->send($report, $this->project, $this->oldCard, $this->request);

        $stored = $result->run ?? throw new \LogicException('The report stores a run.');
        self::assertSame(DiscoveryRun::SUBJECT_TYPE, $stored->subjectType);
        self::assertTrue($stored->subjectId->equals($this->run->id));
        self::assertNull($stored->cardNumber);
        self::assertTrue($stored->workRequestId?->equals($this->request->id));
    }

    #[DataProvider('reports')]
    public function test_a_report_that_names_another_card_is_refused(string $report): void
    {
        $result = $this->send($report, $this->project, $this->card($this->project), $this->request);

        self::assertNull($result->run);
        self::assertSame(ReportWorkerRunStateResult::WORK_REQUEST_NOT_FOUND, $result->error);
    }

    #[DataProvider('reports')]
    public function test_a_report_into_another_project_cannot_take_the_request(string $report): void
    {
        $other = $this->workflowProject('discovery-former-other');

        $result = $this->send($report, $other, $this->card($other), $this->request);

        self::assertNull($result->run);
        self::assertSame(ReportWorkerRunStateResult::WORK_REQUEST_NOT_FOUND, $result->error);
    }

    /** Only the subject of a discovery run has a former card. */
    #[DataProvider('reports')]
    public function test_a_report_that_names_a_card_for_other_subject_work_is_refused(string $report): void
    {
        $analysis = new WorkRequest($this->project, 'analysis', $this->run->id ?? throw new \LogicException('Stored.'), null, 'analysis', null, 'insights.analysis', new \DateTimeImmutable());
        $this->em()->persist($analysis);
        $this->em()->flush();

        $result = $this->send($report, $this->project, $this->oldCard, $analysis);

        self::assertSame(ReportWorkerRunStateResult::WORK_REQUEST_NOT_FOUND, $result->error);
    }

    private function send(string $report, Project $project, Card $card, WorkRequest $request): ReportWorkerRunStateResult|ReportInteractiveLaunchResult
    {
        $subject = WorkSubject::card($card->id ?? throw new \LogicException('Stored.'));
        $requestId = $request->id ?? throw new \LogicException('Stored.');
        if ('worker' === $report) {
            $handler = self::getContainer()->get(ReportWorkerRunStateHandler::class);
            self::assertInstanceOf(ReportWorkerRunStateHandler::class, $handler);

            return $handler(new ReportWorkerRunStateCommand(
                owner: $project->owner,
                handle: (string) $project->id,
                runKey: Uuid::v4(),
                bridgeId: Uuid::v4(),
                state: WorkerRunState::Running,
                at: new \DateTimeImmutable(),
                subject: $subject,
                cardNumber: $card->number,
                workRequestId: $requestId,
                workKind: 'discovery',
                ruleId: 'discovery',
            ));
        }
        $handler = self::getContainer()->get(ReportInteractiveLaunchHandler::class);
        self::assertInstanceOf(ReportInteractiveLaunchHandler::class, $handler);

        return $handler(new ReportInteractiveLaunchCommand(
            owner: $project->owner,
            handle: (string) $project->id,
            sessionId: Uuid::v4(),
            bridgeId: Uuid::v4(),
            subject: $subject,
            cardNumber: $card->number,
            workKind: 'discovery',
            state: WorkerRunState::Running,
            at: new \DateTimeImmutable(),
            workRequestId: $requestId,
            ruleId: 'discovery',
        ));
    }
}
