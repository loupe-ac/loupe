<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Controller;

use App\Module\Bridge\Command\ArchiveExperimentCommand;
use App\Module\Bridge\Command\ArchiveExperimentHandler;
use App\Module\Insights\Entity\PublicLink;
use App\Module\Insights\Repository\PublicLinkRepository;
use App\Module\Insights\Service\ExperimentShareForms;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Insights\InsightsScenario;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Ubermuda\FeatureFlagsBundle\Repository\FeatureFlagRepository;

final class SetExperimentPublicLinkControllerTest extends WebTestCase
{
    use InsightsScenario;

    private const array HEADERS = ['HTTP_REFERER' => 'http://localhost/'];

    private const array STREAM = ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html'];

    public function test_the_owner_shares_with_a_note_and_returns_to_the_experiment(): void
    {
        $client = static::createClient();
        $project = $this->scenarioProject('xshare-on');
        $this->seedExperiment($project, 'trial');
        $projectId = (string) $project->id;
        $this->em()->clear();

        $client->loginUser($project->owner);
        $client->request(Request::METHOD_POST, $this->path($projectId), $this->fields(true, 'Read **this**.'), [], self::HEADERS);

        self::assertResponseRedirects('/projects/'.$projectId.'/analytics/experiments/trial');
        $link = $this->linkOf($projectId);
        self::assertNotNull($link);
        self::assertTrue($link->enabled);
        self::assertSame('Read **this**.', $link->note);
    }

    public function test_the_owner_turns_the_link_off_and_the_code_stays(): void
    {
        $client = static::createClient();
        $project = $this->scenarioProject('xshare-off');
        $this->seedExperiment($project, 'trial');
        $this->em()->persist(PublicLink::forExperiment($project, 'trial', 'Kept.'));
        $this->em()->flush();
        $projectId = (string) $project->id;
        $code = $this->linkOf($projectId)?->code;
        $this->em()->clear();

        $client->loginUser($project->owner);
        $client->request(Request::METHOD_POST, $this->path($projectId), $this->fields(false, 'Kept.'), [], self::HEADERS);

        self::assertResponseRedirects();
        self::assertFalse($this->linkOf($projectId)?->enabled);
        self::assertSame($code, $this->linkOf($projectId)->code);
    }

    public function test_a_turbo_request_gets_a_stream_with_the_link_and_the_tag(): void
    {
        $client = static::createClient();
        $project = $this->scenarioProject('xshare-stream');
        $this->seedExperiment($project, 'trial');
        $projectId = (string) $project->id;
        $this->em()->clear();

        $client->loginUser($project->owner);
        $client->request(Request::METHOD_POST, $this->path($projectId), $this->fields(true, null), [], self::HEADERS + self::STREAM);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/vnd.turbo-stream.html; charset=UTF-8');
        $content = (string) $client->getResponse()->getContent();
        $link = $this->linkOf($projectId);
        self::assertNotNull($link);
        $code = $link->code;
        self::assertStringContainsString('target="share-experiment-body-'.ExperimentShareForms::keyOf('trial').'"', $content);
        self::assertStringContainsString('/s/'.$code, $content);
        self::assertStringContainsString('data-experiment-public=\'trial\'', $content);
        self::assertStringContainsString('lp-tag', $content);
    }

    public function test_switching_off_by_turbo_clears_the_tag(): void
    {
        $client = static::createClient();
        $project = $this->scenarioProject('xshare-stream-off');
        $this->seedExperiment($project, 'trial');
        $this->em()->persist(PublicLink::forExperiment($project, 'trial'));
        $this->em()->flush();
        $projectId = (string) $project->id;
        $this->em()->clear();

        $client->loginUser($project->owner);
        $client->request(Request::METHOD_POST, $this->path($projectId), $this->fields(false, null), [], self::HEADERS + self::STREAM);

        self::assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('data-experiment-public', $content);
        self::assertStringNotContainsString('lp-tag', $content);
    }

    public function test_sharing_an_archived_experiment_unarchives_it_and_reloads_the_page_even_for_turbo(): void
    {
        $client = static::createClient();
        $project = $this->scenarioProject('xshare-archived');
        $this->seedExperiment($project, 'trial');
        $archive = static::getContainer()->get(ArchiveExperimentHandler::class);
        self::assertInstanceOf(ArchiveExperimentHandler::class, $archive);
        $archive(new ArchiveExperimentCommand($project, 'trial'));
        $projectId = (string) $project->id;
        $this->em()->clear();

        $client->loginUser($project->owner);
        $client->request(Request::METHOD_POST, $this->path($projectId), $this->fields(true, null), [], self::HEADERS + self::STREAM);

        self::assertResponseRedirects('/projects/'.$projectId.'/analytics/experiments/trial');
        $client->followRedirect();
        self::assertSelectorNotExists('[data-experiment-archived]');
        self::assertSelectorExists('[data-experiment-public="trial"] .lp-tag');
    }

    public function test_a_stranger_is_refused_and_an_unknown_experiment_is_not_found(): void
    {
        $client = static::createClient();
        $project = $this->scenarioProject('xshare-stranger');
        $this->seedExperiment($project, 'trial');
        $stranger = $this->user($this->em(), 'xshare-stranger-'.uniqid().'@example.com');
        $projectId = (string) $project->id;
        $this->em()->clear();

        $client->loginUser($stranger);
        $client->request(Request::METHOD_POST, $this->path($projectId), $this->fields(true, null), [], self::HEADERS);
        self::assertResponseStatusCodeSame(403);

        $client->loginUser($project->owner);
        $client->request(Request::METHOD_POST, $this->path($projectId, 'ghost'), $this->fields(true, null, 'ghost'), [], self::HEADERS);
        self::assertResponseStatusCodeSame(404);

        self::assertNull($this->linkOf($projectId));
    }

    public function test_a_wrong_token_changes_nothing_and_flashes_an_error(): void
    {
        $client = static::createClient();
        $project = $this->scenarioProject('xshare-csrf');
        $this->seedExperiment($project, 'trial');
        $projectId = (string) $project->id;
        $this->em()->clear();

        $client->loginUser($project->owner);
        $client->request(Request::METHOD_POST, $this->path($projectId), ['share_experiment_'.ExperimentShareForms::keyOf('trial') => ['enabled' => '1', '_token' => 'wrong']], [], self::HEADERS);

        self::assertResponseRedirects('/projects/'.$projectId.'/analytics/experiments/trial');
        $session = $client->getRequest()->getSession();
        self::assertInstanceOf(FlashBagAwareSessionInterface::class, $session);
        self::assertSame(['error' => ['Loupe could not change the sharing of this experiment. Try again.']], $session->getFlashBag()->peekAll());
        self::assertNull($this->linkOf($projectId));
    }

    public function test_a_note_that_is_too_long_is_refused(): void
    {
        $client = static::createClient();
        $project = $this->scenarioProject('xshare-long');
        $this->seedExperiment($project, 'trial');
        $projectId = (string) $project->id;
        $this->em()->clear();

        $client->loginUser($project->owner);
        $client->request(Request::METHOD_POST, $this->path($projectId), $this->fields(true, str_repeat('a', 2001)), [], self::HEADERS + self::STREAM);

        self::assertResponseStatusCodeSame(422);
        self::assertNull($this->linkOf($projectId));
    }

    public function test_a_share_with_the_instance_flag_off_creates_nothing_and_says_why(): void
    {
        $client = static::createClient();
        $project = $this->scenarioProject('xshare-flag');
        $this->seedExperiment($project, 'trial');
        $flags = static::getContainer()->get(FeatureFlagRepository::class);
        self::assertInstanceOf(FeatureFlagRepository::class, $flags);
        $flags->findAllIndexed()[PublicLink::FLAG]->value = false;
        $this->em()->flush();
        $projectId = (string) $project->id;
        $this->em()->clear();

        $client->loginUser($project->owner);
        $client->request(Request::METHOD_POST, $this->path($projectId), $this->fields(true, null), [], self::HEADERS);
        self::assertResponseRedirects();
        $session = $client->getRequest()->getSession();
        self::assertInstanceOf(FlashBagAwareSessionInterface::class, $session);
        self::assertSame(['error' => ['Public links are switched off on this instance.']], $session->getFlashBag()->peekAll());

        $client->request(Request::METHOD_POST, $this->path($projectId), $this->fields(true, null), [], self::HEADERS + self::STREAM);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Public links are switched off on this instance.', (string) $client->getResponse()->getContent());
        self::assertNull($this->linkOf($projectId));
    }

    public function test_a_get_is_not_allowed(): void
    {
        $client = static::createClient();
        $project = $this->scenarioProject('xshare-get');
        $this->seedExperiment($project, 'trial');
        $projectId = (string) $project->id;
        $this->em()->clear();

        $client->loginUser($project->owner);
        $client->request(Request::METHOD_GET, $this->path($projectId));

        self::assertResponseStatusCodeSame(405);
    }

    private function path(string $projectId, string $experiment = 'trial'): string
    {
        return '/projects/'.$projectId.'/analytics/experiments/'.$experiment.'/share';
    }

    /** @return array<string, array<string, string>> */
    private function fields(bool $enabled, ?string $note, string $experiment = 'trial'): array
    {
        return ['share_experiment_'.ExperimentShareForms::keyOf($experiment) => ($enabled ? ['enabled' => '1'] : []) + ['note' => $note ?? '', '_token' => 'csrf-token']];
    }

    private function linkOf(string $projectId): ?PublicLink
    {
        $this->em()->clear();
        $project = $this->em()->find(Project::class, $projectId);
        $links = static::getContainer()->get(PublicLinkRepository::class);
        self::assertInstanceOf(PublicLinkRepository::class, $links);

        return null === $project ? null : $links->findForExperiment($project, 'trial');
    }
}
