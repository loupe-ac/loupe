<?php

declare(strict_types=1);

namespace App\Tests\Module\Artifact\Controller;

use App\Tests\Module\Artifact\ArtifactScenario;
use App\Tests\Module\Artifact\LinkedCardScenario;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ListArtifactsControllerTest extends WebTestCase
{
    use ArtifactScenario;
    use LinkedCardScenario;

    public function test_the_owner_sees_each_artifact_with_its_latest_version_cards_and_author(): void
    {
        $client = static::createClient();
        $project = $this->switchedOnProject('list-owner@example.com');
        $older = $this->storedArtifact($project, 'Older prototype', [['index.html' => 'a']], $project->owner);
        $newer = $this->storedArtifact($project, 'Newer prototype', [['index.html' => 'b'], ['index.html' => 'c', 'style.css' => 'd']], null, 'session-1', 'https://claude.ai/design/p/abc?file=Options.dc.html');
        $this->linkedCard($project, $newer, 'Polish it', 12);
        $this->linkedCard($project, $newer, 'Build it', 11);
        $projectId = (string) $project->id;
        $newerId = (string) $newer->id;
        $this->em()->clear();

        $client->loginUser($project->owner);
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/projects/'.$projectId.'/artifacts');

        self::assertResponseIsSuccessful();
        $rows = $crawler->filter('[data-artifact-id]');
        self::assertCount(2, $rows);
        $row = $crawler->filter('[data-artifact-id="'.$newerId.'"]');
        self::assertSame('Newer prototype', trim($row->filter('.lp-document-row__title')->text()));
        self::assertSame('v2', trim($row->filter('[data-artifact-version]')->text()));
        self::assertSame('2 files', trim($row->filter('[data-artifact-file-count]')->text()));
        self::assertSame('Claude Design canvas', trim($row->filter('[data-artifact-source]')->text()));
        self::assertSame('#11 +1', trim($row->filter('[data-artifact-card-count]')->text()));
        self::assertSame('Claude, interactive session', trim($row->filter('[data-artifact-stored-by]')->text()));
        self::assertSame('/projects/'.$projectId.'/artifacts/'.$newerId, $row->filter('a.lp-document-row__link')->attr('href'));
        self::assertStringEndsWith('/projects/'.$projectId.'/artifacts/'.$newerId, (string) $row->filter('[data-controller="clipboard"]')->attr('data-clipboard-text-value'));
        $other = $crawler->filter('[data-artifact-id="'.$older->id.'"]');
        self::assertSame('Riley Chen', trim($other->filter('[data-artifact-stored-by]')->text()));
        self::assertSame('—', trim($other->filter('[data-artifact-card-count]')->text()));
        self::assertSame('1 file', trim($other->filter('[data-artifact-file-count]')->text()));
        self::assertSame('—', trim($other->filter('[data-artifact-source]')->text()));
        self::assertSame('2 artifacts', trim($crawler->filter('[data-artifact-count]')->text()));
        self::assertSame('2', trim($crawler->filter('[data-artifact-nav-count]')->text()));
        self::assertCount(0, $crawler->filter('[data-artifact-empty]'));
    }

    public function test_the_newest_stored_version_comes_first(): void
    {
        $client = static::createClient();
        $project = $this->switchedOnProject('list-order@example.com');
        $first = $this->storedArtifact($project, 'First made', [['index.html' => 'a']]);
        $second = $this->storedArtifact($project, 'Second made', [['index.html' => 'b']]);
        $this->em()->getConnection()->executeStatement("UPDATE artifact_versions SET created_at = '2026-01-01 00:00:00' WHERE artifact_id = ?", [(string) $second->id]);
        $this->em()->getConnection()->executeStatement("UPDATE artifact_versions SET created_at = '2026-02-01 00:00:00' WHERE artifact_id = ?", [(string) $first->id]);
        $projectId = (string) $project->id;
        $this->em()->clear();

        $client->loginUser($project->owner);
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/projects/'.$projectId.'/artifacts');

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['First made', 'Second made'],
            $crawler->filter('.lp-document-row__title')->each(static fn ($node): string => trim($node->text())),
        );
    }

    public function test_archived_artifacts_show_only_when_asked_for(): void
    {
        $client = static::createClient();
        $project = $this->switchedOnProject('list-archived@example.com');
        $this->storedArtifact($project, 'Live', [['index.html' => 'a']]);
        $archived = $this->storedArtifact($project, 'Shelved', [['index.html' => 'b']]);
        $archived->archivedAt = new \DateTimeImmutable();
        $this->em()->flush();
        $projectId = (string) $project->id;
        $this->em()->clear();

        $client->loginUser($project->owner);
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/projects/'.$projectId.'/artifacts');
        self::assertCount(1, $crawler->filter('[data-artifact-id]'));
        $toggle = $crawler->filter('input[data-artifact-archived-toggle]');
        self::assertSame('archived', $toggle->attr('name'));
        self::assertNull($toggle->attr('checked'));
        self::assertStringContainsString('Show archived', $crawler->filter('.lp-filter-switch')->text());

        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/projects/'.$projectId.'/artifacts?archived=1');
        self::assertCount(2, $crawler->filter('[data-artifact-id]'));
        self::assertCount(1, $crawler->filter('[data-artifact-archived]'));
        self::assertNotNull($crawler->filter('input[data-artifact-archived-toggle]')->attr('checked'));
        self::assertCount(1, $crawler->filter('form[action$="/'.$archived->id.'/unarchive"] [data-artifact-row-archive]'));
    }

    public function test_archiving_from_a_row_returns_to_the_library_and_keeps_the_archived_view(): void
    {
        $client = static::createClient();
        $project = $this->switchedOnProject('list-row-archive@example.com');
        $artifact = $this->storedArtifact($project, 'Row archive', [['index.html' => 'a']]);
        [$projectId, $artifactId] = [(string) $project->id, (string) $artifact->id];
        $this->em()->clear();

        $client->loginUser($project->owner);
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/projects/'.$projectId.'/artifacts');
        $client->submit($crawler->filter('[data-artifact-row-archive]')->form());
        self::assertResponseRedirects('/projects/'.$projectId.'/artifacts');

        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/projects/'.$projectId.'/artifacts?archived=1');
        $client->submit($crawler->filter('form[action$="/'.$artifactId.'/unarchive"] [data-artifact-row-archive]')->form());
        self::assertResponseRedirects('/projects/'.$projectId.'/artifacts?archived=1');
    }

    public function test_a_project_with_no_artifact_shows_the_empty_state(): void
    {
        $client = static::createClient();
        $project = $this->switchedOnProject('list-empty@example.com');
        $projectId = (string) $project->id;
        $this->em()->clear();

        $client->loginUser($project->owner);
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/projects/'.$projectId.'/artifacts');

        self::assertResponseIsSuccessful();
        self::assertSame('No artifacts yet.', trim($crawler->filter('[data-artifact-empty] .lp-empty-state__title')->text()));
        self::assertCount(0, $crawler->filter('[data-artifact-id]'));
    }

    public function test_another_user_gets_a_403(): void
    {
        $client = static::createClient();
        $project = $this->switchedOnProject('list-mine@example.com');
        $stranger = $this->project($this->em(), 'list-stranger@example.com', 'Stranger');
        $projectId = (string) $project->id;
        $this->em()->clear();

        $client->loginUser($stranger->owner);
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/projects/'.$projectId.'/artifacts');

        self::assertResponseStatusCodeSame(403);
    }

    public function test_it_answers_404_while_the_flag_is_off(): void
    {
        $client = static::createClient();
        $project = $this->project($this->em(), 'list-off@example.com', 'Off');
        $projectId = (string) $project->id;
        $this->em()->clear();

        $client->loginUser($project->owner);
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/projects/'.$projectId.'/artifacts');

        self::assertResponseStatusCodeSame(404);
    }

    public function test_the_sidebar_links_to_the_library_only_while_the_flag_is_on(): void
    {
        $client = static::createClient();
        $project = $this->project($this->em(), 'list-nav@example.com', 'Nav');
        $projectId = (string) $project->id;
        $this->em()->clear();
        $client->loginUser($project->owner);

        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/projects/'.$projectId.'/documents');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('a.lp-sidebar__link[href="/projects/'.$projectId.'/artifacts"]'));

        $this->em()->getConnection()->executeStatement("UPDATE feature_flag SET value = 'true' WHERE name = 'artifacts.enabled'");
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/projects/'.$projectId.'/documents');
        $link = $crawler->filter('a.lp-sidebar__link[href="/projects/'.$projectId.'/artifacts"]');
        self::assertCount(1, $link);
        self::assertSame('Artifacts', trim($link->filter('.lp-sidebar__link-label')->text()));
        self::assertSame('0', trim($link->filter('[data-artifact-nav-count]')->text()));
    }
}
