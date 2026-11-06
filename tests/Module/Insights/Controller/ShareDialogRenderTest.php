<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Controller;

use App\Module\Insights\Entity\PublicLink;
use App\Module\Insights\Service\PublicShareBlock;
use App\Module\Insights\View\PublicLinkShareView;
use App\Tests\Module\Insights\InsightsScenario;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;
use Ubermuda\FeatureFlagsBundle\Repository\FeatureFlagRepository;

final class ShareDialogRenderTest extends WebTestCase
{
    use InsightsScenario;

    public function test_a_tile_that_was_never_shared_offers_share_and_a_closed_switch_with_no_link(): void
    {
        $client = static::createClient();
        $project = $this->scenarioProject('render-never');
        $graph = $this->seedSavedGraph($project);
        $path = $this->tilePath((string) $project->id, (string) $graph->id);
        $this->em()->clear();

        $client->loginUser($project->owner);
        $crawler = $client->request(Request::METHOD_GET, $path);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Share…', $crawler->filter('[data-action="click->graph-tile#share"]')->text());
        $dialog = $crawler->filter('[data-share-dialog] dialog');
        self::assertCount(1, $dialog);
        self::assertSame('Share this graph', trim($dialog->filter('.lp-dialog-title')->text()));
        self::assertCount(1, $dialog->filter('input.lp-switch[role="switch"]'));
        self::assertCount(0, $dialog->filter('input.lp-switch[checked]'));
        self::assertCount(0, $dialog->filter('input[readonly]'));
        self::assertCount(0, $crawler->filter('[data-graph-public] .lp-tag'));
        self::assertStringContainsString('Account names show as Account 1, Account 2.', $dialog->text());
    }

    public function test_a_shared_tile_shows_the_link_the_copy_button_the_note_and_the_public_tag(): void
    {
        $client = static::createClient();
        $project = $this->scenarioProject('render-shared');
        $graph = $this->seedSavedGraph($project);
        $link = PublicLink::forGraph($graph);
        $this->em()->persist($link);
        $this->em()->flush();
        $code = $link->code;
        $path = $this->tilePath((string) $project->id, (string) $graph->id);
        $graphId = (string) $graph->id;
        $this->em()->clear();

        $client->loginUser($project->owner);
        $crawler = $client->request(Request::METHOD_GET, $path);

        $dialog = $crawler->filter('[data-share-dialog] dialog');
        self::assertCount(1, $dialog->filter('input.lp-switch[checked]'));
        $input = $dialog->filter('input[readonly]');
        self::assertCount(1, $input);
        self::assertMatchesRegularExpression('#^https?://[^/]+/s/'.$code.'$#', (string) $input->attr('value'));
        self::assertSame('Public link', $input->attr('aria-label'));
        self::assertSame($input->attr('value'), $dialog->filter('[data-clipboard-text-value]')->attr('data-clipboard-text-value'));
        self::assertSame('Copy link', trim($dialog->filter('[data-clipboard-target="button"]')->text()));
        self::assertStringContainsString('Turn the switch off and the link shows "not found". Turn it on again and the same link works again.', $dialog->text());
        self::assertSame('Done', trim($dialog->filter('button[data-action="click->modal#close"]')->text()));
        self::assertSame('Public', trim($crawler->filter('#dashboard-graph-'.$graphId.' [data-graph-public] .lp-tag')->text()));
    }

    public function test_a_link_that_is_switched_off_keeps_its_address_and_shows_no_tag(): void
    {
        $client = static::createClient();
        $project = $this->scenarioProject('render-off');
        $graph = $this->seedSavedGraph($project);
        $link = PublicLink::forGraph($graph);
        $link->enabled = false;
        $this->em()->persist($link);
        $this->em()->flush();
        $path = $this->tilePath((string) $project->id, (string) $graph->id);
        $this->em()->clear();

        $client->loginUser($project->owner);
        $crawler = $client->request(Request::METHOD_GET, $path);

        $dialog = $crawler->filter('[data-share-dialog] dialog');
        self::assertCount(0, $dialog->filter('input.lp-switch[checked]'));
        self::assertCount(1, $dialog->filter('input[readonly]'));
        self::assertCount(0, $crawler->filter('[data-graph-public] .lp-tag'));
    }

    public function test_the_flag_off_hides_share_and_the_dialog(): void
    {
        $client = static::createClient();
        $project = $this->scenarioProject('render-flag');
        $graph = $this->seedSavedGraph($project);
        $this->em()->persist(PublicLink::forGraph($graph));
        $flags = static::getContainer()->get(FeatureFlagRepository::class);
        self::assertInstanceOf(FeatureFlagRepository::class, $flags);
        $flags->findAllIndexed()[PublicLink::FLAG]->value = false;
        $this->em()->flush();
        $path = $this->tilePath((string) $project->id, (string) $graph->id);
        $this->em()->clear();

        $client->loginUser($project->owner);
        $crawler = $client->request(Request::METHOD_GET, $path);

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-action="click->graph-tile#share"]'));
        self::assertCount(0, $crawler->filter('[data-share-dialog]'));
        self::assertCount(0, $crawler->filter('[data-graph-public] .lp-tag'));
    }

    public function test_a_takedown_keeps_share_shows_the_reason_and_disables_the_switch(): void
    {
        $client = static::createClient();
        $project = $this->scenarioProject('render-takedown');
        $graph = $this->seedSavedGraph($project);
        $this->em()->persist(PublicLink::forGraph($graph));
        $this->em()->flush();
        $this->seedTakedown($project);
        $path = $this->tilePath((string) $project->id, (string) $graph->id);
        $this->em()->clear();

        $client->loginUser($project->owner);
        $crawler = $client->request(Request::METHOD_GET, $path);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-action="click->graph-tile#share"]'));
        $dialog = $crawler->filter('[data-share-dialog] dialog');
        self::assertCount(1, $dialog);
        self::assertStringContainsString('An admin took down the public links of this project.', $dialog->text());
        self::assertCount(1, $dialog->filter('input.lp-switch[role="switch"][disabled]'));
        self::assertCount(0, $crawler->filter('[data-graph-public] .lp-tag'));
    }

    public function test_the_dashboard_tells_the_owner_about_a_takedown_and_only_then(): void
    {
        $client = static::createClient();
        $project = $this->scenarioProject('render-notice');
        $this->seedSavedGraph($project);
        $projectId = (string) $project->id;
        $this->em()->clear();
        $client->loginUser($project->owner);

        $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/analytics/dashboard');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-public-links-taken-down]');

        $this->seedTakedown($this->em()->find($project::class, $projectId) ?? throw new \LogicException());
        $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/analytics/dashboard');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-public-links-taken-down]', 'An admin took down the public links of this project.');
    }

    public function test_the_dashboard_notice_stays_while_the_instance_flag_is_off(): void
    {
        $client = static::createClient();
        $project = $this->scenarioProject('render-notice-flag-off');
        $this->seedSavedGraph($project);
        $flags = static::getContainer()->get(FeatureFlagRepository::class);
        self::assertInstanceOf(FeatureFlagRepository::class, $flags);
        $flags->findAllIndexed()[PublicLink::FLAG]->value = false;
        $this->em()->flush();
        $this->seedTakedown($project);
        $projectId = (string) $project->id;
        $this->em()->clear();
        $client->loginUser($project->owner);

        $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/analytics/dashboard');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-public-links-taken-down]', 'An admin took down the public links of this project.');
    }

    public function test_a_blocked_share_disables_the_switch_and_says_why(): void
    {
        $client = static::createClient();
        $project = $this->scenarioProject('render-blocked');
        $graph = $this->seedSavedGraph($project);
        $this->em()->clear();
        $client->loginUser($project->owner);
        $client->request(Request::METHOD_GET, $this->tilePath((string) $project->id, (string) $graph->id));
        $twig = static::getContainer()->get(Environment::class);
        self::assertInstanceOf(Environment::class, $twig);

        $html = $twig->render('@Insights/_share_dialog_body.html.twig', [
            'graph' => $this->em()->find($graph::class, $graph->id),
            'share' => new PublicLinkShareView(null, PublicShareBlock::FlagOff),
            'error' => null,
        ]);

        self::assertStringContainsString('Public links are switched off on this instance.', $html);
        self::assertMatchesRegularExpression('/<input[^>]*disabled="disabled"[^>]*role="switch"/', $html);
    }

    private function tilePath(string $projectId, string $graphId): string
    {
        return '/projects/'.$projectId.'/analytics/dashboard/graphs/'.$graphId;
    }
}
