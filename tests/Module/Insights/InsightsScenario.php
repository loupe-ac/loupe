<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Metric\Formula\MetricFormula;
use App\Module\Bridge\Metric\MetricBucket;
use App\Module\Bridge\Metric\MetricCompare;
use App\Module\Bridge\Metric\MetricGroup;
use App\Module\Bridge\Metric\MetricRange;
use App\Module\Bridge\Metric\MetricStatistic;
use App\Module\Bridge\Metric\MetricUnit;
use App\Module\Insights\Entity\Analysis;
use App\Module\Insights\Entity\AnalysisScope;
use App\Module\Insights\Entity\AnalysisState;
use App\Module\Insights\Entity\AnalysisTopic;
use App\Module\Insights\Entity\InsightsSavedGraph;
use App\Module\Insights\Entity\Proposal;
use App\Module\Insights\Entity\ProposalKind;
use App\Module\Insights\Entity\PublicLinkTakedown;
use App\Module\Insights\Entity\SavedGraphWidth;
use App\Module\Insights\View\MetricChartType;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use App\Tests\Module\Bridge\BridgeScenario;
use Doctrine\ORM\EntityManagerInterface;

/** Fixtures the Insights module's database tests share. Each helper flushes. */
trait InsightsScenario
{
    use BridgeScenario;

    private function scenarioProject(string $name): Project
    {
        $em = $this->em();

        return $this->project($em, $this->user($em, $name.'-'.uniqid().'@example.com'), 'Project '.$name);
    }

    /** A card run that names the experiment, so the project lists it. Every run shares one receive time, so the list orders by name. */
    private function seedExperiment(Project $project, string $experiment): void
    {
        $em = $this->em();
        $run = $this->seedRun($em, $project, new \DateTimeImmutable('2026-01-01 10:00:00'));
        $run->experiment = $experiment;
        $em->flush();
    }

    private function seedAnalysis(EntityManagerInterface $em, Project $project, AnalysisState $state = AnalysisState::Waiting): Analysis
    {
        $analysis = new Analysis($project, AnalysisTopic::Cost, new AnalysisScope(MetricRange::NinetyDays), null, 'sonnet', 'medium', new \DateTimeImmutable('2026-10-07 09:00:00'));
        $analysis->state = $state;
        $em->persist($analysis);
        $em->flush();

        return $analysis;
    }

    /** @param array<mixed>|null $payload */
    private function seedProposal(EntityManagerInterface $em, Analysis $analysis, ProposalKind $kind = ProposalKind::Card, int $position = 0, ?array $payload = null): Proposal
    {
        $proposal = new Proposal($analysis, $kind, 'Cache the dependencies', 'Each run installs them again.', $payload, 'About $4 a week', $position);
        $em->persist($proposal);
        $em->flush();

        return $proposal;
    }

    private function seedSavedGraph(Project $project, string $name = 'Tests', int $position = 0, ?\DateTimeImmutable $removedAt = null): InsightsSavedGraph
    {
        $em = $this->em();
        $graph = new InsightsSavedGraph(
            $project,
            $name,
            'bucket-time:tests',
            MetricUnit::Run,
            MetricStatistic::Median,
            MetricGroup::None,
            MetricRange::ThirtyDays,
            MetricBucket::Day,
            MetricCompare::Off,
            MetricChartType::Line,
            SavedGraphWidth::Half,
            true,
            $position,
            removedAt: $removedAt,
        );
        $em->persist($graph);
        $em->flush();

        return $graph;
    }

    /** A formula graph on the first row of its rows, as the handlers store it. */
    private function seedFormulaGraph(Project $project, string $rows = 'A:cache-read-tokens:sum,B:cache-write-tokens:sum', string $formula = 'A / B', string $format = 'auto', string $name = 'Ratio', int $position = 0): InsightsSavedGraph
    {
        $first = MetricFormula::rowParts($rows)[0] ?? throw new \LogicException('A formula graph has a row.');
        $graph = new InsightsSavedGraph(
            $project,
            $name,
            $first['metric'],
            MetricUnit::Card,
            MetricStatistic::from($first['statistic']),
            MetricGroup::None,
            MetricRange::ThirtyDays,
            MetricBucket::Week,
            MetricCompare::Off,
            MetricChartType::Line,
            SavedGraphWidth::Half,
            true,
            $position,
            formulaRows: $rows,
            formula: $formula,
            format: $format,
        );
        $this->em()->persist($graph);
        $this->em()->flush();

        return $graph;
    }

    private function seedTakedown(Project $project, ?User $admin = null): PublicLinkTakedown
    {
        $takedown = new PublicLinkTakedown($project, $admin);
        $this->em()->persist($takedown);
        $this->em()->flush();

        return $takedown;
    }

    private function seedDocument(EntityManagerInterface $em, Project $project): Document
    {
        $document = new Document($project->owner, $project, 'Cost report');
        $em->persist($document);
        $em->flush();

        return $document;
    }
}
