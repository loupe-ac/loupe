<?php

declare(strict_types=1);

namespace App\Module\Insights\Service\Dev;

use App\DevSeed\DevSeederInterface;
use App\DevSeed\DevSeedId;
use App\DevSeed\SeedContext;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkerRunToolCall;
use App\Module\Bridge\Entity\WorkerRunUsage;
use App\Module\Bridge\Metric\MetricRange;
use App\Module\Bridge\Service\BucketTimeComputer;
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
use App\Module\Review\Service\MarkdownRenderer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\Uid\Uuid;

/**
 * Writes a finished cost analysis with its report, two proposals and a costed
 * run, a waiting one, and a finished experiment analysis, so the Reports page
 * of a dev project shows each state. It also writes three time bucket rules, a
 * finished time analysis whose run holds tool calls, and one bucket rule
 * proposal. Each part runs only when its analysis is missing. The waiting analysis
 * opens no work request, so no bridge claims it.
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
        private InsightsBucketRuleRepository $insightsBucketRules,
        private BucketTimeComputer $bucketTimes,
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
        $experiment = DevExperimentSeeder::EXPERIMENT;

        if (!$this->analyses->find(DevSeedId::of('insights/analysis/cost-report')) instanceof Analysis) {
            $this->seedCost($context, $project);
        }
        if (!$this->analyses->find(DevSeedId::of('insights/analysis/time-report')) instanceof Analysis) {
            $this->seedTime($context, $project);
        }
        if (!$this->analyses->find(DevSeedId::of('insights/analysis/experiment-'.$experiment)) instanceof Analysis) {
            $this->seedExperiment($context, $project, $experiment);
        }
    }

    private function seedCost(SeedContext $context, Project $project): void
    {
        $report = $this->report($context, $project, 'Cost report: last 90 days', self::REPORT, 'cost-report', $context->now->modify('-2 days'));

        $done = $this->analysis($context, $project, 'cost-report', AnalysisTopic::Cost, new AnalysisScope(MetricRange::NinetyDays), $context->now->modify('-2 days'));
        $this->em->persist($done);
        $this->em->flush();
        $done->start();
        $endedAt = $context->now->modify('-2 days +20 minutes');
        $done->complete($report->id ?? throw new \LogicException('A stored document has an id.'), $endedAt);
        $this->seedRun($context, $done, 'cost-report', $endedAt);

        $this->em->persist(new Proposal($done, ProposalKind::Card, 'Cache the dependencies between runs', "Each run installs the dependencies again.\nA shared cache keyed on the lock file skips that step.", null, 'About $4 a week', 0));
        $dismissed = new Proposal($done, ProposalKind::Card, 'Run the lint stage on a smaller model', 'The lint stage reads short diffs, so a smaller model can do it.', null, 'About $1 a week', 1);
        $dismissed->state = ProposalState::Dismissed;
        $dismissed->dismissReason = 'The lint stage already runs on the smallest model.';
        $this->em->persist($dismissed);

        $this->em->persist($this->analysis($context, $project, 'cost-waiting', AnalysisTopic::Cost, new AnalysisScope(MetricRange::ThirtyDays), $context->now->modify('-10 minutes')));
        $this->em->flush();
    }

    private function seedExperiment(SeedContext $context, Project $project, string $experiment): void
    {
        $report = $this->report($context, $project, 'Experiment report: '.$experiment, self::EXPERIMENT_REPORT, 'experiment-report-'.$experiment, $context->now->modify('-1 day'));

        $analysis = $this->analysis($context, $project, 'experiment-'.$experiment, AnalysisTopic::Experiment, new AnalysisScope(MetricRange::All, $experiment), $context->now->modify('-1 day'));
        $this->em->persist($analysis);
        $this->em->flush();
        $analysis->start();
        $endedAt = $context->now->modify('-1 day +25 minutes');
        $analysis->complete($report->id ?? throw new \LogicException('A stored document has an id.'), $endedAt);
        $this->seedRun($context, $analysis, 'experiment-'.$experiment, $endedAt);
        $this->em->persist(new Proposal($analysis, ProposalKind::Card, 'Run 10 more cards on each variant before a decision', "The cost gap is clear: $1.74 against $4.33 per merged card.\nThe merge rate and the fix rounds give no clear answer with 6 finished cards per variant.", null, null, 0));
        $this->em->flush();
    }

    private function seedTime(SeedContext $context, Project $project): void
    {
        $endedAt = $context->now->modify('-1 day');
        $report = $this->report($context, $project, 'Time report: last 30 days', self::TIME_REPORT, 'time-report', $endedAt);

        $done = $this->analysis($context, $project, 'time-report', AnalysisTopic::Time, new AnalysisScope(MetricRange::ThirtyDays), $endedAt->modify('-1 hour'));
        $this->em->persist($done);
        $this->em->flush();
        $done->start();
        $done->complete($report->id ?? throw new \LogicException('A stored document has an id.'), $endedAt);
        $this->em->persist(new Proposal($done, ProposalKind::BucketRule, 'Put the make calls in a bucket of their own', "The calls `make test` and `make build` take 7 minutes of an 18 minute run.\nNo rule takes them, so they count in `other`.", ['pattern' => 'make *', 'bucket' => 'build'], null, 0));

        if ([] === $this->insightsBucketRules->findOrdered($project)) {
            foreach (self::RULES as $position => [$pattern, $bucket]) {
                $this->em->persist(new InsightsBucketRule($project, $pattern, $bucket, $position));
            }
        }

        $run = $this->seedRun($context, $done, 'time-report', $endedAt);
        $this->seedToolCalls($run);
        $this->em->flush();
        $this->bucketTimes->recompute($project, [$run->id ?? throw new \LogicException('A stored run has an id.')]);
    }

    private function report(SeedContext $context, Project $project, string $title, string $markdown, string $slug, \DateTimeImmutable $createdAt): Document
    {
        $report = new Document($project->owner, $project, $title, $createdAt);
        $context->ids->assign($report, DevSeedId::of('review/document/'.$slug));
        $report->addVersion($markdown, $this->renderer->render($markdown));
        $this->em->persist($report);

        return $report;
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

    /** WorkerRunFactListener writes the fact row on flush, and AnalysisRepository::costOf() sums it. */
    private function seedRun(SeedContext $context, Analysis $analysis, string $slug, \DateTimeImmutable $endedAt): WorkerRun
    {
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
        $context->ids->assign($run, DevSeedId::of('insights/run/'.$slug));
        $this->em->persist($run);
        $this->em->persist(new WorkerRunUsage($run, $analysis->project, $run->subjectType, $run->subjectId, $run->workKind, 'claude-sonnet-5-5', WorkerRunUsageSource::Reported, 182_000, 9_400, 1_250_000, 64_000, '1.840000'));

        return $run;
    }
}
