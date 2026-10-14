<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Controller;

use App\Module\Board\Twig\BoardExtension;
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
        foreach ([[$first, '2026-01-01'], [$second, '2026-01-02']] as [$card, $at]) {
            $this->em()->getConnection()->executeStatement('UPDATE board_card_documents SET linked_at = ? WHERE card_id = ?', [$at, (string) $card->id]);
        }
        [$projectId, $artifactId, $documentId] = [(string) $project->id, (string) $artifact->id, (string) $document->id];
        $this->em()->clear();

        $client->loginUser($project->owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents/'.$documentId.'/review');

        self::assertResponseIsSuccessful();
        $row = $crawler->filter('#review-page-menu [data-page-menu-group="artifact"] .lp-menu__item');
        self::assertCount(1, $row);
        self::assertSame('Inbox prototype', trim($row->filter('.lp-menu__label')->text()));
        self::assertSame('Artifact · v2 · from #706', trim($row->filter('.lp-menu__line')->text()));
        self::assertSame('/projects/'.$projectId.'/artifacts/'.$artifactId, $row->attr('href'));
    }

    public function test_the_artifact_links_follow_card_order_then_link_order(): void
    {
        static::createClient();
        $project = $this->switchedOnProject('menu-artifacts-order@example.com');
        $shared = $this->storedArtifact($project, 'Shared', [['index.html' => 's']]);
        $alpha = $this->storedArtifact($project, 'Alpha', [['index.html' => 'a']]);
        $beta = $this->storedArtifact($project, 'Beta', [['index.html' => 'b']]);
        $gamma = $this->storedArtifact($project, 'Gamma', [['index.html' => 'c']]);
        $first = $this->linkedCard($project, $alpha, 'First card', 1);
        $first->linkArtifact($shared);
        $second = $this->linkedCard($project, $beta, 'Second card', 2);
        $second->linkArtifact($gamma);
        $second->linkArtifact($shared);
        $this->em()->flush();
        $document = $this->linkedDocument($project, $first, $second);
        $connection = $this->em()->getConnection();
        foreach ([[$second, '2026-01-01'], [$first, '2026-01-02']] as [$card, $at]) {
            $connection->executeStatement('UPDATE board_card_documents SET linked_at = ? WHERE card_id = ?', [$at, (string) $card->id]);
        }
        foreach ([[$second, $gamma, '2026-01-01'], [$second, $beta, '2026-01-02'], [$second, $shared, '2026-01-03'], [$first, $shared, '2026-01-01'], [$first, $alpha, '2026-01-02']] as [$card, $artifact, $at]) {
            $connection->executeStatement('UPDATE board_card_artifacts SET linked_at = ? WHERE card_id = ? AND artifact_id = ?', [$at, (string) $card->id, (string) $artifact->id]);
        }
        $documentId = $document->id;
        $this->em()->clear();

        $extension = static::getContainer()->get(BoardExtension::class);
        self::assertInstanceOf(BoardExtension::class, $extension);
        $entries = $extension->documentArtifactLinks($this->em()->find(Document::class, $documentId) ?? self::fail('No document'));

        self::assertSame(
            [['Gamma', 2], ['Beta', 2], ['Shared', 2], ['Alpha', 1]],
            array_map(static fn (array $entry): array => [$entry['link']->artifact->name, $entry['link']->card->number], $entries),
        );
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

        self::assertSame('Artifact · Archived · from #1', trim($crawler->filter('#review-page-menu [data-page-menu-group="artifact"] .lp-menu__line')->text()));
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
