<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Service;

use App\Module\Bridge\Experiment\Confidence;
use App\Module\Bridge\Experiment\ExperimentArm;
use App\Module\Bridge\Experiment\ExperimentComparison;
use App\Module\Bridge\Experiment\ExperimentGoal;
use App\Module\Bridge\Experiment\ExperimentHeadline;
use App\Module\Bridge\Experiment\ExperimentMetric;
use App\Module\Bridge\Experiment\ExperimentVariant;
use App\Module\Bridge\Experiment\GoalDirection;
use App\Module\Bridge\Experiment\GoalState;
use App\Module\Insights\Service\PublicLinkDescription;
use App\Module\Insights\View\PublicExperimentResult;
use App\Tests\Module\Insights\InsightsScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PublicLinkDescriptionTest extends KernelTestCase
{
    use InsightsScenario;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function test_a_graph_prints_its_statistic_and_a_formula_graph_prints_its_formula_in_words(): void
    {
        $project = $this->scenarioProject('description-graph');
        $plain = $this->seedSavedGraph($project);
        $plain->metric = 'cost';
        $formula = $this->seedFormulaGraph($project, 'A:cache-read-tokens:mean,B:cache-write-tokens:sum', 'A / B');

        self::assertSame('Per run · Median · 30 days · Per day · Line', $this->description()->forGraph($plain));
        self::assertSame('Per finished card · Cache read tokens (mean) / Cache write tokens · 30 days · Per week · Line', $this->description()->forGraph($formula));
    }

    public function test_a_note_is_stripped_of_tags_and_its_spaces_are_folded(): void
    {
        $html = "<h2>Why</h2>\n<p>Two   models &amp; a <strong>baseline</strong>.</p>\n<ul><li>one</li><li>two</li></ul>";

        $text = $this->description()->forExperiment($this->experiment(null), $html, 'Alpha');

        self::assertSame('Why Two models & a baseline . one two', $text);
    }

    public function test_a_long_note_is_cut_at_a_word_near_200_characters(): void
    {
        $note = '<p>'.implode(' ', array_fill(0, 60, 'wordy')).'</p>';

        $text = $this->description()->forExperiment($this->experiment(null), $note, 'Alpha');

        self::assertLessThanOrEqual(PublicLinkDescription::NOTE_LENGTH, mb_strlen($text));
        self::assertGreaterThan(PublicLinkDescription::NOTE_LENGTH - 10, mb_strlen($text));
        self::assertStringEndsWith('wordy…', $text);
    }

    public function test_a_note_of_200_characters_or_less_is_not_cut(): void
    {
        $note = str_repeat('a', 198).' b';

        self::assertSame($note, $this->description()->forExperiment($this->experiment(null), '<p>'.$note.'</p>', 'Alpha'));
    }

    public function test_a_note_with_no_space_is_cut_at_the_limit(): void
    {
        $text = $this->description()->forExperiment($this->experiment(null), str_repeat('a', 400), 'Alpha');

        self::assertSame(PublicLinkDescription::NOTE_LENGTH, mb_strlen($text));
        self::assertStringEndsWith('…', $text);
    }

    public function test_no_note_and_a_note_that_is_empty_after_the_strip_give_the_goal_line(): void
    {
        $result = $this->experiment($this->headline('merge-rate', 'no-lsp', ['a', 'b', 'c']), ['no-lsp', 'a', 'b', 'c']);
        $expected = 'Merge rate, 3 variants against no-lsp';

        self::assertSame($expected, $this->description()->forExperiment($result, null, 'Alpha'));
        self::assertSame($expected, $this->description()->forExperiment($result, "<p>\u{00A0} </p><img src=\"x.png\">", 'Alpha'));
    }

    public function test_one_variant_reads_in_the_singular(): void
    {
        $result = $this->experiment($this->headline('merge-rate', 'base', ['a']), ['base', 'a']);

        self::assertSame('Merge rate, 1 variant against base', $this->description()->forExperiment($result, null, 'Alpha'));
    }

    public function test_an_experiment_with_no_baseline_names_its_variants_only(): void
    {
        $result = $this->experiment($this->headline('merge-rate', null, []), ['a', 'b']);

        self::assertSame('Merge rate, 2 variants', $this->description()->forExperiment($result, null, 'Alpha'));
    }

    public function test_a_goal_that_is_not_a_metric_names_the_default_goal(): void
    {
        $result = $this->experiment($this->headline('hours-to-mrege', 'base', ['a'], GoalState::UnknownMetric), ['base', 'a']);

        self::assertSame('Cost per merged card, 1 variant against base', $this->description()->forExperiment($result, null, 'Alpha'));
    }

    public function test_an_experiment_with_no_headline_and_no_note_names_its_project(): void
    {
        self::assertSame('A shared experiment of the project Alpha.', $this->description()->forExperiment($this->experiment(null), null, 'Alpha'));
    }

    private function description(): PublicLinkDescription
    {
        $description = self::getContainer()->get(PublicLinkDescription::class);
        self::assertInstanceOf(PublicLinkDescription::class, $description);

        return $description;
    }

    /** @param list<string> $variants */
    private function experiment(?ExperimentHeadline $headline, array $variants = []): PublicExperimentResult
    {
        return new PublicExperimentResult(
            new \DateTimeImmutable('2026-11-01'),
            'trial',
            $headline,
            array_map(static fn (string $name): ExperimentVariant => new ExperimentVariant($name, [], 1, 0, 0, 0, null, null), $variants),
            [],
        );
    }

    /** @param list<string> $others */
    private function headline(string $metric, ?string $baseline, array $others, ?GoalState $problem = null): ExperimentHeadline
    {
        return new ExperimentHeadline(
            new ExperimentGoal(new ExperimentMetric($metric, [], null), GoalDirection::Higher, $problem),
            $baseline,
            new Confidence(0.95),
            null === $baseline ? null : new ExperimentArm($baseline, 1.0, 6),
            array_map(static fn (string $name): ExperimentComparison => new ExperimentComparison($name, GoalState::Clear, new ExperimentArm($name, 1.0, 6), true, null, null, []), $others),
            [],
        );
    }
}
