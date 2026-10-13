<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Controller;

use App\Module\Review\Entity\Document;
use App\Tests\Module\Artifact\ArtifactScenario;
use App\Tests\Module\Artifact\LinkedCardScenario;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class ShowDocumentArtifactMenuTest extends WebTestCase
{
    use ArtifactScenario;
    use LinkedCardScenario;

    public function test_the_page_menu_lists_the_artifacts_of_the_linked_cards_once_each(): void
    {
        $client = static::createClient();
        $project = $this->switchedOnProject('menu-artifacts@example.com');
        $artifact = $this->storedArtifact($project, 'Inbox prototype', [['index.html' => 'a'], ['index.html' => 'b']]);
        $first = $this->linkedCard($project, $artifact, 'First card', 706);
        $second = $this->linkedCard($project, $artifact, 'Second card', 707);
        $document = $this->linkedDocument($project, $first, $second);
        [$projectId, $artifactId, $documentId] = [(string) $project->id, (string) $artifact->id, (string) $document->id];
        $this->em()->clear();

        $client->loginUser($project->owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents/'.$documentId.'/review');

        self::assertResponseIsSuccessful();
        $row = $crawler->filter('#review-page-menu [data-page-menu-group="artifact"] .lp-page-menu__row');
        self::assertCount(1, $row);
        self::assertSame('Inbox prototype', trim($row->filter('.lp-page-menu__label')->text()));
        self::assertSame('Artifact · v2 · from #706', trim($row->filter('.lp-page-menu__line')->text()));
        self::assertSame('/projects/'.$projectId.'/artifacts/'.$artifactId, $row->attr('href'));
    }

    public function test_the_page_menu_marks_an_archived_artifact(): void
    {
        $client = static::createClient();
        $project = $this->switchedOnProject('menu-artifacts-archived@example.com');
        $artifact = $this->storedArtifact($project, 'Old prototype', [['index.html' => 'a']]);
        $artifact->archivedAt = new \DateTimeImmutable();
        $card = $this->linkedCard($project, $artifact, 'A card', 1);
        $document = $this->linkedDocument($project, $card);
        [$projectId, $documentId] = [(string) $project->id, (string) $document->id];
        $this->em()->clear();

        $client->loginUser($project->owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents/'.$documentId.'/review');

        self::assertSame('Artifact · Archived · from #1', trim($crawler->filter('#review-page-menu [data-page-menu-group="artifact"] .lp-page-menu__line')->text()));
    }

    public function test_the_page_menu_hides_artifacts_while_the_flag_is_off(): void
    {
        $client = static::createClient();
        $project = $this->switchedOnProject('menu-artifacts-off@example.com');
        $artifact = $this->storedArtifact($project, 'Hidden', [['index.html' => 'a']]);
        $card = $this->linkedCard($project, $artifact, 'A card', 1);
        $document = $this->linkedDocument($project, $card);
        [$projectId, $documentId] = [(string) $project->id, (string) $document->id];
        $this->em()->getConnection()->executeStatement("UPDATE feature_flag SET value = 'false' WHERE name = 'artifacts.enabled'");
        $this->em()->clear();

        $client->loginUser($project->owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents/'.$documentId.'/review');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('#review-page-menu [data-page-menu-group="artifact"]'));
    }

    private function linkedDocument(\App\Module\Project\Entity\Project $project, \App\Module\Board\Entity\Card ...$cards): Document
    {
        $document = new Document(owner: $project->owner, project: $project, title: 'Linked document');
        $document->addVersion('# Linked', '<h1>Linked</h1>');
        $this->em()->persist($document);
        $this->em()->flush();
        foreach ($cards as $card) {
            $card->syncDocuments($document);
        }
        $this->em()->flush();

        return $document;
    }
}
