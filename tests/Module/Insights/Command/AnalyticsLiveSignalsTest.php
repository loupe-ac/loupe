<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Command;

use App\Exception\DomainErrors;
use App\Mercure\ProjectTopicBuilder;
use App\Module\Bridge\Command\ArchiveExperimentCommand;
use App\Module\Bridge\Command\ArchiveExperimentHandler;
use App\Module\Bridge\Command\UnarchiveExperimentCommand;
use App\Module\Bridge\Command\UnarchiveExperimentHandler;
use App\Module\Bridge\Event\BucketTimesRecomputed;
use App\Module\Bridge\Event\ExperimentSetupChanged;
use App\Module\Bridge\Event\WorkerRunChanged;
use App\Module\Bridge\Event\WorkRequestChanged;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Bridge\WorkSubject\WorkSubjectHandlers;
use App\Module\Insights\Command\AcceptProposalCommand;
use App\Module\Insights\Command\AcceptProposalHandler;
use App\Module\Insights\Command\CreateBucketRuleCommand;
use App\Module\Insights\Command\CreateBucketRuleHandler;
use App\Module\Insights\Command\DeleteBucketRuleCommand;
use App\Module\Insights\Command\DeleteBucketRuleHandler;
use App\Module\Insights\Command\MoveBucketRuleCommand;
use App\Module\Insights\Command\MoveBucketRuleHandler;
use App\Module\Insights\Command\PlaceBucketRuleCommand;
use App\Module\Insights\Command\PlaceBucketRuleHandler;
use App\Module\Insights\Command\ReportAnalysisCommand;
use App\Module\Insights\Command\ReportAnalysisHandler;
use App\Module\Insights\Command\UpdateAnalyticsSettingsCommand;
use App\Module\Insights\Command\UpdateAnalyticsSettingsHandler;
use App\Module\Insights\Entity\AnalysisState;
use App\Module\Insights\Entity\BucketRuleDirection;
use App\Module\Insights\Entity\ProposalKind;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Insights\InsightsScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\MockHub;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/** Each writer of the Analytics pages queues its signal, and the signal leaves at the end of the request. */
final class AnalyticsLiveSignalsTest extends KernelTestCase
{
    use InsightsScenario;

    /** @var list<Update> */
    private array $published = [];

    protected function setUp(): void
    {
        self::bootKernel();
        // Before anything builds the hub: the container hands the replacement only to what it builds afterwards.
        self::getContainer()->set('mercure.hub.default', new MockHub(
            'http://mercure/.well-known/mercure',
            new StaticTokenProvider('token'),
            function (Update $update): string {
                $this->published[] = $update;

                return 'id';
            },
        ));
    }

    public function test_an_archive_and_an_unarchive_each_signal_the_experiment(): void
    {
        $project = $this->scenarioProject('analytics-archive');
        $this->seedExperiment($project, 'trial');

        $this->service(ArchiveExperimentHandler::class)(new ArchiveExperimentCommand($project, 'trial'));
        $this->terminate();
        $this->assertSignals($project, [['experiments', 'trial']]);

        $this->service(UnarchiveExperimentHandler::class)(new UnarchiveExperimentCommand($project, 'trial'));
        $this->terminate();
        $this->assertSignals($project, [['experiments', 'trial'], ['experiments', 'trial']]);
    }

    public function test_an_unarchive_of_an_active_experiment_signals_nothing(): void
    {
        $project = $this->scenarioProject('analytics-unarchive-none');
        $this->seedExperiment($project, 'trial');

        $this->service(UnarchiveExperimentHandler::class)(new UnarchiveExperimentCommand($project, 'trial'));
        $this->terminate();

        $this->assertSignals($project, []);
    }

    public function test_a_refused_archive_signals_nothing(): void
    {
        $project = $this->scenarioProject('analytics-archive-refused');

        try {
            $this->service(ArchiveExperimentHandler::class)(new ArchiveExperimentCommand($project, 'ghost'));
            self::fail('Expected a refusal.');
        } catch (DomainErrors) {
        }
        $this->terminate();

        $this->assertSignals($project, []);
    }

    public function test_a_setup_change_signals_the_experiment(): void
    {
        $project = $this->scenarioProject('analytics-setup');

        $this->dispatch(new ExperimentSetupChanged((string) $project->id, 'trial'));
        $this->terminate();

        $this->assertSignals($project, [['experiments', 'trial']]);
    }

    public function test_a_run_change_signals_each_experiment_its_runs_name_once(): void
    {
        $project = $this->scenarioProject('analytics-runs');
        $em = $this->em();
        $runs = [];
        foreach (['alpha', 'alpha', null, 'beta'] as $index => $experiment) {
            $run = $this->seedRun($em, $project, new \DateTimeImmutable('2026-01-01 10:00:00'), cardNumber: $index + 1, cardId: Uuid::v7());
            $run->experiment = $experiment;
            $runs[] = $run;
        }
        $em->flush();

        foreach (WorkerRunChanged::ofRuns($runs) as $event) {
            $this->dispatch($event);
        }
        $this->terminate();

        $this->assertSignals($project, [['experiments', 'alpha'], ['experiments', 'beta']]);
    }

    public function test_a_run_change_of_runs_with_no_experiment_signals_nothing(): void
    {
        $project = $this->scenarioProject('analytics-runs-none');
        $run = $this->seedRun($this->em(), $project, new \DateTimeImmutable('2026-01-01 10:00:00'), cardId: Uuid::v7());

        foreach (WorkerRunChanged::ofRuns([$run]) as $event) {
            $this->dispatch($event);
        }
        $this->terminate();

        $this->assertSignals($project, []);
    }

    public function test_the_analysis_writers_signal_the_reports_area(): void
    {
        $em = $this->em();
        $project = $this->scenarioProject('analytics-reports');
        $reported = $this->seedAnalysis($em, $project, AnalysisState::Running);
        $document = $this->seedDocument($em, $project);

        $this->service(ReportAnalysisHandler::class)(new ReportAnalysisCommand($project, (string) $reported->id, (string) $document->id, []));
        $this->service(UpdateAnalyticsSettingsHandler::class)(new UpdateAnalyticsSettingsCommand($project, null, null, true, changeModel: false, changeEffort: false));
        $this->terminate();

        $this->assertSignals($project, [['reports', (string) $reported->id], ['reports', null]]);
    }

    public function test_a_refused_report_signals_nothing(): void
    {
        $project = $this->scenarioProject('analytics-report-refused');

        try {
            $this->service(ReportAnalysisHandler::class)(new ReportAnalysisCommand($project, (string) Uuid::v7(), (string) Uuid::v7(), []));
            self::fail('Expected a refusal.');
        } catch (DomainErrors) {
        }
        $this->terminate();

        $this->assertSignals($project, []);
    }

    public function test_a_claim_and_a_failed_request_signal_the_analysis(): void
    {
        $em = $this->em();
        $project = $this->scenarioProject('analytics-claim');
        $claimed = $this->seedAnalysis($em, $project);
        $this->dispatch(new WorkRequestChanged($project->requireId(), 'analysis', $claimed->id ?? throw new \LogicException('An id.'), Uuid::v7(), WorkRequestState::Claimed));
        $this->terminate();
        $this->assertSignals($project, [['reports', (string) $claimed->id]]);

        $failed = $this->seedAnalysis($em, $project, AnalysisState::Running);
        $handler = $this->service(WorkSubjectHandlers::class)->for('analysis');
        self::assertNotNull($handler);
        $handler->onSettled($this->seedWorkRequest($em, $project, kind: 'analysis', state: WorkRequestState::Refused, ruleId: 'insights.analysis', subject: new \App\Module\Bridge\ValueObject\WorkSubject('analysis', $failed->id ?? throw new \LogicException('An id.'))));
        $this->terminate();

        $this->assertSignals($project, [['reports', (string) $claimed->id], ['reports', (string) $failed->id]]);
    }

    public function test_a_claim_of_an_analysis_that_is_not_waiting_signals_nothing(): void
    {
        $em = $this->em();
        $project = $this->scenarioProject('analytics-claim-none');
        $paused = $this->seedAnalysis($em, $project, AnalysisState::Paused);

        $this->dispatch(new WorkRequestChanged($project->requireId(), 'analysis', $paused->id ?? throw new \LogicException('An id.'), Uuid::v7(), WorkRequestState::Claimed));
        $this->terminate();

        $this->assertSignals($project, []);
    }

    public function test_accepting_a_rule_proposal_signals_the_reports_and_the_rules(): void
    {
        $em = $this->em();
        $project = $this->scenarioProject('analytics-accept');
        $analysis = $this->seedAnalysis($em, $project, AnalysisState::Done);
        $proposal = $this->seedProposal($em, $analysis, ProposalKind::BucketRule, 0, ['pattern' => 'Bash:lint*', 'bucket' => 'lint']);

        $this->service(AcceptProposalHandler::class)(new AcceptProposalCommand($proposal));
        $this->terminate();

        $this->assertSignals($project, [['reports', (string) $analysis->id], ['bucket_rules', null]]);
    }

    public function test_each_rule_handler_signals_its_rule(): void
    {
        $project = $this->scenarioProject('analytics-rules');
        $first = $this->service(CreateBucketRuleHandler::class)(new CreateBucketRuleCommand($project, 'a*', 'a'));
        $second = $this->service(CreateBucketRuleHandler::class)(new CreateBucketRuleCommand($project, 'b*', 'b'));
        $firstId = (string) $first->id;
        $secondId = (string) $second->id;
        $this->terminate();
        $this->assertSignals($project, [['bucket_rules', $firstId], ['bucket_rules', $secondId]]);

        $steps = [
            fn () => $this->service(MoveBucketRuleHandler::class)(new MoveBucketRuleCommand($second, BucketRuleDirection::Up)),
            fn () => $this->service(PlaceBucketRuleHandler::class)(new PlaceBucketRuleCommand($second, 1)),
            fn () => $this->service(DeleteBucketRuleHandler::class)(new DeleteBucketRuleCommand($first)),
        ];
        foreach ([$secondId, $secondId, $firstId] as $index => $expectedId) {
            $this->published = [];
            $steps[$index]();
            $this->terminate();
            $this->assertSignals($project, [['bucket_rules', $expectedId]]);
        }
    }

    public function test_a_move_that_changes_nothing_signals_nothing(): void
    {
        $project = $this->scenarioProject('analytics-rules-none');
        $only = $this->service(CreateBucketRuleHandler::class)(new CreateBucketRuleCommand($project, 'a*', 'a'));
        $this->terminate();
        $this->published = [];

        $this->service(MoveBucketRuleHandler::class)(new MoveBucketRuleCommand($only, BucketRuleDirection::Up));
        $this->service(PlaceBucketRuleHandler::class)(new PlaceBucketRuleCommand($only, 0));
        $this->terminate();

        $this->assertSignals($project, []);
    }

    public function test_a_bucket_time_recompute_signals_the_rules_area(): void
    {
        $project = $this->scenarioProject('analytics-recompute');

        $this->dispatch(new BucketTimesRecomputed((string) $project->id));
        $this->terminate();

        $this->assertSignals($project, [['bucket_rules', null]]);
    }

    public function test_the_signal_carries_only_the_type_the_area_and_an_id(): void
    {
        $project = $this->scenarioProject('analytics-payload');

        $this->dispatch(new ExperimentSetupChanged((string) $project->id, 'trial'));
        $this->terminate();

        $update = $this->updatesOf($project)[0] ?? throw new \LogicException('A signal was sent.');
        self::assertSame(['type', 'area', 'id', 'origin'], array_keys(json_decode($update->getData(), true, 512, \JSON_THROW_ON_ERROR)));
        self::assertSame('analytics.changed', json_decode($update->getData(), true, 512, \JSON_THROW_ON_ERROR)['type']);
        self::assertTrue($update->isPrivate());
    }

    /** @param list<array{string, string|null}> $expected the area and the id of each signal, in order */
    private function assertSignals(Project $project, array $expected): void
    {
        $actual = array_map(static function (Update $update): array {
            $data = json_decode($update->getData(), true, 512, \JSON_THROW_ON_ERROR);

            return [$data['area'], $data['id']];
        }, $this->updatesOf($project));
        self::assertSame($expected, $actual);
    }

    /** @return list<Update> */
    private function updatesOf(Project $project): array
    {
        $topic = $this->service(ProjectTopicBuilder::class)->forAnalytics($project->requireId());

        return array_values(array_filter($this->published, static fn (Update $update): bool => \in_array($topic, $update->getTopics(), true)));
    }

    private function dispatch(object $event): void
    {
        $this->service(EventDispatcherInterface::class)->dispatch($event);
    }

    private function terminate(): void
    {
        self::assertNotNull(self::$kernel);
        $this->service(EventDispatcherInterface::class)->dispatch(new TerminateEvent(self::$kernel, Request::create('/'), new Response()), KernelEvents::TERMINATE);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function service(string $class): object
    {
        $service = self::getContainer()->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }
}
