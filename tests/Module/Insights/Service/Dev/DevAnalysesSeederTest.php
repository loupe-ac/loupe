<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Service\Dev;

use App\DevSeed\DevSeedId;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Repository\WorkerRunBucketTimeRepository;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\Repository\WorkerRunToolCallRepository;
use App\Module\Inbox\Service\Dev\DevExperimentSeeder;
use App\Module\Insights\Entity\Analysis;
use App\Module\Insights\Entity\AnalysisState;
use App\Module\Insights\Entity\AnalysisTopic;
use App\Module\Insights\Entity\ProposalKind;
use App\Module\Insights\Entity\ProposalState;
use App\Module\Insights\Repository\AnalysisRepository;
use App\Module\Insights\Repository\InsightsBucketRuleRepository;
use App\Module\Insights\Repository\ProposalRepository;
use App\Module\Insights\Service\Dev\DevAnalysesSeeder;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use App\Module\Review\Repository\DocumentRepository;
use App\Tests\DevSeed\DevProjectFixture;
use App\Tests\DevSeed\SeedContextFactory;
use App\Tests\Module\Insights\InsightsScenario;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DevAnalysesSeederTest extends KernelTestCase
{
    use InsightsScenario;

    private const string EXPERIMENT = DevExperimentSeeder::EXPERIMENT;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function test_it_seeds_the_cost_time_and_experiment_analyses_with_rules_and_bucket_times(): void
    {
        $project = $this->seeded();

        $analyses = $this->service(AnalysisRepository::class)->findByProject($project);
        self::assertCount(4, $analyses);
        $time = array_values(array_filter($analyses, static fn ($analysis): bool => AnalysisTopic::Time === $analysis->topic));
        self::assertCount(1, $time);
        self::assertSame(AnalysisState::Done, $time[0]->state);
        self::assertNotNull($time[0]->documentId);
        self::assertCount(1, array_filter($analyses, static fn ($analysis): bool => AnalysisTopic::Experiment === $analysis->topic && self::EXPERIMENT === $analysis->scope->experiment));

        $proposals = $this->service(ProposalRepository::class)->findByAnalysis($time[0]);
        self::assertCount(1, $proposals);
        self::assertSame(ProposalKind::BucketRule, $proposals[0]->kind);
        self::assertSame(ProposalState::Proposed, $proposals[0]->state);
        self::assertSame(['pattern' => 'make *', 'bucket' => 'build'], $proposals[0]->payload);

        $rules = $this->service(InsightsBucketRuleRepository::class)->findOrdered($project);
        self::assertSame(['git *', 'grep', 'Agent'], array_map(static fn ($rule): string => $rule->pattern, $rules));

        $names = $this->service(WorkerRunBucketTimeRepository::class)->findBucketNamesOfProject($project);
        sort($names);
        self::assertSame(['git', 'other', 'search', 'subagent'], $names);
    }

    public function test_a_second_run_adds_nothing(): void
    {
        $project = $this->seeded();

        $this->service(DevAnalysesSeeder::class)->seed(SeedContextFactory::create(self::getContainer()));

        $this->em()->clear();
        self::assertCount(4, $this->service(AnalysisRepository::class)->findByProject($project));
        self::assertCount(3, $this->service(InsightsBucketRuleRepository::class)->findOrdered($project));
    }

    public function test_the_analyses_and_their_reports_and_runs_have_fixed_ids(): void
    {
        $this->seeded();

        $analyses = $this->service(AnalysisRepository::class);
        foreach (['cost-report', 'cost-waiting', 'time-report', 'experiment-'.self::EXPERIMENT] as $slug) {
            self::assertNotNull($analyses->find(DevSeedId::of('insights/analysis/'.$slug)), $slug);
        }
        self::assertNotNull($this->service(WorkerRunRepository::class)->find(DevSeedId::of('insights/run/time-report')));
        self::assertSame(DevSeedId::of('review/document/time-report')->toRfc4122(), $analyses->find(DevSeedId::of('insights/analysis/time-report'))?->documentId?->toRfc4122());
    }

    #[TestWith(['cost-report', 2])]
    #[TestWith(['time-report', 1])]
    #[TestWith(['experiment-implement', 1])]
    public function test_a_deleted_analysis_comes_back_with_its_proposals_and_writes_no_second_report_or_run(string $slug, int $proposals): void
    {
        $project = $this->seeded();
        $counts = $this->counts($project);
        $id = DevSeedId::of('insights/analysis/'.$slug);
        $this->em()->remove($this->em()->find(Analysis::class, $id) ?? throw new \LogicException('The analysis is missing.'));
        $this->em()->flush();
        $this->em()->clear();

        $this->service(DevAnalysesSeeder::class)->seed(SeedContextFactory::create(self::getContainer()));

        $this->em()->clear();
        self::assertSame($counts, $this->counts($project));
        $analysis = $this->em()->find(Analysis::class, $id) ?? throw new \LogicException('The analysis did not come back.');
        self::assertSame(AnalysisState::Done, $analysis->state);
        self::assertCount($proposals, $this->service(ProposalRepository::class)->findByAnalysis($analysis));
    }

    #[TestWith(['cost-report', 'cost-report', 2])]
    #[TestWith(['time-report', 'time-report', 1])]
    #[TestWith(['experiment-report-implement', 'experiment-implement', 1])]
    public function test_a_deleted_report_comes_back_while_its_analysis_stays(string $reportSlug, string $analysisSlug, int $proposals): void
    {
        $project = $this->seeded();
        $counts = $this->counts($project);
        $id = DevSeedId::of('review/document/'.$reportSlug);
        $this->em()->remove($this->em()->find(Document::class, $id) ?? throw new \LogicException('The report is missing.'));
        $this->em()->flush();
        $this->em()->clear();
        self::assertSame($counts['documents'] - 1, $this->counts($project)['documents']);

        $this->service(DevAnalysesSeeder::class)->seed(SeedContextFactory::create(self::getContainer()));

        $this->em()->clear();
        self::assertSame($counts, $this->counts($project));
        $analysis = $this->em()->find(Analysis::class, DevSeedId::of('insights/analysis/'.$analysisSlug)) ?? throw new \LogicException('The analysis is missing.');
        self::assertSame($id->toRfc4122(), $analysis->documentId?->toRfc4122());
        self::assertCount($proposals, $this->service(ProposalRepository::class)->findByAnalysis($analysis));
    }

    public function test_a_deleted_run_comes_back_with_its_tool_calls_while_its_analysis_stays(): void
    {
        $project = $this->seeded();
        $counts = $this->counts($project);
        $id = DevSeedId::of('insights/run/time-report');
        $this->em()->remove($this->em()->find(WorkerRun::class, $id) ?? throw new \LogicException('The run is missing.'));
        $this->em()->flush();
        $this->em()->clear();
        self::assertSame($counts['runs'] - 1, $this->counts($project)['runs']);

        $this->service(DevAnalysesSeeder::class)->seed(SeedContextFactory::create(self::getContainer()));

        $this->em()->clear();
        self::assertSame($counts, $this->counts($project));
        $run = $this->em()->find(WorkerRun::class, $id) ?? throw new \LogicException('The run did not come back.');
        self::assertCount(6, $this->service(WorkerRunToolCallRepository::class)->findBy(['run' => $run]));
    }

    public function test_a_deleted_waiting_analysis_comes_back_alone(): void
    {
        $project = $this->seeded();
        $counts = $this->counts($project);
        $id = DevSeedId::of('insights/analysis/cost-waiting');
        $this->em()->remove($this->em()->find(Analysis::class, $id) ?? throw new \LogicException('The analysis is missing.'));
        $this->em()->flush();
        $this->em()->clear();

        $this->service(DevAnalysesSeeder::class)->seed(SeedContextFactory::create(self::getContainer()));

        $this->em()->clear();
        self::assertSame($counts, $this->counts($project));
        self::assertNotNull($this->em()->find(Analysis::class, $id));
    }

    /** @return array{analyses: int, documents: int, runs: int, rules: int} */
    private function counts(Project $project): array
    {
        return [
            'analyses' => \count($this->service(AnalysisRepository::class)->findByProject($project)),
            'documents' => $this->service(DocumentRepository::class)->count(['project' => $project->id]),
            'runs' => $this->service(WorkerRunRepository::class)->count(['project' => $project->id]),
            'rules' => \count($this->service(InsightsBucketRuleRepository::class)->findOrdered($project)),
        ];
    }

    private function seeded(): Project
    {
        $context = SeedContextFactory::create(self::getContainer());
        $project = DevProjectFixture::create(self::getContainer(), $context);
        $this->service(DevExperimentSeeder::class)->seed($context);
        $this->service(DevAnalysesSeeder::class)->seed($context);
        $this->em()->clear();

        return $project;
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
