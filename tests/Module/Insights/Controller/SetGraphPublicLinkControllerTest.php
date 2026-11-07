<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Controller;

use App\Module\Insights\Entity\InsightsSavedGraph;
use App\Module\Insights\Entity\PublicLink;
use App\Module\Insights\Repository\PublicLinkRepository;
use App\Tests\Module\Insights\InsightsScenario;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Ubermuda\FeatureFlagsBundle\Repository\FeatureFlagRepository;

final class SetGraphPublicLinkControllerTest extends WebTestCase
{
    use InsightsScenario;

    private const array HEADERS = ['HTTP_REFERER' => 'http://localhost/'];

    private const array STREAM = ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html'];

    public function test_the_owner_turns_the_link_on_and_returns_to_the_dashboard(): void
    {
        $client = static::createClient();
        $project = $this->scenarioProject('share-on');
        $graph = $this->seedSavedGraph($project);
        $projectId = (string) $project->id;
        $graphId = (string) $graph->id;
        $this->em()->clear();

        $client->loginUser($project->owner);
        $client->request(Request::METHOD_POST, $this->path($projectId, $graphId), $this->fields($graphId, true), [], self::HEADERS);

        self::assertResponseRedirects('/projects/'.$projectId.'/analytics/dashboard');
        $link = $this->linkOf($graphId);
        self::assertNotNull($link);
        self::assertTrue($link->enabled);
    }

    public function test_the_owner_turns_the_link_off_and_the_code_stays(): void
    {
        $client = static::createClient();
        $project = $this->scenarioProject('share-off');
        $graph = $this->seedSavedGraph($project);
        $this->em()->persist(PublicLink::forGraph($graph));
        $this->em()->flush();
        $projectId = (string) $project->id;
        $graphId = (string) $graph->id;
        $code = $this->linkOf($graphId)?->code;
        $this->em()->clear();

        $client->loginUser($project->owner);
        $client->request(Request::METHOD_POST, $this->path($projectId, $graphId), $this->fields($graphId, false), [], self::HEADERS);

        self::assertResponseRedirects('/projects/'.$projectId.'/analytics/dashboard');
        self::assertFalse($this->linkOf($graphId)?->enabled);
        self::assertSame($code, $this->linkOf($graphId)->code);
    }

    public function test_a_turbo_request_gets_a_stream_with_the_link_and_the_tag(): void
    {
        $client = static::createClient();
        $project = $this->scenarioProject('share-stream');
        $graph = $this->seedSavedGraph($project);
        $projectId = (string) $project->id;
        $graphId = (string) $graph->id;
        $this->em()->clear();

        $client->loginUser($project->owner);
        $client->request(Request::METHOD_POST, $this->path($projectId, $graphId), $this->fields($graphId, true), [], self::HEADERS + self::STREAM);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/vnd.turbo-stream.html; charset=UTF-8');
        $content = (string) $client->getResponse()->getContent();
        $link = $this->linkOf($graphId);
        self::assertNotNull($link);
        $code = $link->code;
        self::assertStringContainsString('action="replace" method="morph" target="share-graph-body-'.$graphId.'"', $content);
        self::assertStringContainsString('/s/'.$code, $content);
        self::assertStringContainsString('Copy link', $content);
        self::assertStringContainsString('data-graph-public', $content);
        self::assertStringContainsString('lp-tag', $content);
    }

    public function test_switching_off_by_turbo_clears_the_tag_and_keeps_the_link_text(): void
    {
        $client = static::createClient();
        $project = $this->scenarioProject('share-stream-off');
        $graph = $this->seedSavedGraph($project);
        $this->em()->persist(PublicLink::forGraph($graph));
        $this->em()->flush();
        $projectId = (string) $project->id;
        $graphId = (string) $graph->id;
        $this->em()->clear();

        $client->loginUser($project->owner);
        $client->request(Request::METHOD_POST, $this->path($projectId, $graphId), $this->fields($graphId, false), [], self::HEADERS + self::STREAM);

        self::assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('data-graph-public', $content);
        self::assertStringNotContainsString('lp-tag', $content);
    }

    public function test_a_stranger_is_refused_and_a_foreign_graph_is_not_found(): void
    {
        $client = static::createClient();
        $project = $this->scenarioProject('share-stranger');
        $mine = $this->seedSavedGraph($project);
        $theirs = $this->seedSavedGraph($this->scenarioProject('share-theirs'));
        $stranger = $this->user($this->em(), 'share-stranger-'.uniqid().'@example.com');
        $projectId = (string) $project->id;
        $mineId = (string) $mine->id;
        $theirsId = (string) $theirs->id;
        $this->em()->clear();

        $client->loginUser($stranger);
        $client->request(Request::METHOD_POST, $this->path($projectId, $mineId), $this->fields($mineId, true), [], self::HEADERS);
        self::assertResponseStatusCodeSame(403);

        $client->loginUser($project->owner);
        $client->request(Request::METHOD_POST, $this->path($projectId, $theirsId), $this->fields($theirsId, true), [], self::HEADERS);
        self::assertResponseStatusCodeSame(404);

        self::assertNull($this->linkOf($mineId));
        self::assertNull($this->linkOf($theirsId));
    }

    public function test_a_wrong_token_changes_nothing_and_flashes_an_error(): void
    {
        $client = static::createClient();
        $project = $this->scenarioProject('share-csrf');
        $graph = $this->seedSavedGraph($project);
        $projectId = (string) $project->id;
        $graphId = (string) $graph->id;
        $this->em()->clear();

        $client->loginUser($project->owner);
        $client->request(Request::METHOD_POST, $this->path($projectId, $graphId), ['share_graph_'.$graphId => ['enabled' => '1', '_token' => 'wrong']], [], self::HEADERS);

        self::assertResponseRedirects('/projects/'.$projectId.'/analytics/dashboard');
        $session = $client->getRequest()->getSession();
        self::assertInstanceOf(FlashBagAwareSessionInterface::class, $session);
        self::assertSame(['error' => ['Loupe could not change the graph. Try again.']], $session->getFlashBag()->peekAll());
        self::assertNull($this->linkOf($graphId));
    }

    public function test_a_share_with_the_instance_flag_off_creates_nothing_and_says_why(): void
    {
        $client = static::createClient();
        $project = $this->scenarioProject('share-flag');
        $graph = $this->seedSavedGraph($project);
        $flags = static::getContainer()->get(FeatureFlagRepository::class);
        self::assertInstanceOf(FeatureFlagRepository::class, $flags);
        $flags->findAllIndexed()[PublicLink::FLAG]->value = false;
        $this->em()->flush();
        $projectId = (string) $project->id;
        $graphId = (string) $graph->id;
        $this->em()->clear();

        $client->loginUser($project->owner);
        $client->request(Request::METHOD_POST, $this->path($projectId, $graphId), $this->fields($graphId, true), [], self::HEADERS);
        self::assertResponseRedirects('/projects/'.$projectId.'/analytics/dashboard');
        $session = $client->getRequest()->getSession();
        self::assertInstanceOf(FlashBagAwareSessionInterface::class, $session);
        self::assertSame(['error' => ['Public links are switched off on this instance.']], $session->getFlashBag()->peekAll());

        $client->request(Request::METHOD_POST, $this->path($projectId, $graphId), $this->fields($graphId, true), [], self::HEADERS + self::STREAM);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Public links are switched off on this instance.', (string) $client->getResponse()->getContent());
        self::assertNull($this->linkOf($graphId));
    }

    public function test_a_turbo_share_after_a_takedown_fails_and_says_why(): void
    {
        $client = static::createClient();
        $project = $this->scenarioProject('share-takedown');
        $graph = $this->seedSavedGraph($project);
        $this->seedTakedown($project);
        $projectId = (string) $project->id;
        $graphId = (string) $graph->id;
        $this->em()->clear();

        $client->loginUser($project->owner);
        $client->request(Request::METHOD_POST, $this->path($projectId, $graphId), $this->fields($graphId, true), [], self::HEADERS + self::STREAM);

        self::assertResponseStatusCodeSame(422);
        self::assertResponseHeaderSame('Content-Type', 'text/vnd.turbo-stream.html; charset=UTF-8');
        self::assertStringContainsString('An admin took down the public links of this project.', (string) $client->getResponse()->getContent());
        self::assertNull($this->linkOf($graphId));
    }

    public function test_a_get_is_not_allowed(): void
    {
        $client = static::createClient();
        $project = $this->scenarioProject('share-get');
        $graph = $this->seedSavedGraph($project);
        $projectId = (string) $project->id;
        $graphId = (string) $graph->id;
        $this->em()->clear();

        $client->loginUser($project->owner);
        $client->request(Request::METHOD_GET, $this->path($projectId, $graphId));

        self::assertResponseStatusCodeSame(405);
    }

    private function path(string $projectId, string $graphId): string
    {
        return '/projects/'.$projectId.'/analytics/dashboard/graphs/'.$graphId.'/share';
    }

    /** @return array<string, array<string, string>> */
    private function fields(string $graphId, bool $enabled): array
    {
        return ['share_graph_'.$graphId => ($enabled ? ['enabled' => '1'] : []) + ['_token' => 'csrf-token']];
    }

    private function linkOf(string $graphId): ?PublicLink
    {
        $this->em()->clear();
        $graph = $this->em()->find(InsightsSavedGraph::class, $graphId);
        $links = static::getContainer()->get(PublicLinkRepository::class);
        self::assertInstanceOf(PublicLinkRepository::class, $links);

        return null === $graph ? null : $links->findForGraph($graph);
    }
}
