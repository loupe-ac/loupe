<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Controller;

use App\Module\Bridge\Command\ArchiveExperimentCommand;
use App\Module\Bridge\Command\ArchiveExperimentHandler;
use App\Module\Bridge\Entity\ExperimentDefinition;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Insights\Entity\PublicLink;
use App\Module\Insights\Entity\PublicLinkKind;
use App\Tests\Module\Bridge\ExperimentScenario;
use App\Tests\Support\InstalledInstance;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

/** The test app host is `localhost`. */
final class ShowPublicExperimentControllerTest extends WebTestCase
{
    use ExperimentScenario;

    private const string SECRET_CARD = 'Secret card title zq';

    private const string SECRET_BRIDGE = 'Dev box / 7 zq';

    private const string NOTE = "Our **model test**.\n\n<script>alert(1)</script>\n\nSee card:3 for more.";

    private string $previousSiteHost = '';

    #[\Override]
    protected function setUp(): void
    {
        $this->previousSiteHost = (string) ($_SERVER['SITE_HOST'] ?? '');
    }

    #[\Override]
    protected function tearDown(): void
    {
        $_ENV['SITE_HOST'] = $_SERVER['SITE_HOST'] = $this->previousSiteHost;
        parent::tearDown();
    }

    public function test_a_shared_experiment_shows_its_totals_in_the_site_frame_with_the_note(): void
    {
        $client = $this->client('');
        $link = $this->sharedExperiment('public-x-ok', note: self::NOTE);

        $client->request(Request::METHOD_GET, '/s/'.$link->code);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.lp-home-nav');
        self::assertSelectorExists('.lp-home-footer');
        self::assertSelectorNotExists('.lp-sidebar');
        self::assertSelectorTextContains('.lp-public__project', 'Project public-x-ok');
        self::assertSelectorTextContains('[data-public-experiment] .lp-experiment-heading__name', 'model-test');
        self::assertSelectorExists('[data-public-experiment-panel] [data-public-experiment-note]');
        self::assertSelectorExists('[data-public-experiment-panel] [data-experiment-variants]');
        self::assertSelectorExists('[data-public-experiment-panel] [data-experiment-metrics]');
        self::assertSelectorExists('[data-public-experiment-panel] [data-experiment-goal]');
        self::assertSelectorTextContains('[data-public-experiment-panel] > [data-public-experiment-privacy]:last-child', 'Totals only. The cards of each variant stay private.');
        self::assertSelectorNotExists('[data-public-experiment-panel] .lp-experiment-heading__name');
        self::assertSelectorTextContains('.lp-public__meta', 'Updated 0 min ago');
        self::assertSelectorTextContains('.lp-public__meta a.lp-public__made-with', 'Made with Loupe');
        $stamp = $client->getCrawler()->filter('[data-controller~="dashboard-age"] [data-public-experiment]')->attr('data-computed-at');
        self::assertIsNumeric($stamp, 'The age line hides itself when the item gives it no data-computed-at.');
        self::assertEqualsWithDelta(time(), (int) $stamp, 120);
    }

    public function test_the_page_has_no_tabs_no_cards_link_no_analyse_button_and_no_unknown_metrics_list(): void
    {
        $client = $this->client('');
        $link = $this->sharedExperiment('public-x-chrome', note: null);

        $client->request(Request::METHOD_GET, '/s/'.$link->code);

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.lp-experiment-tabs');
        self::assertSelectorNotExists('[data-experiment-analyse]');
        self::assertSelectorNotExists('[data-experiment-left-out]');
        self::assertSelectorNotExists('[data-experiment-unknown-metrics]');
        self::assertSelectorNotExists('[data-experiment-unknown-guards]');
        self::assertSelectorNotExists('[data-experiment-archive-toggle]');
        self::assertSelectorNotExists('[data-public-experiment-note]');
        $source = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('/analytics/', $source);
        self::assertStringNotContainsString('/cards', $source);
    }

    public function test_the_page_source_holds_no_card_bridge_or_person_name(): void
    {
        $client = $this->client('');
        $link = $this->sharedExperiment('public-x-leak', note: null);

        $client->request(Request::METHOD_GET, '/s/'.$link->code);

        self::assertResponseIsSuccessful();
        $source = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString(self::SECRET_CARD, $source);
        self::assertStringNotContainsString(self::SECRET_BRIDGE, $source);
        self::assertStringNotContainsString('Riley Chen', $source);
        self::assertStringNotContainsString('example.com', $source);
        self::assertStringNotContainsString('zq', $source);
    }

    public function test_the_note_renders_as_sanitized_markdown(): void
    {
        $client = $this->client('');
        $link = $this->sharedExperiment('public-x-note', note: self::NOTE);

        $client->request(Request::METHOD_GET, '/s/'.$link->code);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-public-experiment-note] strong', 'model test');
        $source = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('<script>alert', $source);
        self::assertSelectorTextContains('[data-public-experiment-note] [data-ref-card="3"]', 'card #3');
        self::assertSelectorNotExists('[data-public-experiment-note] a');
    }

    /** @return iterable<string, array{string, string}> */
    public static function hosts(): iterable
    {
        yield 'app host with no site host' => ['', 'localhost'];
        yield 'site host' => ['loupe.example', 'loupe.example'];
    }

    #[DataProvider('hosts')]
    public function test_the_page_sets_no_cookie(string $siteHost, string $requestHost): void
    {
        $client = $this->client($siteHost);
        $link = $this->sharedExperiment('public-x-cookie', note: 'A note.');

        $client->request(Request::METHOD_GET, '/s/'.$link->code, server: ['HTTP_HOST' => $requestHost]);

        self::assertResponseIsSuccessful();
        self::assertFalse($client->getResponse()->headers->has('Set-Cookie'));
    }

    public function test_an_archived_experiment_and_an_unknown_experiment_give_the_same_404_page_as_a_wrong_code(): void
    {
        $client = $this->client('');
        $link = $this->sharedExperiment('public-x-404', note: null);
        $project = $link->project;
        $ghost = new PublicLink($project, PublicLinkKind::Experiment, 'ghostghostgh', experiment: 'ghost');
        $this->em()->persist($ghost);
        $this->em()->flush();
        $archive = static::getContainer()->get(ArchiveExperimentHandler::class);
        self::assertInstanceOf(ArchiveExperimentHandler::class, $archive);
        $archive(new ArchiveExperimentCommand($project, 'model-test'));

        $bodies = [];
        foreach (['aaaaaaaaaaaa' => 'wrong code', $link->code => 'archived', $ghost->code => 'unknown experiment'] as $code => $reason) {
            $client->request(Request::METHOD_GET, '/s/'.$code);
            self::assertResponseStatusCodeSame(404, $reason);
            $bodies[$reason] = $this->withoutNoise((string) $client->getResponse()->getContent());
        }

        self::assertCount(1, array_unique($bodies));
        self::assertFalse($client->getResponse()->headers->has('Set-Cookie'));
    }

    public function test_the_report_is_kept_so_a_second_view_does_not_recompute_it(): void
    {
        $client = $this->client('');
        $link = $this->sharedExperiment('public-x-kept', note: null);

        $client->request(Request::METHOD_GET, '/s/'.$link->code);
        self::assertResponseIsSuccessful();
        $first = $client->getCrawler()->filter('[data-experiment-variants]')->html();

        $runs = static::getContainer()->get(WorkerRunRepository::class);
        self::assertInstanceOf(WorkerRunRepository::class, $runs);
        $run = $runs->findOneBy(['project' => $link->project]);
        self::assertNotNull($run);
        $run->variant = 'renamed';
        $this->em()->flush();
        $client->request(Request::METHOD_GET, '/s/'.$link->code);

        self::assertSame($first, $client->getCrawler()->filter('[data-experiment-variants]')->html());
    }

    private function sharedExperiment(string $name, ?string $note): PublicLink
    {
        $em = $this->em();
        $owner = $this->user($em, $name.'-'.uniqid().'@example.com');
        $project = $this->boardProject($em, $owner);
        $project->name = 'Project '.$name;
        $this->bindLifecycle($em, $project);
        $definition = new ExperimentDefinition($project, 'model-test', [['name' => 'a', 'weight' => 1], ['name' => 'b', 'weight' => 1]]);
        $definition->baseline = 'a';
        $em->persist($definition);
        $bridge = $this->seedBridge($em, $owner, projects: [(string) $project->id]);
        $bridge->name = self::SECRET_BRIDGE;
        $em->flush();
        for ($i = 1; $i <= 4; ++$i) {
            foreach (['a' => $i, 'b' => $i + 4] as $variant => $number) {
                $card = $this->experimentCard($em, $project, $number, merged: true);
                $card->title = self::SECRET_CARD.' '.$number;
                $run = $this->experimentRun($em, $project, $card, $variant, state: 'a' === $variant ? WorkerRunState::Failed : WorkerRunState::Succeeded);
                $this->seedUsage($em, $run, costUsd: '2.000000');
            }
        }
        $link = PublicLink::forExperiment($project, 'model-test', $note);
        $em->persist($link);
        $em->flush();

        return $link;
    }

    /** The test environment prints the exception, so drop the object ids, the source lines and the lines of the handler and of this file. */
    private function withoutNoise(string $body): string
    {
        $lines = array_filter(explode("\n", $body), static fn (string $line): bool => !str_contains($line, 'ShowPublicExperimentControllerTest') && !str_contains($line, 'ShowPublicLinkHandler') && !str_contains($line, '›') && !str_contains($line, '#line'));

        return (string) preg_replace('/\{#\d+/', '{#', implode("\n", $lines));
    }

    private function client(string $siteHost): KernelBrowser
    {
        $_ENV['SITE_HOST'] = $_SERVER['SITE_HOST'] = $siteHost;
        $client = static::createClient();
        $client->disableReboot();
        InstalledInstance::ensure(static::getContainer());

        return $client;
    }
}
