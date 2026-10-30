<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Controller;

use App\Tests\Module\Bridge\ExperimentScenario;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class ArchiveExperimentControllerTest extends WebTestCase
{
    use ExperimentScenario;

    private const array HEADERS = ['HTTP_REFERER' => 'http://localhost/'];

    public function test_the_owner_archives_an_experiment_off_the_main_list_and_unarchives_it(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'archive-controller-owner@example.com');
        $project = $this->boardProject($em, $owner);
        $this->experimentRun($em, $project, $this->experimentCard($em, $project, 1), 'a', at: '-3 hours');
        $projectId = (string) $project->id;
        $base = '/projects/'.$projectId.'/analytics/experiments';
        $em->clear();
        $client->loginUser($owner);

        $client->request(Request::METHOD_POST, $base.'/model-test/archive', ['from' => 'list', '_csrf_token' => 'csrf-token'], [], self::HEADERS);
        self::assertResponseRedirects($base);

        $crawler = $client->request(Request::METHOD_GET, $base);
        self::assertCount(0, $crawler->filter('[data-experiment]'));
        self::assertCount(1, $crawler->filter('[data-experiment-archived-toggle]'));

        $crawler = $client->request(Request::METHOD_GET, $base.'?archived=1');
        self::assertCount(1, $crawler->filter('[data-experiment="model-test"]'));
        self::assertCount(1, $crawler->filter('[data-experiment="model-test"] [data-experiment-row-archive]'));

        $crawler = $client->request(Request::METHOD_GET, $base.'/model-test');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-experiment-archived]'));

        $client->request(Request::METHOD_POST, $base.'/model-test/unarchive', ['_csrf_token' => 'csrf-token'], [], self::HEADERS);
        self::assertResponseRedirects($base.'/model-test');

        $crawler = $client->request(Request::METHOD_GET, $base);
        self::assertCount(1, $crawler->filter('[data-experiment="model-test"]'));
        self::assertCount(0, $crawler->filter('[data-experiment-archived-toggle]'));
    }

    public function test_the_archived_filter_keeps_its_switch_when_nothing_is_archived(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'archive-controller-empty@example.com');
        $project = $this->boardProject($em, $owner);
        $projectId = (string) $project->id;
        $em->clear();
        $client->loginUser($owner);

        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/analytics/experiments?archived=1');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-experiment-archived-toggle]'));
        self::assertCount(0, $crawler->filter('[data-experiments-empty]'));
        $back = $crawler->filter('[data-experiments-archived-empty] [data-experiments-archived-empty-back]');
        self::assertCount(1, $back);
        self::assertSame('/projects/'.$projectId.'/analytics/experiments', $back->attr('href'));
    }

    public function test_an_unknown_experiment_flashes_an_error_and_archives_nothing(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'archive-controller-unknown@example.com');
        $project = $this->boardProject($em, $owner);
        $projectId = (string) $project->id;
        $em->clear();
        $client->loginUser($owner);

        $client->request(Request::METHOD_POST, '/projects/'.$projectId.'/analytics/experiments/ghost/archive', ['_csrf_token' => 'csrf-token'], [], self::HEADERS);

        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'nothing to archive');
    }

    public function test_another_users_project_is_refused(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'archive-controller-theirs@example.com');
        $stranger = $this->user($em, 'archive-controller-stranger@example.com');
        $project = $this->boardProject($em, $owner);
        $projectId = (string) $project->id;
        $em->clear();
        $client->loginUser($stranger);

        $client->request(Request::METHOD_POST, '/projects/'.$projectId.'/analytics/experiments/model-test/archive', ['_csrf_token' => 'csrf-token'], [], self::HEADERS);

        self::assertResponseStatusCodeSame(403);
    }
}
