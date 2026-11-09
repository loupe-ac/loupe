<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Preview;

use App\Module\Bridge\Metric\MetricGroup;
use App\Module\Bridge\Metric\MetricWindow;
use App\Module\Insights\Entity\PublicLink;
use App\Module\Insights\Preview\ExperimentPreviewLayouts;
use App\Module\Insights\Preview\PreviewRenderer;
use App\Module\Insights\Preview\PreviewResultUnsettled;
use App\Module\Insights\Preview\PublicPreviewDrawer;
use App\Module\Insights\Preview\PublicPreviewEntries;
use App\Module\Insights\Preview\PublicPreviewSubject;
use App\Module\Insights\Preview\PublicPreviewSubjects;
use App\Module\Insights\Preview\PublicPreviewVersion;
use App\Module\Insights\Service\InsightsResultKeeper;
use App\Module\Insights\Service\PublicGraphResults;
use App\Module\Insights\View\KeptGraphResult;
use App\Module\Insights\View\MetricChartType;
use App\Module\Insights\View\MetricsQuery;
use App\Tests\Module\Insights\PublicPreviewScenario;
use App\Tests\Support\ChartSeries;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Twig\Environment;

final class PublicPreviewDrawerTest extends KernelTestCase
{
    use PublicPreviewScenario;

    private const string PNG = "\x89PNG\r\n\x1a\n";

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function test_it_draws_the_kept_result_and_keeps_the_png_with_its_version(): void
    {
        $link = $this->sharedCostGraph('drawer-keeps');
        $expected = $this->liveSubjects()->read($link);
        self::assertNotNull($expected);

        $preview = $this->drawer($this->liveSubjects())->draw($link, PublicPreviewDrawer::WORKER);

        self::assertStringStartsWith(self::PNG, $preview->png);
        self::assertSame($expected->version, $preview->version);
        self::assertFalse($preview->reused);
        $entry = $this->entries()->entryFor($link->code);
        self::assertNotNull($entry);
        self::assertSame($expected->version, $entry->version);
        self::assertSame($preview->png, $this->entries()->png($entry));
    }

    public function test_a_second_draw_of_the_same_result_reuses_the_kept_png(): void
    {
        $link = $this->sharedCostGraph('drawer-reuse');
        $subjects = $this->liveSubjects();
        $first = $this->drawer($subjects)->draw($link, PublicPreviewDrawer::WORKER);

        $second = $this->drawer($subjects)->draw($link, PublicPreviewDrawer::INLINE);

        self::assertTrue($second->reused);
        self::assertSame($first->png, $second->png);
    }

    public function test_two_computes_with_the_same_numbers_keep_one_version(): void
    {
        $link = $this->sharedCostGraph('drawer-same-numbers');
        $graph = $link->savedGraph;
        self::assertNotNull($graph);
        $keeper = self::getContainer()->get(InsightsResultKeeper::class);
        self::assertInstanceOf(InsightsResultKeeper::class, $keeper);

        $at = new \DateTimeImmutable('2100-01-01 UTC');
        $first = $keeper->graph($graph);
        usleep(1_100_000);
        $second = $keeper->graph($graph);

        self::assertNotEquals($first->computedAt, $second->computedAt);
        self::assertSame(PublicPreviewVersion::of($first, $at), PublicPreviewVersion::of($second, $at));
    }

    public function test_other_numbers_or_a_legend_change_give_another_version(): void
    {
        $link = $this->sharedCostGraph('drawer-other-numbers');
        $kept = $this->kept($link);
        $at = new \DateTimeImmutable('2100-01-01 UTC');
        $moved = new KeptGraphResult($kept->computedAt, $kept->query, array_slice($kept->series, 0, 0), $kept->groupLabels, $kept->current, $kept->previous);

        self::assertNotSame(PublicPreviewVersion::of($kept, $at), PublicPreviewVersion::of($moved, $at));
        self::assertNotSame(PublicPreviewVersion::of($kept, $at, false), PublicPreviewVersion::of($kept, $at, true));
        self::assertSame('broken', PublicPreviewVersion::of(null, $at));
    }

    public function test_a_line_graph_gets_another_version_once_its_last_bucket_closes(): void
    {
        $window = new MetricWindow(null, new \DateTimeImmutable('2026-11-08 23:58 UTC'));
        $series = ChartSeries::of(null, ['2026-11-02' => 1.0, '2026-11-09' => 2.0]);
        $line = new KeptGraphResult(new \DateTimeImmutable('2026-11-08 23:58 UTC'), new MetricsQuery(), [$series], [], $window, null);

        $before = PublicPreviewVersion::of($line, new \DateTimeImmutable('2026-11-09 00:05 UTC'));
        $after = PublicPreviewVersion::of($line, new \DateTimeImmutable('2026-11-16 00:05 UTC'));

        self::assertTrue($line->drawsOpenLine(new \DateTimeImmutable('2026-11-09 00:05 UTC')));
        self::assertFalse($line->drawsOpenLine(new \DateTimeImmutable('2026-11-16 00:00 UTC')));
        self::assertNotSame($before, $after);
        self::assertSame($after, PublicPreviewVersion::of($line, new \DateTimeImmutable('2026-12-30 00:00 UTC')));
    }

    public function test_a_request_that_waits_on_the_lock_draws_the_newer_result(): void
    {
        $link = $this->sharedCostGraph('drawer-waits');
        $subjects = new ScriptedSubjects([$this->subject($link, 'old', false)]);
        $twig = new RecordingTwig(self::getContainer()->get(Environment::class));
        $newer = $this->subject($link, 'new', true);
        $store = new HookStore(new InMemoryStore(), static function () use ($subjects, $newer): void {
            $subjects->script = [$newer];
        });

        $preview = $this->drawer($subjects, $twig, new LockFactory($store))->draw($link, PublicPreviewDrawer::INLINE);

        self::assertSame('new', $preview->version);
        self::assertSame([true], array_column($twig->contexts, 'legend'));
        self::assertSame('new', $this->entries()->entryFor($link->code)?->version);
    }

    public function test_a_request_that_waits_on_the_lock_finds_the_result_drawn(): void
    {
        $link = $this->sharedCostGraph('drawer-finds');
        $subjects = new ScriptedSubjects([$this->subject($link, 'new', true)]);
        $twig = new RecordingTwig(self::getContainer()->get(Environment::class));
        $other = $this->drawer($subjects);
        $store = new HookStore(new InMemoryStore(), static function () use ($other, $link): void {
            $other->draw($link, PublicPreviewDrawer::WORKER);
        });

        $preview = $this->drawer($subjects, $twig, new LockFactory($store))->draw($link, PublicPreviewDrawer::INLINE);

        self::assertTrue($preview->reused);
        self::assertSame([], $twig->contexts);
    }

    public function test_a_result_that_changes_during_the_render_is_drawn_again_and_only_the_confirmed_version_is_kept(): void
    {
        $link = $this->sharedCostGraph('drawer-redraw');
        $subjects = new ScriptedSubjects([
            $this->subject($link, 'a', false),
            $this->subject($link, 'b', true),
            $this->subject($link, 'b', true),
        ]);
        $twig = new RecordingTwig(self::getContainer()->get(Environment::class));

        $preview = $this->drawer($subjects, $twig)->draw($link, PublicPreviewDrawer::WORKER);

        self::assertSame('b', $preview->version);
        self::assertSame([false, true], array_column($twig->contexts, 'legend'));
        self::assertSame('b', $this->entries()->entryFor($link->code)?->version);
    }

    public function test_a_result_that_changes_on_each_of_three_draws_keeps_nothing(): void
    {
        $link = $this->sharedCostGraph('drawer-unsettled');
        $subjects = new ScriptedSubjects([
            $this->subject($link, 'a', false),
            $this->subject($link, 'b', false),
            $this->subject($link, 'c', false),
            $this->subject($link, 'd', false),
        ]);
        $twig = new RecordingTwig(self::getContainer()->get(Environment::class));

        try {
            $this->drawer($subjects, $twig)->draw($link, PublicPreviewDrawer::WORKER);
            self::fail('The drawer returned a PNG that no recheck confirmed.');
        } catch (PreviewResultUnsettled) {
            self::assertCount(3, $twig->contexts);
            self::assertNull($this->entries()->entryFor($link->code));
        }
    }

    public function test_a_broken_graph_draws_the_broken_image_under_the_broken_version(): void
    {
        $link = $this->sharedCostGraph('drawer-broken', withData: false);
        $subjects = new ScriptedSubjects([new PublicPreviewSubject(PublicPreviewVersion::BROKEN)]);
        $twig = new RecordingTwig(self::getContainer()->get(Environment::class));

        $preview = $this->drawer($subjects, $twig)->draw($link, PublicPreviewDrawer::INLINE);

        self::assertSame('broken', $preview->version);
        self::assertSame(['broken'], array_column($twig->contexts, 'state'));
        self::assertStringStartsWith(self::PNG, $preview->png);
    }

    public function test_an_empty_graph_draws_the_empty_image(): void
    {
        $link = $this->sharedCostGraph('drawer-empty', withData: false);
        $twig = new RecordingTwig(self::getContainer()->get(Environment::class));

        $preview = $this->drawer($this->liveSubjects(), $twig)->draw($link, PublicPreviewDrawer::INLINE);

        self::assertSame(['empty'], array_column($twig->contexts, 'state'));
        self::assertStringStartsWith(self::PNG, $preview->png);
    }

    public function test_a_chart_draws_the_chart_state(): void
    {
        $link = $this->sharedCostGraph('drawer-chart');
        $twig = new RecordingTwig(self::getContainer()->get(Environment::class));

        $this->drawer($this->liveSubjects(), $twig)->draw($link, PublicPreviewDrawer::INLINE);

        self::assertSame(['chart'], array_column($twig->contexts, 'state'));
    }

    /** @return iterable<string, array{MetricChartType}> */
    public static function chartTypes(): iterable
    {
        foreach (MetricChartType::cases() as $type) {
            yield $type->value => [$type];
        }
    }

    #[DataProvider('chartTypes')]
    public function test_every_chart_type_draws_a_well_formed_svg_with_the_made_with_line(MetricChartType $type): void
    {
        $link = $this->sharedCostGraph('drawer-svg-'.$type->value);
        $graph = $link->savedGraph;
        self::assertNotNull($graph);
        $graph->chartType = $type;
        $graph->group = MetricGroup::Account;
        $graph->showLegend = true;
        $em = $this->em();
        $this->seedUsage($em, $this->seedRun($em, $graph->project, cardNumber: 2, endedAt: new \DateTimeImmutable('-4 days')), costUsd: '5.00');
        $em->getConnection()->executeStatement('UPDATE bridge_worker_run_facts SET account = CASE WHEN account IS NULL THEN \'first\' ELSE account END');
        $em->flush();
        $twig = new RecordingTwig(self::getContainer()->get(Environment::class));

        $this->drawer($this->liveSubjects(), $twig)->draw($link, PublicPreviewDrawer::INLINE);

        $svg = simplexml_load_string($twig->outputs[0]);
        self::assertNotFalse($svg);
        self::assertSame('1200', (string) $svg['width']);
        self::assertStringContainsString('Made with Loupe', $twig->outputs[0]);
        self::assertStringNotContainsString('href', $twig->outputs[0]);
        self::assertStringNotContainsString('foreignObject', $twig->outputs[0]);
    }

    public function test_the_empty_and_broken_images_draw_their_sentence(): void
    {
        $link = $this->sharedCostGraph('drawer-sentences', withData: false);
        $twig = new RecordingTwig(self::getContainer()->get(Environment::class));
        $this->drawer($this->liveSubjects(), $twig)->draw($link, PublicPreviewDrawer::INLINE);
        $this->drawer(new ScriptedSubjects([new PublicPreviewSubject(PublicPreviewVersion::BROKEN)]), $twig, new LockFactory(new InMemoryStore()))->draw($link, PublicPreviewDrawer::INLINE);

        self::assertStringContainsString('No runs are recorded yet', $twig->outputs[0]);
        self::assertStringContainsString('This graph cannot be drawn right now.', $twig->outputs[1]);
        self::assertNotFalse(simplexml_load_string($twig->outputs[1]));
    }

    public function test_the_real_tool_draws_a_png_of_the_image_size(): void
    {
        if (null === new \Symfony\Component\Process\ExecutableFinder()->find('rsvg-convert')) {
            self::markTestSkipped('rsvg-convert is not installed.');
        }
        $link = $this->sharedCostGraph('drawer-real');
        $renderer = new PreviewRenderer();
        $twig = self::getContainer()->get(Environment::class);
        self::assertInstanceOf(Environment::class, $twig);
        $drawer = new PublicPreviewDrawer(new LockFactory(new InMemoryStore()), $this->liveSubjects(), $this->entries(), $this->results(), $this->layouts(), $renderer, $twig);

        $preview = $drawer->draw($link, PublicPreviewDrawer::INLINE);

        self::assertSame([1200, 630], \array_slice(getimagesizefromstring($preview->png) ?: [], 0, 2));
    }

    public function test_a_png_is_written_again_only_after_a_day(): void
    {
        $clock = new MockClock('2026-11-08 10:00:00');
        $entries = new PublicPreviewEntries(new ArrayAdapter(clock: $clock), $clock);
        $code = 'abcdefghijkl';

        self::assertSame(5, $entries->keep($code, 'v1', 'hello'));
        $first = $entries->entryFor($code);
        self::assertNotNull($first);

        self::assertSame(0, $entries->keep($code, 'v2', 'hello'), 'The same bytes are not written again.');
        $second = $entries->entryFor($code);
        self::assertNotNull($second);
        self::assertSame('v2', $second->version);
        self::assertSame($first->pngWrittenAt, $second->pngWrittenAt);

        $clock->modify('+1 day');
        self::assertSame(5, $entries->keep($code, 'v2', 'hello'), 'An entry older than a day is written again.');
    }

    public function test_the_small_entry_expires_after_a_day_and_the_png_after_two_and_each_draw_renews_both(): void
    {
        $clock = new MockClock('2026-11-08 10:00:00');
        $entries = new PublicPreviewEntries(new ArrayAdapter(clock: $clock), $clock);
        $code = 'abcdefghijkl';
        $entries->keep($code, 'v1', 'hello');

        $clock->modify('+23 hours');
        $entry = $entries->entryFor($code);
        self::assertNotNull($entry);
        self::assertSame('hello', $entries->png($entry));

        $clock->modify('+2 hours');
        self::assertNull($entries->entryFor($code), 'The small entry lasts one day.');
        self::assertSame('hello', $entries->png($entry));

        $clock->modify('+23 hours');
        self::assertNull($entries->png($entry), 'The PNG entry lasts two days.');

        $entries->keep($code, 'v1', 'hello');
        $clock->modify('+23 hours');
        $renewed = $entries->entryFor($code);
        self::assertNotNull($renewed);
        $entries->keep($code, 'v1', 'hello');
        $clock->modify('+23 hours');
        self::assertNotNull($entries->entryFor($code), 'A draw renews the small entry.');
        $entry = $renewed;
        self::assertSame('hello', $entries->png($entry));
    }

    private function subject(PublicLink $link, string $version, bool $legend): PublicPreviewSubject
    {
        return new PublicPreviewSubject($version, $this->kept($link), $legend);
    }

    private function kept(PublicLink $link): KeptGraphResult
    {
        $graph = $link->savedGraph;
        self::assertNotNull($graph);
        $kept = $this->results()->kept($graph);
        self::assertNotNull($kept);

        return $kept;
    }

    private function results(): PublicGraphResults
    {
        $results = self::getContainer()->get(PublicGraphResults::class);
        self::assertInstanceOf(PublicGraphResults::class, $results);

        return $results;
    }

    private function layouts(): ExperimentPreviewLayouts
    {
        $layouts = self::getContainer()->get(ExperimentPreviewLayouts::class);
        self::assertInstanceOf(ExperimentPreviewLayouts::class, $layouts);

        return $layouts;
    }

    private function entries(): PublicPreviewEntries
    {
        $entries = self::getContainer()->get(PublicPreviewEntries::class);
        self::assertInstanceOf(PublicPreviewEntries::class, $entries);

        return $entries;
    }

    private function liveSubjects(): PublicPreviewSubjects
    {
        $subjects = self::getContainer()->get(PublicPreviewSubjects::class);
        self::assertInstanceOf(PublicPreviewSubjects::class, $subjects);

        return $subjects;
    }

    private function drawer(PublicPreviewSubjects $subjects, ?Environment $twig = null, ?LockFactory $locks = null): PublicPreviewDrawer
    {
        $twig ??= self::getContainer()->get(Environment::class);
        self::assertInstanceOf(Environment::class, $twig);

        return new PublicPreviewDrawer($locks ?? new LockFactory(new InMemoryStore()), $subjects, $this->entries(), $this->results(), $this->layouts(), new PreviewRenderer(__DIR__.'/fake-rsvg-convert'), $twig);
    }
}
