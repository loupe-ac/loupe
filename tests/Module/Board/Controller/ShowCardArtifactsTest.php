<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Controller;

use App\Tests\Module\Artifact\ArtifactScenario;
use App\Tests\Module\Artifact\LinkedCardScenario;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class ShowCardArtifactsTest extends WebTestCase
{
    use ArtifactScenario;
    use LinkedCardScenario;

    public function test_the_card_page_lists_its_artifacts_with_the_latest_version(): void
    {
        $client = static::createClient();
        $project = $this->switchedOnProject('card-artifacts@example.com');
        $artifact = $this->storedArtifact($project, 'Review page, Direction C', [['index.html' => 'a'], ['index.html' => 'b'], ['index.html' => 'c']]);
        $card = $this->linkedCard($project, $artifact, 'Redesign the review page', 611);
        [$projectId, $artifactId, $cardId] = [(string) $project->id, (string) $artifact->id, (string) $card->id];
        $this->em()->clear();

        $client->loginUser($project->owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/board/cards/'.$cardId);

        self::assertResponseIsSuccessful();
        $row = $crawler->filter('[data-card-artifacts] [data-card-artifact="'.$artifactId.'"]');
        self::assertCount(1, $row);
        self::assertStringContainsString('Review page, Direction C', $row->text());
        self::assertStringContainsString('v3 · latest', $row->text());
        self::assertSame('/projects/'.$projectId.'/artifacts/'.$artifactId, $row->filter('a')->attr('href'));
    }

    public function test_the_card_page_hides_artifacts_while_the_flag_is_off(): void
    {
        $client = static::createClient();
        $project = $this->switchedOnProject('card-artifacts-off@example.com');
        $artifact = $this->storedArtifact($project, 'Hidden', [['index.html' => 'a']]);
        $card = $this->linkedCard($project, $artifact, 'A card', 1);
        [$projectId, $cardId] = [(string) $project->id, (string) $card->id];
        $this->em()->getConnection()->executeStatement("UPDATE feature_flag SET value = 'false' WHERE name = 'artifacts.enabled'");
        $this->em()->clear();

        $client->loginUser($project->owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/board/cards/'.$cardId);

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-card-artifacts]'));
    }
}
