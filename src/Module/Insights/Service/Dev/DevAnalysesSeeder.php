<?php

declare(strict_types=1);

namespace App\Module\Insights\Service\Dev;

use App\DevSeed\DevSeederInterface;
use App\DevSeed\DevSeedId;
use App\DevSeed\SeedContext;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkerRunToolCall;
use App\Module\Bridge\Metric\MetricRange;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\Service\BucketTimeComputer;
use App\Module\Bridge\Service\Dev\DevRunUsage;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkerRunToolCallKind;
use App\Module\Bridge\ValueObject\WorkerRunUsageSource;
use App\Module\Inbox\Service\Dev\DevExperimentSeeder;
use App\Module\Insights\Command\StartAnalysisHandler;
use App\Module\Insights\Entity\Analysis;
use App\Module\Insights\Entity\AnalysisScope;
use App\Module\Insights\Entity\AnalysisTopic;
use App\Module\Insights\Entity\InsightsBucketRule;
use App\Module\Insights\Entity\Proposal;
use App\Module\Insights\Entity\ProposalKind;
use App\Module\Insights\Entity\ProposalState;
use App\Module\Insights\Repository\AnalysisRepository;
use App\Module\Insights\Repository\InsightsBucketRuleRepository;
use App\Module\Insights\Service\AnalysisSettings;
use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use App\Module\Project\Service\Dev\DevProjectSeeder;
use App\Module\Review\Entity\Document;
use App\Module\Review\Repository\DocumentRepository;
use App\Module\Review\Service\MarkdownRenderer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\Uid\Uuid;

/**
 * Writes a finished cost analysis with its report, two proposals and a costed
 * run, a waiting one, and a finished experiment analysis, so the Reports page
 * of a dev project shows each state. It also writes three time bucket rules, a
 * finished time analysis whose run holds tool calls, and one bucket rule
 * proposal. A later seed looks up each analysis, report and run on its own, and
 * writes only a missing one. A proposal, a rule and a tool call have no fixed id,
 * so they come only with a new analysis or run. The waiting analysis opens no
 * work request, so no bridge claims it.
 */
#[When('dev')]
#[When('test')]
final readonly class DevAnalysesSeeder implements DevSeederInterface
{
    public const string KEY = 'analyses';

    private const string REPORT = <<<'MARKDOWN'
        # Cost report: last 90 days

        ## Summary

        Most of the cost comes from the implementation stage. Each run installs the dependencies again, which takes about a fifth of its tokens.

        ## Proposals

        1. Cache the dependencies between runs.
        2. Run the lint stage on a smaller model.
        MARKDOWN;

    private const string TIME_REPORT = <<<'MARKDOWN'
        # Time report: last 30 days

        ## Summary

        A run spends most of its tool time in `make test` and `make build`. No bucket rule takes them, so they fall in `other`.

        ## Proposals

        1. Put the `make` calls in a bucket of their own.
        MARKDOWN;

    /** @var list<array{string, string}> pattern and bucket */
    private const array RULES = [['git *', 'git'], ['grep', 'search'], ['Agent', 'subagent']];

    /** @var list<array{string, int, int, list<string>}> tool, start offset in seconds, duration in milliseconds, signatures */
    private const array CALLS = [
        ['Bash', 10, 45_000, ['git push']],
        ['Bash', 70, 12_000, ['grep']],
        ['Bash', 100, 240_000, ['make test']],
        ['Bash', 400, 180_000, ['make build']],
        ['Agent', 600, 300_000, ['Agent']],
        ['Bash', 950, 8_000, ['git status']],
    ];

    private const string EXPERIMENT_REPORT = <<<'MARKDOWN'
        # Experiment report: implement

        ## Summary

        The sonnet variant costs about 60% less per merged card, and the cost gap is clear. Its merge rate is lower and it needs more fix rounds, but neither gap is clear yet. Run more cards before a decision.

        ## Proposals

        1. Run 10 more cards on each variant.
        MARKDOWN;

    public function __construct(
        private EntityManagerInterface $em,
        private ProjectRepository $projects,
        private AnalysisRepository $analyses,
        private DocumentRepository $documents,
        private WorkerRunRepository $workerRuns,
        private InsightsBucketRuleRepository $insightsBucketRules,
        private BucketTimeComputer $bucketTimes,
        private DevRunUsage $runUsage,
        private MarkdownRenderer $renderer,
    ) {
    }

    #[\Override]
    public function key(): string
    {
        return self::KEY;
    }

    #[\Override]
    public function dependsOn(): array
    {
        return [DevProjectSeeder::KEY, DevExperimentSeeder::KEY];
    }

    #[\Override]
    public function seed(SeedContext $context): void
    {
        $project = $this->projects->find(DevProjectSeeder::devProjectId())
            ?? throw new \LogicException('The dev project does not exist.');

        $this->seedCost($context, $project);
        $this->seedTime($context, $project);
        $this->seedExperiment($context, $project, DevExperimentSeeder::EXPERIMENT);
    }

    private function seedCost(SeedContext $context, Project $project): void
    {
        $report = $this->report($context, $project, 'Cost report: last 90 days', self::REPORT, 'cost-report', $context->now->modify('-2 days'));
        $endedAt = $context->now->modify('-2 days +20 minutes');

        $done = $this->storedAnalysis('cost-report');
        if (!$done instanceof Analysis) {
            $done = $this->finishedAnalysis($context, $project, 'cost-report', AnalysisTopic::Cost, new AnalysisScope(MetricRange::NinetyDays), $context->now->modify('-2 days'), $report, $endedAt);
            $this->em->persist(new Proposal($done, ProposalKind::Card, 'Cache the dependencies between runs', "Each run installs the dependencies again.\nA shared cache keyed on the lock file skips that step.", null, 'About $4 a week', 0));
            $dismissed = new Proposal($done, ProposalKind::Card, 'Run the lint stage on a smaller model', 'The lint stage reads short diffs, so a smaller model can do it.', null, 'About $1 a week', 1);
            $dismissed->state = ProposalState::Dismissed;
            $dismissed->dismissReason = 'The lint stage already runs on the smallest model.';
            $this->em->persist($dismissed);
        }
        $this->seedRun($context, $done, 'cost-report', $endedAt);

        if (!$this->storedAnalysis('cost-waiting') instanceof Analysis) {
            $this->em->persist($this->analysis($context, $project, 'cost-waiting', AnalysisTopic::Cost, new AnalysisScope(MetricRange::ThirtyDays), $context->now->modify('-10 minutes')));
        }
        $this->em->flush();
    }

    private function seedExperiment(SeedContext $context, Project $project, string $experiment): void
    {
        $report = $this->report($context, $project, 'Experiment report: '.$experiment, self::EXPERIMENT_REPORT, 'experiment-report-'.$experiment, $context->now->modify('-1 day'));
        $endedAt = $context->now->modify('-1 day +25 minutes');

        $analysis = $this->storedAnalysis('experiment-'.$experiment);
        if (!$analysis instanceof Analysis) {
            $analysis = $this->finishedAnalysis($context, $project, 'experiment-'.$experiment, AnalysisTopic::Experiment, new AnalysisScope(MetricRange::All, $experiment), $context->now->modify('-1 day'), $report, $endedAt);
            $this->em->persist(new Proposal($analysis, ProposalKind::Card, 'Run 10 more cards on each variant before a decision', "The cost gap is clear: $1.74 against $4.33 per merged card.\nThe merge rate and the fix rounds give no clear answer with 6 finished cards per variant.", null, null, 0));
        }
        $this->seedRun($context, $analysis, 'experiment-'.$experiment, $endedAt);
        $this->em->flush();
    }

    private function seedTime(SeedContext $context, Project $project): void
    {
        $endedAt = $context->now->modify('-1 day');
        $report = $this->report($context, $project, 'Time report: last 30 days', self::TIME_REPORT, 'time-report', $endedAt);

        $done = $this->storedAnalysis('time-report');
        if (!$done instanceof Analysis) {
            $done = $this->finishedAnalysis($context, $project, 'time-report', AnalysisTopic::Time, new AnalysisScope(MetricRange::ThirtyDays), $endedAt->modify('-1 hour'), $report, $endedAt);
            $this->em->persist(new Proposal($done, ProposalKind::BucketRule, 'Put the make calls in a bucket of their own', "The calls `make test` and `make build` take 7 minutes of an 18 minute run.\nNo rule takes them, so they count in `other`.", ['pattern' => 'make *', 'bucket' => 'build'], null, 0));

            if ([] === $this->insightsBucketRules->findOrdered($project)) {
                foreach (self::RULES as $position => [$pattern, $bucket]) {
                    $this->em->persist(new InsightsBucketRule($project, $pattern, $bucket, $position));
                }
            }
        }

        $run = $this->seedRun($context, $done, 'time-report', $endedAt);
        if ($run instanceof WorkerRun) {
            $this->seedToolCalls($run);
        }
        $this->em->flush();
        if ($run instanceof WorkerRun) {
            $this->bucketTimes->recompute($project, [$run->id ?? throw new \LogicException('A stored run has an id.')]);
        }
    }

    private function report(SeedContext $context, Project $project, string $title, string $markdown, string $slug, \DateTimeImmutable $createdAt): Document
    {
        $id = DevSeedId::of('review/document/'.$slug);
        $stored = $this->documents->find($id);
        if ($stored instanceof Document) {
            return $stored;
        }

        $report = new Document($project->owner, $project, $title, $createdAt);
        $context->ids->assign($report, $id);
        $report->addVersion($markdown, $this->renderer->render($markdown));
        $this->em->persist($report);

        return $report;
    }

    private function storedAnalysis(string $slug): ?Analysis
    {
        return $this->analyses->find(DevSeedId::of('insights/analysis/'.$slug));
    }

    private function finishedAnalysis(SeedContext $context, Project $project, string $slug, AnalysisTopic $topic, AnalysisScope $scope, \DateTimeImmutable $createdAt, Document $report, \DateTimeImmutable $endedAt): Analysis
    {
        $analysis = $this->analysis($context, $project, $slug, $topic, $scope, $createdAt);
        $this->em->persist($analysis);
        $this->em->flush();
        $analysis->start();
        $analysis->complete($report->id ?? throw new \LogicException('A stored document has an id.'), $endedAt);

        return $analysis;
    }

    private function analysis(SeedContext $context, Project $project, string $slug, AnalysisTopic $topic, AnalysisScope $scope, \DateTimeImmutable $createdAt): Analysis
    {
        $analysis = new Analysis($project, $topic, $scope, null, AnalysisSettings::DEFAULT_MODEL, AnalysisSettings::DEFAULT_EFFORT, $createdAt);
        $context->ids->assign($analysis, DevSeedId::of('insights/analysis/'.$slug));

        return $analysis;
    }

    private function seedToolCalls(WorkerRun $run): void
    {
        foreach (self::CALLS as $index => [$tool, $offset, $durationMs, $signatures]) {
            $this->em->persist(new WorkerRunToolCall(Uuid::v7(), $run, $index + 1, $tool, 'Agent' === $tool ? WorkerRunToolCallKind::Subagent : WorkerRunToolCallKind::Shell, $run->startedAt?->modify(\sprintf('+%d seconds', $offset)) ?? throw new \LogicException('A seeded run has a start.'), $durationMs, false, false, null, null, $signatures, null));
        }
    }

    /**
     * WorkerRunFactListener writes the fact row on flush, and AnalysisRepository::costOf() sums it.
     *
     * @return WorkerRun|null the new run, or null when the run is stored
     */
    private function seedRun(SeedContext $context, Analysis $analysis, string $slug, \DateTimeImmutable $endedAt): ?WorkerRun
    {
        $id = DevSeedId::of('insights/run/'.$slug);
        $stored = $this->workerRuns->find($id);
        if ($stored instanceof WorkerRun) {
            $this->recordUsage($context, $stored);

            return null;
        }

        $run = new WorkerRun(
            project: $analysis->project,
            bridgeId: Uuid::v4(),
            subjectType: Analysis::SUBJECT_TYPE,
            subjectId: $analysis->id ?? throw new \LogicException('A stored analysis has an id.'),
            cardNumber: null,
            workKind: StartAnalysisHandler::WORK_KIND,
            state: WorkerRunState::Succeeded,
            runKey: Uuid::v4(),
            startedAt: $endedAt->modify('-18 minutes'),
            endedAt: $endedAt,
            exitCode: 0,
            hasResult: true,
            output: 'The report is written and the analysis is done.',
            receivedAt: $endedAt,
        );
        $run->usageSource = WorkerRunUsageSource::Reported;
        $context->ids->assign($run, $id);
        $this->em->persist($run);
        $this->recordUsage($context, $run);

        return $run;
    }

    private function recordUsage(SeedContext $context, WorkerRun $run): void
    {
        $this->runUsage->record($context, $run, 'claude-sonnet-5-5', 182_000, 9_400, 1_250_000, 64_000, '1.840000');
    }
}
