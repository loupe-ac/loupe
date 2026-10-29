<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Controller;

use App\Module\Bridge\Entity\ExperimentDefinition;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Bridge\ExperimentScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;

final class ShowExperimentControllerTest extends WebTestCase
{
    use ExperimentScenario;

    public function test_the_owner_compares_the_variants_on_each_metric(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'experiment-owner@example.com');
        $project = $this->boardProject($em, $owner);
        $this->bindLifecycle($em, $project);
        $definition = new ExperimentDefinition($project, 'model-test', [['name' => 'a', 'weight' => 3], ['name' => 'b', 'weight' => 1]]);
        $definition->baseline = 'a';
        $em->persist($definition);
        // Every run of a fails and every run of b succeeds, so the stop rate gives a clear answer.
        for ($i = 1; $i <= 5; ++$i) {
            $card = $this->experimentCard($em, $project, $i, merged: true);
            $this->seedUsage($em, $this->experimentRun($em, $project, $card, 'a', state: WorkerRunState::Failed), costUsd: '2.000000');
            if (1 === $i) {
                $this->fixRequest($em, $project, $card, 'conflict');
            }
            if (2 === $i) {
                $this->fixRequest($em, $project, $card, 'rebase-needed');
            }
            $card = $this->experimentCard($em, $project, $i + 5, merged: true);
            $this->seedUsage($em, $this->experimentRun($em, $project, $card, 'b'), costUsd: '1.000000');
        }
        $switched = $this->experimentCard($em, $project, 11);
        $this->experimentRun($em, $project, $switched, 'b', switchedFrom: 'a');
        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/analytics/experiments/model-test');

        self::assertResponseIsSuccessful();
        self::assertSame('Experiments', trim($crawler->filter('.lp-analytics-tabs [aria-current="page"]')->text()));
        self::assertSame('Comparison', trim($crawler->filter('.lp-experiment-tabs [aria-current="page"]')->text()));
        self::assertCount(4, $crawler->filter('.lp-experiment-table.lp-table-cue[data-controller="overflow-cue"] > .lp-experiment-table__scroll[data-overflow-cue-target="viewport"] > table'));
        self::assertCount(8, $crawler->filter('.lp-experiment-table > .lp-overflow-cue__arrow'));
        self::assertSame(['Variant', 'Model', 'Weight', 'Cards', 'Finished', 'Runs', 'Covered cost', 'Cost'], $this->cells($crawler->filter('[data-experiment-variants] thead tr')));
        self::assertSame(['a Baseline', 'model-a', '3', '5', '5', '5', '$10.00', '$10.00'], $this->cells($crawler->filter('[data-experiment-variant="a"]')));
        self::assertCount(1, $crawler->filter('[data-experiment-variants] .lp-tag--neutral'));
        self::assertSame(['b', 'model-b', '1', '5', '5', '5', '$5.00', '$5.00'], $this->cells($crawler->filter('[data-experiment-variant="b"]')));

        self::assertSame('Goal · Cost per merged card · lower is better', trim($crawler->filter('[data-experiment-goal-heading]')->text()));
        self::assertSame(['Variant', 'Cost per merged card', 'Change against a', 'Likely range of the change', 'Result'], $this->cells($crawler->filter('[data-experiment-goal] thead tr')));
        self::assertSame(['a Baseline', '$2.00', '–', '–', ''], $this->cells($crawler->filter('[data-experiment-goal-row="a"]')));
        self::assertSame(['b', '$1.00', '50% cheaper', '50% to 50% lower', 'Clear'], $this->cells($crawler->filter('[data-experiment-goal-row="b"]')));
        self::assertSame('clear', $crawler->filter('[data-experiment-goal-row="b"]')->attr('data-experiment-goal-state'));
        self::assertSame('Baseline', trim($crawler->filter('[data-experiment-goal-baseline] .lp-tag--neutral')->text()));
        self::assertCount(1, $crawler->filter('[data-experiment-goal-row="b"] .lp-status-chip--ok'));
        self::assertCount(0, $crawler->filter('[data-experiment-goal-widened]'));
        self::assertStringContainsString('holds the true value 95 times in 100. A metric is clear', trim($crawler->filter('[data-experiment-metrics-note]')->text()));

        self::assertSame(['a', 'Likely range', 'b', 'Likely range'], \array_slice($this->cells($crawler->filter('[data-experiment-metrics] thead tr')), 1));
        self::assertStringStartsWith('Fix rounds', $this->cells($crawler->filter('[data-metric="fix-rounds"]'))[0]);
        self::assertStringStartsWith('Merge rate', $this->cells($crawler->filter('[data-metric="merge-rate"]'))[0]);
        self::assertSame(['Cost per merged card', '$2.00', '$2.00–$2.00', '$1.00', '$1.00–$1.00'], $this->cells($crawler->filter('[data-metric="cost"]')));
        self::assertSame('100%', $this->cells($crawler->filter('[data-metric="merge-rate"]'))[1]);
        self::assertSame('100%', $this->cells($crawler->filter('[data-metric="stop-rate"]'))[1]);
        self::assertSame('0%', $this->cells($crawler->filter('[data-metric="stop-rate"]'))[3]);
        self::assertCount(0, $crawler->filter('[data-metric="stop-rate"] [data-metric-unclear]'));
        self::assertSame('Too few cards', trim($crawler->filter('[data-metric="hours-to-merge"] [data-metric-unclear]')->text()));
        // No card has a pull request, so no variant has an hour to show.
        self::assertSame(['–', '–', '–', '–'], \array_slice($this->cells($crawler->filter('[data-metric="hours-to-merge"]')), 1));
        self::assertStringStartsWith('Conflict', $this->cells($crawler->filter('[data-metric-part="conflict"]'))[0]);
        self::assertSame('0.2', $this->cells($crawler->filter('[data-metric-part="conflict"]'))[1]);
        self::assertStringStartsWith('Other reason: rebase-needed', $this->cells($crawler->filter('[data-metric-part="rebase-needed"]'))[0]);

        $leftOut = $crawler->filter('[data-experiment-left-out]');
        self::assertStringContainsString('1 card is left out', $leftOut->text());
        self::assertSame('/projects/'.$projectId.'/analytics/experiments/model-test/cards?left-out=1', $leftOut->filter('a')->attr('href'));

        $analyse = $crawler->filter('[data-experiment-analyse]');
        self::assertSame('Analyse this experiment', trim($analyse->text()));
        self::assertSame('/projects/'.$projectId.'/analytics/reports?topic=experiment&experiment=model-test', $analyse->attr('href'));
    }

    public function test_the_table_shows_the_declared_metrics_and_names_the_unknown_ones(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'experiment-declared@example.com');
        $project = $this->boardProject($em, $owner);
        $definition = new ExperimentDefinition($project, 'model-test', [['name' => 'a', 'weight' => 1]]);
        $definition->metrics = ['duration', 'foo', 'input-tokens', 'bar'];
        $em->persist($definition);
        $this->seedUsage($em, $this->experimentRun($em, $project, $this->experimentCard($em, $project, 1, merged: true), 'a'), costUsd: '1.000000', inputTokens: 1234);
        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/analytics/experiments/model-test');

        self::assertResponseIsSuccessful();
        self::assertSame(['duration', 'input-tokens'], $crawler->filter('[data-metric]')->each(static fn (Crawler $row): ?string => $row->attr('data-metric')));
        $duration = $this->cells($crawler->filter('[data-metric="duration"]'));
        self::assertStringStartsWith('Run time per merged card (minutes)', $duration[0]);
        self::assertSame(['5.0', '5.0–5.0'], \array_slice($duration, 1));
        $inputTokens = $this->cells($crawler->filter('[data-metric="input-tokens"]'));
        self::assertStringStartsWith('Input tokens per merged card', $inputTokens[0]);
        self::assertSame(['1,234', '1,234–1,234'], \array_slice($inputTokens, 1));
        self::assertSame('The bridge rule declares metrics that this server does not know: foo, bar.', trim($crawler->filter('[data-experiment-unknown-metrics]')->text()));
        self::assertSame(['foo', 'bar'], $crawler->filter('[data-experiment-unknown-metrics] code.lp-experiment-config-key')->each(static fn ($node) => $node->text()));
    }

    public function test_a_variant_with_no_usage_shows_no_cost(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'experiment-no-usage@example.com');
        $project = $this->boardProject($em, $owner);
        $this->seedUsage($em, $this->experimentRun($em, $project, $this->experimentCard($em, $project, 1), 'a'), costUsd: '1.000000');
        $this->experimentRun($em, $project, $this->experimentCard($em, $project, 2), 'b');
        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/analytics/experiments/model-test');

        self::assertResponseIsSuccessful();
        self::assertSame('$1.00', $this->cells($crawler->filter('[data-experiment-variant="a"]'))[6]);
        self::assertSame('–', $this->cells($crawler->filter('[data-experiment-variant="b"]'))[6]);
    }

    public function test_an_unknown_experiment_is_not_found(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'experiment-unknown@example.com');
        $project = $this->boardProject($em, $owner);
        $this->experimentRun($em, $project, $this->experimentCard($em, $project, 1), 'a');
        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/analytics/experiments/another-test');

        self::assertResponseStatusCodeSame(404);
    }

    public function test_another_users_project_is_refused(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'experiment-theirs@example.com');
        $stranger = $this->user($em, 'experiment-stranger@example.com');
        $project = $this->boardProject($em, $owner);
        $this->experimentRun($em, $project, $this->experimentCard($em, $project, 1), 'a');
        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($stranger);
        $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/analytics/experiments/model-test');

        self::assertResponseStatusCodeSame(403);
    }

    public function test_the_goal_is_not_clear_yet_when_the_ranges_overlap(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'experiment-not-clear@example.com');
        $project = $this->boardProject($em, $owner);
        $this->nameBaseline($em, $project);
        $this->seedCosts($em, $project, 'a', ['1.000000', '2.000000', '3.000000', '4.000000', '5.000000'], 1);
        $this->seedCosts($em, $project, 'b', ['1.000000', '1.000000', '2.000000', '3.000000', '3.000000'], 6);
        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/analytics/experiments/model-test');

        self::assertResponseIsSuccessful();
        $row = $crawler->filter('[data-experiment-goal-row="b"]');
        self::assertSame('not-clear', $row->attr('data-experiment-goal-state'));
        $cells = $this->cells($row);
        self::assertSame(['b', '$2.00', '33% cheaper'], \array_slice($cells, 0, 3));
        self::assertMatchesRegularExpression('/^\d+% lower to \d+% higher$/', $cells[3]);
        self::assertSame('Not clear yet', $cells[4]);
        self::assertCount(1, $row->filter('.lp-status-chip--pending'));
        self::assertCount(0, $row->filter('.lp-status-chip--ok'));
    }

    public function test_the_goal_says_how_many_cards_are_missing(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'experiment-few-cards@example.com');
        $project = $this->boardProject($em, $owner);
        $this->nameBaseline($em, $project);
        $this->seedCosts($em, $project, 'a', ['1.000000', '1.000000', '1.000000'], 1);
        $this->seedCosts($em, $project, 'b', ['1.000000', '1.000000', '1.000000', '1.000000', '1.000000', '1.000000'], 4);
        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/analytics/experiments/model-test');

        self::assertResponseIsSuccessful();
        $row = $crawler->filter('[data-experiment-goal-row="b"]');
        self::assertSame('not-enough-cards', $row->attr('data-experiment-goal-state'));
        self::assertSame(['b', '$1.00', '–', '–', 'a needs 2 more cards'], $this->cells($row));
        self::assertCount(1, $row->filter('.lp-status-chip--neutral'));
    }

    public function test_the_guards_table_follows_the_goal_before_the_variants(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'experiment-guards@example.com');
        $project = $this->boardProject($em, $owner);
        $this->nameBaseline($em, $project);
        $this->bindLifecycle($em, $project);
        $this->seedCosts($em, $project, 'a', ['2.000000', '2.000000', '2.000000', '2.000000', '2.000000'], 1);
        $this->seedCosts($em, $project, 'b', ['1.000000', '1.000000', '1.000000', '1.000000', '1.000000'], 6);
        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/analytics/experiments/model-test');

        self::assertResponseIsSuccessful();
        self::assertSame(['Goal · Cost per merged card · lower is better', 'Guards', 'Variants', 'Metrics'], $crawler->filter('h3.lp-experiment-section')->each(static fn (Crawler $node): string => trim($node->text())));
        self::assertSame(['Guard', 'a (baseline)', 'b', 'Change, likely range'], $this->cells($crawler->filter('[data-experiment-guards] thead tr')));
        $mergeRate = $this->cells($crawler->filter('[data-experiment-guard="merge-rate"]'));
        self::assertSame(['Merge rate', '100%', '100%'], \array_slice($mergeRate, 0, 3));
        self::assertMatchesRegularExpression('/^(\x{2212}\d+|0|\+\d+) pp \((\x{2212}\d+|0|\+\d+) to (\x{2212}\d+|0|\+\d+)\)$/u', $mergeRate[3]);
        $fixRounds = $this->cells($crawler->filter('[data-experiment-guard="fix-rounds"]'));
        self::assertSame(['Fix rounds', '0', '0', '0 (0 to 0)'], $fixRounds);
        self::assertCount(1, $crawler->filter('.lp-experiment-table.lp-table-cue[data-controller="overflow-cue"] > .lp-experiment-table__scroll > table[data-experiment-guards]'));
    }

    public function test_a_project_without_fix_rounds_guards_the_merge_rate_alone(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'experiment-no-guards@example.com');
        $project = $this->boardProject($em, $owner);
        $this->nameBaseline($em, $project);
        $this->seedCosts($em, $project, 'a', ['2.000000', '2.000000', '2.000000', '2.000000', '2.000000'], 1);
        $this->seedCosts($em, $project, 'b', ['1.000000', '1.000000', '1.000000', '1.000000', '1.000000'], 6);
        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/analytics/experiments/model-test');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-experiment-goal]'));
        self::assertSame(['merge-rate'], $crawler->filter('[data-experiment-guard]')->each(static fn (Crawler $row): ?string => $row->attr('data-experiment-guard')));
        self::assertCount(0, $crawler->filter('[data-experiment-unknown-guards]'));
    }

    public function test_the_page_names_a_headline_key_that_no_metric_has(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'experiment-unknown-headline@example.com');
        $project = $this->boardProject($em, $owner);
        $definition = new ExperimentDefinition($project, 'model-test', [['name' => 'a', 'weight' => 1], ['name' => 'b', 'weight' => 1]]);
        $definition->baseline = 'a';
        $definition->headline = ['goal' => ['metric' => 'nonsense', 'better' => 'higher'], 'guards' => ['phantom']];
        $em->persist($definition);
        $this->seedCosts($em, $project, 'a', ['2.000000', '2.000000', '2.000000', '2.000000', '2.000000'], 1);
        $this->seedCosts($em, $project, 'b', ['1.000000', '1.000000', '1.000000', '1.000000', '1.000000'], 6);
        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/analytics/experiments/model-test');

        self::assertResponseIsSuccessful();
        self::assertSame('The bridge rule names a guard that this server does not know: phantom. The page leaves it out.', trim($crawler->filter('[data-experiment-unknown-guards]')->text()));
        self::assertSame('The headline goal nonsense is not a metric of this project.', trim($crawler->filter('[data-experiment-goal-problem]')->text()));
        self::assertSame(['nonsense'], $crawler->filter('[data-experiment-goal-problem] code.lp-experiment-config-key')->each(static fn ($node) => $node->text()));
        self::assertSame(['phantom'], $crawler->filter('[data-experiment-unknown-guards] code.lp-experiment-config-key')->each(static fn ($node) => $node->text()));
        self::assertCount(0, $crawler->filter('[data-experiment-goal], [data-experiment-goal-heading]'));
        self::assertCount(0, $crawler->filter('[data-experiment-guards]'));
    }

    public function test_the_page_escapes_a_key_of_the_bridge_rule(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'experiment-escaped-key@example.com');
        $project = $this->boardProject($em, $owner);
        $definition = new ExperimentDefinition($project, 'model-test', [['name' => 'a', 'weight' => 1], ['name' => 'b', 'weight' => 1]]);
        $definition->baseline = 'a';
        $definition->headline = ['goal' => ['metric' => '<i>goal</i>', 'better' => 'higher'], 'guards' => ['<b>guard</b>']];
        $em->persist($definition);
        $this->seedCosts($em, $project, 'a', ['2.000000', '2.000000', '2.000000', '2.000000', '2.000000'], 1);
        $this->seedCosts($em, $project, 'b', ['1.000000', '1.000000', '1.000000', '1.000000', '1.000000'], 6);
        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/analytics/experiments/model-test');

        self::assertResponseIsSuccessful();
        self::assertSame(['<i>goal</i>'], $crawler->filter('[data-experiment-goal-problem] code.lp-experiment-config-key')->each(static fn ($node) => $node->text()));
        self::assertSame(['<b>guard</b>'], $crawler->filter('[data-experiment-unknown-guards] code.lp-experiment-config-key')->each(static fn ($node) => $node->text()));
        self::assertCount(0, $crawler->filter('[data-experiment-goal-problem] i, [data-experiment-unknown-guards] b'));
    }

    public function test_the_page_asks_for_the_direction_of_a_goal(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'experiment-no-direction@example.com');
        $project = $this->boardProject($em, $owner);
        $definition = new ExperimentDefinition($project, 'model-test', [['name' => 'a', 'weight' => 1], ['name' => 'b', 'weight' => 1]]);
        $definition->baseline = 'a';
        $definition->headline = ['goal' => ['metric' => 'cost']];
        $em->persist($definition);
        $this->seedCosts($em, $project, 'a', ['2.000000', '2.000000', '2.000000', '2.000000', '2.000000'], 1);
        $this->seedCosts($em, $project, 'b', ['1.000000', '1.000000', '1.000000', '1.000000', '1.000000'], 6);
        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/analytics/experiments/model-test');

        self::assertResponseIsSuccessful();
        self::assertSame('The headline goal cost has no direction. Add better: lower or better: higher to it in the bridge rule.', trim($crawler->filter('[data-experiment-goal-problem]')->text()));
        self::assertSame(['cost', 'better: lower', 'better: higher'], $crawler->filter('[data-experiment-goal-problem] code.lp-experiment-config-key')->each(static fn ($node) => $node->text()));
        self::assertCount(0, $crawler->filter('[data-experiment-goal], [data-experiment-goal-row]'));
    }

    public function test_a_failed_control_holds_the_goal_and_the_controls_table_sits_above_the_guards(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'experiment-controls@example.com');
        $project = $this->boardProject($em, $owner);
        $this->bindLifecycle($em, $project);
        $definition = new ExperimentDefinition($project, 'model-test', [['name' => 'a', 'weight' => 1], ['name' => 'b', 'weight' => 1]]);
        $definition->baseline = 'a';
        $definition->headline = ['goal' => ['metric' => 'cost', 'better' => 'lower'], 'controls' => [
            ['metric' => 'harness-events:diagnostics', 'variant' => 'a', 'expect' => '> 1', 'share' => 0.5],
            ['metric' => 'cost', 'variant' => 'b', 'expect' => '< 5', 'share' => 1.0],
            ['metric' => 'harness-events:never-seen', 'variant' => 'b', 'expect' => '== 0', 'share' => 1.0],
        ]];
        $em->persist($definition);
        $this->seedCosts($em, $project, 'a', ['2.000000', '2.000000', '2.000000', '2.000000', '2.000000'], 1);
        $this->seedCosts($em, $project, 'b', ['1.000000', '1.000000', '1.000000', '1.000000', '1.000000'], 6);
        $em->getConnection()->executeStatement("UPDATE bridge_worker_runs SET harness_events = CASE WHEN variant = 'a' THEN '{\"diagnostics\": 1}'::jsonb ELSE NULL END");
        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/analytics/experiments/model-test');

        self::assertResponseIsSuccessful();
        $row = $crawler->filter('[data-experiment-goal-row="b"]');
        self::assertSame('held', $row->attr('data-experiment-goal-state'));
        self::assertCount(0, $crawler->filter('[data-experiment-goal-problem]'));
        self::assertSame(['b', '$1.00', '–', '–', 'Control failed'], $this->cells($row));
        self::assertCount(1, $row->filter('.lp-status-chip--failed'));
        $hold = $crawler->filter('[data-experiment-goal-hold="b"]');
        self::assertSame('b: Controls failed: 1 of 3. The comparison names no winner.', trim($hold->text()));
        self::assertSame('failed', $hold->attr('data-experiment-goal-hold-reason'));
        self::assertSame(['Goal · Cost per merged card · lower is better', 'Controls', 'Guards', 'Variants'], \array_slice($crawler->filter('h3.lp-experiment-section')->each(static fn (Crawler $node): string => trim($node->text())), 0, 4));
        self::assertSame(['Control', 'Variant', 'Cards that pass', 'Needs', 'Cards with data', 'Result'], $this->cells($crawler->filter('[data-experiment-controls] thead tr')));
        self::assertSame(['Harness events: diagnostics > 1', 'a', '0%', '50%', '5 of 5', 'Failed'], $this->cells($crawler->filter('[data-experiment-control="harness-events:diagnostics"]')));
        self::assertSame(['Cost < 5', 'b', '100%', '100%', '5 of 5', 'Passed'], $this->cells($crawler->filter('[data-experiment-control="cost"]')));
        self::assertSame(['Harness events: never-seen == 0', 'b', '–', '100%', '0 of 5', 'No data'], $this->cells($crawler->filter('[data-experiment-control="harness-events:never-seen"]')));
    }

    public function test_a_hold_names_the_key_of_a_control_that_is_no_metric(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'experiment-control-typo@example.com');
        $project = $this->boardProject($em, $owner);
        $definition = new ExperimentDefinition($project, 'model-test', [['name' => 'a', 'weight' => 1], ['name' => 'b', 'weight' => 1]]);
        $definition->baseline = 'a';
        $definition->headline = ['controls' => [['metric' => 'lsp-diagnostcs', 'variant' => 'a', 'expect' => '> 0', 'share' => 0.5]]];
        $em->persist($definition);
        $this->seedCosts($em, $project, 'a', ['2.000000', '2.000000', '2.000000', '2.000000', '2.000000'], 1);
        $this->seedCosts($em, $project, 'b', ['1.000000', '1.000000', '1.000000', '1.000000', '1.000000'], 6);
        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/analytics/experiments/model-test');

        self::assertResponseIsSuccessful();
        $row = $crawler->filter('[data-experiment-goal-row="b"]');
        self::assertSame('held', $row->attr('data-experiment-goal-state'));
        self::assertSame(['b', '$1.00', '–', '–', 'No data'], $this->cells($row));
        self::assertCount(1, $row->filter('.lp-status-chip--neutral'));
        self::assertSame('b: Controls with an unknown metric: 1 of 1. The comparison names no winner.', trim($crawler->filter('[data-experiment-goal-hold="b"]')->text()));
        self::assertSame(['lsp-diagnostcs > 0', 'a', '–', '50%', '–', 'No data'], $this->cells($crawler->filter('[data-experiment-control="lsp-diagnostcs"]')));
    }

    public function test_the_page_shows_no_controls_table_without_controls(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'experiment-no-controls@example.com');
        $project = $this->boardProject($em, $owner);
        $this->nameBaseline($em, $project);
        $this->seedCosts($em, $project, 'a', ['2.000000', '2.000000', '2.000000', '2.000000', '2.000000'], 1);
        $this->seedCosts($em, $project, 'b', ['1.000000', '1.000000', '1.000000', '1.000000', '1.000000'], 6);
        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/analytics/experiments/model-test');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-experiment-controls]'));
        self::assertSame('clear', $crawler->filter('[data-experiment-goal-row="b"]')->attr('data-experiment-goal-state'));
        self::assertCount(0, $crawler->filter('[data-experiment-goal-hold]'));
    }

    public function test_three_arms_each_compare_against_the_baseline_with_a_widened_level(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'experiment-three-arms@example.com');
        $project = $this->boardProject($em, $owner);
        $this->bindLifecycle($em, $project);
        $this->threeArmDefinition($em, $project);
        $this->seedCosts($em, $project, 'a', ['4.000000', '4.000000', '4.000000', '4.000000', '4.000000'], 1);
        $this->seedCosts($em, $project, 'b', ['2.000000', '2.000000', '2.000000', '2.000000', '2.000000'], 6);
        $this->seedCosts($em, $project, 'c', ['5.000000', '5.000000', '5.000000', '5.000000', '5.000000'], 11);
        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/analytics/experiments/model-test');

        self::assertResponseIsSuccessful();
        self::assertSame(['a', 'b', 'c'], $crawler->filter('[data-experiment-goal-row]')->each(static fn (Crawler $row): ?string => $row->attr('data-experiment-goal-row')));
        self::assertSame('Baseline', trim($crawler->filter('[data-experiment-goal-baseline] .lp-tag--neutral')->text()));
        self::assertSame(['b', '$2.00', '50% cheaper', '50% to 50% lower', 'Clear'], $this->cells($crawler->filter('[data-experiment-goal-row="b"]')));
        self::assertSame(['c', '$5.00', '25% more expensive', '25% to 25% higher', 'Clear'], $this->cells($crawler->filter('[data-experiment-goal-row="c"]')));
        self::assertSame('The ranges are widened to 97.5% because the page makes 2 comparisons against a.', trim($crawler->filter('[data-experiment-goal-widened]')->text()));
        self::assertSame(['Guard', 'a (baseline)', 'b', 'Change, likely range', 'c', 'Change, likely range'], $this->cells($crawler->filter('[data-experiment-guards] thead tr')));
        self::assertCount(6, $this->cells($crawler->filter('[data-experiment-guard="merge-rate"]')));
        self::assertSame(['Metric', 'a', 'Likely range', 'b', 'Likely range', 'c', 'Likely range'], $this->cells($crawler->filter('[data-experiment-metrics] thead tr')));
        self::assertStringContainsString('true value 97.5 times in 100, widened for 2 comparisons against the baseline.', trim($crawler->filter('[data-experiment-metrics-note]')->text()));
        self::assertSame(['a', 'b', 'c'], $crawler->filter('[data-experiment-variant]')->each(static fn (Crawler $row): ?string => $row->attr('data-experiment-variant')));
        self::assertSame(['a Baseline'], [$this->cells($crawler->filter('[data-experiment-variant="a"]'))[0]]);
        self::assertCount(1, $crawler->filter('[data-experiment-variants] .lp-tag--neutral'));
    }

    public function test_a_failed_control_on_one_arm_holds_only_that_arm(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'experiment-held-arm@example.com');
        $project = $this->boardProject($em, $owner);
        $definition = $this->threeArmDefinition($em, $project);
        $definition->headline = ['goal' => ['metric' => 'cost', 'better' => 'lower'], 'controls' => [
            ['metric' => 'harness-events:diagnostics', 'variant' => 'c', 'expect' => '> 1', 'share' => 0.5],
        ]];
        $definition->metrics = ['cost', 'harness-events:diagnostics'];
        $this->seedCosts($em, $project, 'a', ['4.000000', '4.000000', '4.000000', '4.000000', '4.000000'], 1);
        $this->seedCosts($em, $project, 'b', ['2.000000', '2.000000', '2.000000', '2.000000', '2.000000'], 6);
        $this->seedCosts($em, $project, 'c', ['5.000000', '5.000000', '5.000000', '5.000000', '5.000000'], 11);
        $em->getConnection()->executeStatement("UPDATE bridge_worker_runs SET harness_events = CASE WHEN variant = 'c' THEN '{\"diagnostics\": 1}'::jsonb ELSE NULL END");
        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/analytics/experiments/model-test');

        self::assertResponseIsSuccessful();
        self::assertSame('clear', $crawler->filter('[data-experiment-goal-row="b"]')->attr('data-experiment-goal-state'));
        self::assertSame(['b', '$2.00', '50% cheaper', '50% to 50% lower', 'Clear'], $this->cells($crawler->filter('[data-experiment-goal-row="b"]')));
        self::assertSame('held', $crawler->filter('[data-experiment-goal-row="c"]')->attr('data-experiment-goal-state'));
        self::assertSame(['c', '$5.00', '–', '–', 'Control failed'], $this->cells($crawler->filter('[data-experiment-goal-row="c"]')));
        self::assertCount(0, $crawler->filter('[data-experiment-goal-hold="b"]'));
        self::assertSame('c: Controls failed: 1 of 1. The comparison names no winner.', trim($crawler->filter('[data-experiment-goal-hold="c"]')->text()));
        self::assertStringStartsWith('Harness events: diagnostics', $this->cells($crawler->filter('[data-metric="harness-events:diagnostics"]'))[0]);
    }

    public function test_an_experiment_with_no_baseline_shows_a_note_and_no_comparison(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'experiment-no-baseline@example.com');
        $project = $this->boardProject($em, $owner);
        $this->bindLifecycle($em, $project);
        $em->persist(new ExperimentDefinition($project, 'model-test', [['name' => 'a', 'weight' => 1], ['name' => 'b', 'weight' => 1]]));
        $this->seedCosts($em, $project, 'a', ['2.000000', '2.000000', '2.000000', '2.000000', '2.000000'], 1);
        $this->seedCosts($em, $project, 'b', ['1.000000', '1.000000', '1.000000', '1.000000', '1.000000'], 6);
        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/analytics/experiments/model-test');

        self::assertResponseIsSuccessful();
        self::assertSame('This experiment names no baseline. Add baseline: to it in the bridge rule file to compare the variants.', trim($crawler->filter('[data-experiment-no-baseline]')->text()));
        self::assertSame(['baseline:'], $crawler->filter('[data-experiment-no-baseline] code.lp-experiment-config-key')->each(static fn (Crawler $code): string => $code->text()));
        self::assertCount(0, $crawler->filter('[data-experiment-goal], [data-experiment-goal-heading], [data-experiment-guards], [data-experiment-goal-widened]'));
        self::assertCount(0, $crawler->filter('.lp-tag--neutral'));
        self::assertCount(2, $crawler->filter('[data-experiment-variant]'));
        self::assertSame(['Cost per merged card', '$2.00', '$2.00–$2.00', '$1.00', '$1.00–$1.00'], $this->cells($crawler->filter('[data-metric="cost"]')));
        self::assertCount(0, $crawler->filter('[data-metric-unclear]'));
        self::assertStringContainsString('true value 95 times in 100. A metric is clear', trim($crawler->filter('[data-experiment-metrics-note]')->text()));
    }

    private function threeArmDefinition(EntityManagerInterface $em, Project $project): ExperimentDefinition
    {
        $definition = new ExperimentDefinition($project, 'model-test', [['name' => 'a', 'weight' => 1], ['name' => 'b', 'weight' => 1], ['name' => 'c', 'weight' => 1]]);
        $definition->baseline = 'a';
        $em->persist($definition);

        return $definition;
    }

    private function nameBaseline(EntityManagerInterface $em, Project $project): void
    {
        $definition = new ExperimentDefinition($project, 'model-test', [['name' => 'a', 'weight' => 1], ['name' => 'b', 'weight' => 1]]);
        $definition->baseline = 'a';
        $em->persist($definition);
    }

    /** @param list<string> $costs one merged card per cost, numbered from the given number */
    private function seedCosts(EntityManagerInterface $em, Project $project, string $variant, array $costs, int $firstNumber): void
    {
        foreach ($costs as $offset => $cost) {
            $card = $this->experimentCard($em, $project, $firstNumber + $offset, merged: true);
            $this->seedUsage($em, $this->experimentRun($em, $project, $card, $variant), costUsd: $cost);
        }
    }

    /** @return list<string> */
    private function cells(Crawler $row): array
    {
        self::assertCount(1, $row);

        return $row->filter('th, td')->each(static fn (Crawler $cell): string => trim($cell->text()));
    }
}
