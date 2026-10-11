<?php

declare(strict_types=1);

namespace App\Tests\Module\Artifact\Controller;

use App\Tests\Module\Artifact\ArtifactScenario;
use App\Tests\Module\Artifact\LinkedCardScenario;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class ShowArtifactControllerTest extends WebTestCase
{
    use ArtifactScenario;
    use LinkedCardScenario;

    private const string BAND = 'Limited preview: some interactive parts may not work. Your operator can set a content domain for the full view.';

    public function test_the_stable_link_opens_the_latest_version_in_a_sealed_frame(): void
    {
        $client = static::createClient();
        $project = $this->switchedOnProject('show-latest@example.com');
        $artifact = $this->storedArtifact($project, 'Widget tokens', [['index.html' => 'one'], ['index.html' => 'two', 'app.js' => 'x']], $project->owner);
        [$projectId, $artifactId] = [(string) $project->id, (string) $artifact->id];
        $latestId = (string) $this->versionNumbered($artifact, 2)->id;
        $this->em()->clear();

        $client->loginUser($project->owner);
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/projects/'.$projectId.'/artifacts/'.$artifactId);

        self::assertResponseIsSuccessful();
        self::assertSame('Widget tokens', trim($crawler->filter('[data-artifact-name]')->text()));
        self::assertSame('v2 · latest', trim($crawler->filter('[data-artifact-current-version]')->text()));
        $frame = $crawler->filter('iframe[data-artifact-frame]');
        self::assertCount(1, $frame);
        self::assertSame('allow-scripts allow-forms allow-popups allow-pointer-lock', $frame->attr('sandbox'));
        self::assertMatchesRegularExpression('#^/artifact-content/'.$latestId.'/\d+/[a-f0-9]{64}/index\.html$#', (string) $frame->attr('src'));
        self::assertSame(self::BAND, $this->bandText($crawler));
        self::assertSame('Dismiss', trim($crawler->filter('[data-artifact-band] .lp-flash__dismiss')->text()));
        self::assertSame(
            ['/projects/'.$projectId.'/artifacts/'.$artifactId.'/versions/2', '/projects/'.$projectId.'/artifacts/'.$artifactId.'/versions/1'],
            $crawler->filter('[data-artifact-version-link]')->each(static fn ($link): string => (string) $link->attr('href')),
        );
        self::assertSame('/projects/'.$projectId.'/artifacts/'.$artifactId.'/versions/2/download', $crawler->filter('[data-artifact-download]')->attr('href'));
    }

    public function test_a_pinned_link_opens_that_version_and_copies_the_pinned_link(): void
    {
        $client = static::createClient();
        $project = $this->switchedOnProject('show-pinned@example.com');
        $artifact = $this->storedArtifact($project, 'Widget tokens', [['index.html' => 'one'], ['index.html' => 'two']]);
        [$projectId, $artifactId] = [(string) $project->id, (string) $artifact->id];
        $firstId = (string) $this->versionNumbered($artifact, 1)->id;
        $this->em()->clear();
        $client->loginUser($project->owner);

        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/projects/'.$projectId.'/artifacts/'.$artifactId.'/versions/1');
        self::assertResponseIsSuccessful();
        self::assertSame('v1', trim($crawler->filter('[data-artifact-current-version]')->text()));
        self::assertStringContainsString('/artifact-content/'.$firstId.'/', (string) $crawler->filter('iframe')->attr('src'));
        self::assertStringEndsWith('/projects/'.$projectId.'/artifacts/'.$artifactId.'/versions/1', (string) $crawler->filter('[data-controller="clipboard"]')->attr('data-clipboard-text-value'));

        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/projects/'.$projectId.'/artifacts/'.$artifactId);
        self::assertStringEndsWith('/projects/'.$projectId.'/artifacts/'.$artifactId, (string) $crawler->filter('[data-controller="clipboard"]')->attr('data-clipboard-text-value'));
    }

    public function test_the_frame_opens_the_first_html_file_then_the_first_file_when_there_is_no_index(): void
    {
        $client = static::createClient();
        $project = $this->switchedOnProject('show-entry@example.com');
        $pages = $this->storedArtifact($project, 'Pages', [['a.css' => 'x', 'start.html' => '<p>']]);
        $plain = $this->storedArtifact($project, 'Plain', [['notes.txt' => 'x', 'other.txt' => 'y']]);
        [$projectId, $pagesId, $plainId] = [(string) $project->id, (string) $pages->id, (string) $plain->id];
        $this->em()->clear();
        $client->loginUser($project->owner);

        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/projects/'.$projectId.'/artifacts/'.$pagesId);
        self::assertStringEndsWith('/start.html', (string) $crawler->filter('iframe')->attr('src'));

        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/projects/'.$projectId.'/artifacts/'.$plainId);
        self::assertStringEndsWith('/notes.txt', (string) $crawler->filter('iframe')->attr('src'));
    }

    public function test_the_bar_lists_the_linked_cards(): void
    {
        $client = static::createClient();
        $project = $this->switchedOnProject('show-cards@example.com');
        $artifact = $this->storedArtifact($project, 'Linked', [['index.html' => 'x']]);
        $card = $this->linkedCard($project, $artifact, 'Build the widget', 7);
        $bare = $this->storedArtifact($project, 'Bare', [['index.html' => 'x']]);
        [$projectId, $artifactId, $bareId, $cardId] = [(string) $project->id, (string) $artifact->id, (string) $bare->id, (string) $card->id];
        $this->em()->clear();
        $client->loginUser($project->owner);

        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/projects/'.$projectId.'/artifacts/'.$artifactId);
        self::assertSame('1 linked card', trim($crawler->filter('[data-artifact-cards] [data-artifact-card-count]')->text()));
        $link = $crawler->filter('[data-artifact-card-link]');
        self::assertCount(1, $link);
        self::assertSame('/projects/'.$projectId.'/board/cards/'.$cardId, $link->attr('href'));
        self::assertStringContainsString('#7', $link->text());
        self::assertStringContainsString('Build the widget', $link->text());

        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/projects/'.$projectId.'/artifacts/'.$bareId);
        self::assertSame('No linked cards', trim($crawler->filter('[data-artifact-cards] [data-artifact-card-count]')->text()));
        self::assertCount(0, $crawler->filter('[data-artifact-card-link]'));
    }

    public function test_the_caption_names_who_stored_the_version_and_links_a_web_source(): void
    {
        $client = static::createClient();
        $project = $this->switchedOnProject('show-source@example.com');
        $web = $this->storedArtifact($project, 'From design', [['index.html' => 'x']], null, 'session-1', 'https://design.example/canvas/1');
        $odd = $this->storedArtifact($project, 'Odd source', [['index.html' => 'x']], $project->owner, null, 'javascript:alert(1)');
        $none = $this->storedArtifact($project, 'No source', [['index.html' => 'x']], $project->owner);
        [$projectId, $webId, $oddId, $noneId] = [(string) $project->id, (string) $web->id, (string) $odd->id, (string) $none->id];
        $this->em()->clear();
        $client->loginUser($project->owner);

        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/projects/'.$projectId.'/artifacts/'.$webId);
        $caption = $crawler->filter('[data-artifact-caption]');
        self::assertStringContainsString('Stored by Claude, interactive session', $caption->text());
        self::assertSame('https://design.example/canvas/1', $caption->filter('[data-artifact-source] a')->attr('href'));
        self::assertSame('noopener noreferrer', $caption->filter('[data-artifact-source] a')->attr('rel'));
        self::assertStringContainsString('Stored on '.date('Y-m-d'), $caption->filter('[data-artifact-source]')->text());

        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/projects/'.$projectId.'/artifacts/'.$oddId);
        self::assertStringContainsString('Stored by Riley Chen', $crawler->filter('[data-artifact-caption]')->text());
        self::assertCount(0, $crawler->filter('[data-artifact-source] a'));
        self::assertStringContainsString('javascript:alert(1)', $crawler->filter('[data-artifact-source]')->text());

        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/projects/'.$projectId.'/artifacts/'.$noneId);
        self::assertCount(0, $crawler->filter('[data-artifact-source]'));
    }

    public function test_an_archived_artifact_still_opens_and_offers_unarchive(): void
    {
        $client = static::createClient();
        $project = $this->switchedOnProject('show-archived@example.com');
        $artifact = $this->storedArtifact($project, 'Shelved', [['index.html' => 'x']]);
        $artifact->archivedAt = new \DateTimeImmutable();
        $this->em()->flush();
        [$projectId, $artifactId] = [(string) $project->id, (string) $artifact->id];
        $this->em()->clear();
        $client->loginUser($project->owner);

        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/projects/'.$projectId.'/artifacts/'.$artifactId);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-artifact-archived]'));
        self::assertCount(1, $crawler->filter('form[data-artifact-unarchive-form][action="/projects/'.$projectId.'/artifacts/'.$artifactId.'/unarchive"]'));
        self::assertCount(0, $crawler->filter('[data-artifact-archive-form]'));
    }

    public function test_an_unknown_version_artifact_or_foreign_project_answers_404(): void
    {
        $client = static::createClient();
        $project = $this->switchedOnProject('show-missing@example.com');
        $sibling = $this->project($this->em(), 'show-missing-sibling@example.com', 'Sibling');
        $artifact = $this->storedArtifact($project, 'Mine', [['index.html' => 'x']]);
        [$projectId, $artifactId, $siblingId] = [(string) $project->id, (string) $artifact->id, (string) $sibling->id];
        $this->em()->clear();
        $client->loginUser($project->owner);

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/projects/'.$projectId.'/artifacts/'.$artifactId.'/versions/2');
        self::assertResponseStatusCodeSame(404);

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/projects/'.$projectId.'/artifacts/'.$artifactId.'/versions/0');
        self::assertResponseStatusCodeSame(404);

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/projects/'.$projectId.'/artifacts/0198f3a0-0000-7000-8000-000000000009');
        self::assertResponseStatusCodeSame(404);

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/projects/'.$projectId.'/artifacts/not-a-uuid');
        self::assertResponseStatusCodeSame(404);

        // The artifact exists, but not in the project named in the path.
        $client->loginUser($sibling->owner);
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/projects/'.$siblingId.'/artifacts/'.$artifactId);
        self::assertResponseStatusCodeSame(404);
    }

    public function test_another_user_gets_a_403_and_a_switched_off_flag_a_404(): void
    {
        $client = static::createClient();
        $project = $this->switchedOnProject('show-guard@example.com');
        $stranger = $this->project($this->em(), 'show-guard-stranger@example.com', 'Stranger');
        $artifact = $this->storedArtifact($project, 'Mine', [['index.html' => 'x']]);
        [$projectId, $artifactId] = [(string) $project->id, (string) $artifact->id];
        $this->em()->clear();

        $client->loginUser($stranger->owner);
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/projects/'.$projectId.'/artifacts/'.$artifactId);
        self::assertResponseStatusCodeSame(403);

        $this->em()->getConnection()->executeStatement("UPDATE feature_flag SET value = 'false' WHERE name = 'artifacts.enabled'");
        $client->loginUser($project->owner);
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/projects/'.$projectId.'/artifacts/'.$artifactId);
        self::assertResponseStatusCodeSame(404);
    }

    private function bandText(Crawler $crawler): string
    {
        return trim(preg_replace('/\s+/', ' ', $crawler->filter('[data-artifact-band] .lp-flash__message')->text()) ?? '');
    }
}
