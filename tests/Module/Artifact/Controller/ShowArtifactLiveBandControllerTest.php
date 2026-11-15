<?php

declare(strict_types=1);

namespace App\Tests\Module\Artifact\Controller;

use App\Module\Artifact\Entity\Artifact;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Artifact\ArtifactScenario;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;

final class ShowArtifactLiveBandControllerTest extends WebTestCase
{
    use ArtifactScenario;

    private const string STREAM = 'text/vnd.turbo-stream.html';

    public function test_a_page_on_an_old_version_gets_the_newer_version_band(): void
    {
        [$client, $project, $artifact] = $this->opened('band-newer@example.com', [['index.html' => 'one'], ['index.html' => 'two']]);

        $crawler = $this->band($client, $project, $artifact, 'shown=1&archived=0');

        self::assertResponseIsSuccessful();
        self::assertStringStartsWith(self::STREAM, (string) $client->getResponse()->headers->get('Content-Type'));
        self::assertSame('live-band', $crawler->filter('turbo-stream')->attr('target'));
        self::assertStringContainsString('Version 2 is out.', $crawler->filter('[data-artifact-live-version]')->text());
        self::assertSame('Show version 2', trim($crawler->filter('[data-artifact-live-show-version]')->text()));
        self::assertStringEndsWith('/artifacts/'.$artifact->id.'/versions/2', (string) $crawler->filter('[data-artifact-live-show-version]')->attr('href'));
        self::assertCount(0, $crawler->filter('[data-artifact-live-archived]'));
    }

    public function test_the_full_screen_page_links_to_the_full_screen_latest(): void
    {
        [$client, $project, $artifact] = $this->opened('band-full@example.com', [['index.html' => 'one'], ['index.html' => 'two']]);

        $crawler = $this->band($client, $project, $artifact, 'shown=1&full=1');

        self::assertStringEndsWith('/artifacts/'.$artifact->id.'/versions/2/full-screen', (string) $crawler->filter('[data-artifact-live-show-version]')->attr('href'));
    }

    public function test_a_page_on_the_latest_version_gets_an_empty_band(): void
    {
        [$client, $project, $artifact] = $this->opened('band-current@example.com', [['index.html' => 'one']]);

        $crawler = $this->band($client, $project, $artifact, 'shown=1');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('turbo-stream[action="update"][target="live-band"]'));
        self::assertCount(0, $crawler->filter('.lp-flash'));
    }

    public function test_an_archived_artifact_shows_the_archive_band_with_an_unarchive_form(): void
    {
        [$client, $project, $artifact] = $this->opened('band-archived@example.com', [['index.html' => 'one']], archived: true);

        $crawler = $this->band($client, $project, $artifact, 'shown=1&archived=0');

        self::assertStringContainsString('This artifact was archived.', $crawler->filter('[data-artifact-live-archived]')->text());
        self::assertCount(1, $crawler->filter('form[action$="/'.$artifact->id.'/unarchive"]'));
        self::assertCount(0, $crawler->filter('[data-artifact-live-restored]'));
    }

    public function test_a_restored_artifact_shows_the_restored_band(): void
    {
        [$client, $project, $artifact] = $this->opened('band-restored@example.com', [['index.html' => 'one']]);

        $crawler = $this->band($client, $project, $artifact, 'shown=1&archived=1');

        self::assertStringContainsString('This artifact was restored.', $crawler->filter('[data-artifact-live-restored]')->text());
        self::assertCount(0, $crawler->filter('[data-artifact-live-archived]'));
    }

    public function test_a_page_that_already_shows_the_archive_state_gets_no_archive_band(): void
    {
        [$client, $project, $artifact] = $this->opened('band-same@example.com', [['index.html' => 'one']], archived: true);

        $crawler = $this->band($client, $project, $artifact, 'shown=1&archived=1');

        self::assertCount(0, $crawler->filter('.lp-flash'));
    }

    public function test_a_plain_request_redirects_to_the_page(): void
    {
        [$client, $project, $artifact] = $this->opened('band-plain@example.com', [['index.html' => 'one']]);

        $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/artifacts/'.$artifact->id.'/live-band?shown=1');

        self::assertResponseRedirects('/projects/'.$project->id.'/artifacts/'.$artifact->id);
    }

    public function test_another_user_is_refused(): void
    {
        [$client, $project, $artifact] = $this->opened('band-owner@example.com', [['index.html' => 'one']]);
        $stranger = $this->project($this->em(), 'band-stranger@example.com', 'Other');
        $client->loginUser($stranger->owner);

        $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/artifacts/'.$artifact->id.'/live-band?shown=1', server: ['HTTP_ACCEPT' => self::STREAM]);

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * @param non-empty-string            $email
     * @param list<array<string, string>> $versions
     *
     * @return array{KernelBrowser, Project, Artifact}
     */
    private function opened(string $email, array $versions, bool $archived = false): array
    {
        $client = static::createClient();
        $project = $this->switchedOnProject($email);
        $artifact = $this->storedArtifact($project, 'Widget tokens', $versions, $project->owner);
        if ($archived) {
            $artifact->archivedAt = new \DateTimeImmutable();
            $this->em()->flush();
        }
        $client->loginUser($project->owner);

        return [$client, $project, $artifact];
    }

    private function band(KernelBrowser $client, Project $project, Artifact $artifact, string $query): Crawler
    {
        return $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/artifacts/'.$artifact->id.'/live-band?'.$query, server: ['HTTP_ACCEPT' => self::STREAM]);
    }
}
