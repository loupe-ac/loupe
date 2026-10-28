<?php

declare(strict_types=1);

namespace App\Tests\Search;

use App\Module\Account\Entity\User;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Project\Entity\Project;
use App\Module\Review\Command\CreateDocumentCommand;
use App\Module\Workflow\Contract\LabelTone;
use App\Module\Review\Command\CreateDocumentHandler;
use App\Search\SearchResult;
use App\Search\SearchResults;
use App\Tests\Support\AcceptedTerms;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class TopbarSearchFrameTest extends WebTestCase
{
    private const array FRAME = ['HTTP_TURBO_FRAME' => 'topbar-search-results'];

    public function test_the_dropdown_frame_lists_a_card_with_its_number_and_column_and_a_document(): void
    {
        [$client, $project] = $this->signedInProject('quartz-frame@example.com');
        $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/search', ['query' => 'quartz'], server: self::FRAME);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Vary', 'Turbo-Frame');
        self::assertSelectorExists('turbo-frame#topbar-search-results');
        self::assertSelectorNotExists('.lp-sidebar');
        self::assertSelectorExists('[data-search-query="quartz"]');
        self::assertSelectorTextContains('[data-search-kind="card"] .lp-board-card__number', '#42');
        self::assertSelectorTextContains('[data-search-kind="card"]', 'Quartz card');
        self::assertSelectorTextContains('[data-search-kind="card"] .lp-tag.lp-tag--teal', 'Next');
        self::assertSelectorExists('[data-search-kind="card"][data-drawer-title="Quartz card"]');
        self::assertSelectorNotExists('[data-card-drawer-opener]');
        self::assertSelectorTextContains('[data-search-kind="document"]', 'Quartz document');
        self::assertSelectorTextContains('a[data-search-all]', 'quartz');
        self::assertSame('/projects/'.$project->id.'/search?query=quartz', $client->getCrawler()->filter('a[data-search-all]')->attr('href'));
    }

    public function test_the_dropdown_frame_takes_rows_round_robin_up_to_ten(): void
    {
        [$client, $project] = $this->signedInProject('round-robin-frame@example.com', documents: 12);
        $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/search', ['query' => 'board'], server: self::FRAME);

        self::assertResponseIsSuccessful();
        $rows = $client->getCrawler()->filter('[data-search-kind]');
        self::assertGreaterThanOrEqual(1, $rows->filter('[data-search-kind="page"]')->count(), 'the page group keeps a row though documents fill the rest');
        self::assertGreaterThanOrEqual(1, $rows->filter('[data-search-kind="card"]')->count());
        self::assertSame(10, $rows->count());
    }

    public function test_a_query_with_no_word_shows_the_empty_message_in_the_dropdown_frame(): void
    {
        [$client, $project] = $this->signedInProject('empty-frame@example.com');
        foreach (['!!!', '#', 'nothing-matches-this-xyz'] as $query) {
            $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/search', ['query' => $query], server: self::FRAME);

            self::assertResponseIsSuccessful();
            self::assertSelectorExists('turbo-frame#topbar-search-results [role="status"]', $query);
            self::assertSelectorNotExists('[data-search-kind]', $query);
            self::assertSelectorExists('a[data-search-all]', $query);
        }
    }

    public function test_an_invalid_query_gives_a_422_with_an_alert_in_the_frame(): void
    {
        [$client, $project] = $this->signedInProject('invalid-frame@example.com');
        $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/search', ['query' => str_repeat('x', 201)], server: self::FRAME);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('turbo-frame#topbar-search-results [role="alert"]');
        self::assertSelectorNotExists('[data-search-kind]');
    }

    public function test_the_search_without_a_project_renders_the_same_frame(): void
    {
        [$client] = $this->signedInProject('owned-frame@example.com');
        $client->request(Request::METHOD_GET, '/search', ['query' => 'quartz'], server: self::FRAME);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('turbo-frame#topbar-search-results [data-search-kind="card"] .lp-tag--teal', 'the owned search keeps the column colour');
        self::assertSelectorExists('turbo-frame#topbar-search-results [data-search-kind="document"]');
        self::assertSame('/search?query=quartz', $client->getCrawler()->filter('a[data-search-all]')->attr('href'));

        $client->request(Request::METHOD_GET, '/search', ['query' => '!!!'], server: self::FRAME);
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-search-kind]');
        self::assertSelectorExists('[role="status"]');
    }

    public function test_the_dropdown_pick_alternates_between_the_groups(): void
    {
        $items = [];
        foreach (range(1, 4) as $number) {
            $items[] = new SearchResult('Card '.$number, '/c/'.$number, 'card', $number);
        }
        foreach (range(1, 4) as $number) {
            $items[] = new SearchResult('Document '.$number, '/d/'.$number, 'document');
        }
        $items[] = new SearchResult('Board', '/p/board', 'page');

        $groups = new SearchResults($items)->dropdown(6);

        self::assertSame(['card', 'document', 'page'], array_keys($groups));
        self::assertSame(['Card 1', 'Card 2', 'Card 3'], array_map(static fn (SearchResult $row): string => $row->title, $groups['card']));
        self::assertSame(['Document 1', 'Document 2'], array_map(static fn (SearchResult $row): string => $row->title, $groups['document']));
        self::assertSame(['Board'], array_map(static fn (SearchResult $row): string => $row->title, $groups['page']));
        self::assertSame([], new SearchResults()->dropdown());
    }

    /**
     * @param non-empty-string $email
     *
     * @return array{KernelBrowser, Project}
     */
    private function signedInProject(string $email, int $documents = 1): array
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $owner = new User(fullName: 'Owner', email: $email, password: 'x');
        $owner->emailVerifiedAt = new \DateTimeImmutable();
        AcceptedTerms::stamp($owner, self::getContainer());
        $em->persist($owner);
        $project = new Project($owner, 'Frame project');
        $column = new BoardColumn($project, 'board.card.status.next', 'next', 0, tone: LabelTone::Teal);
        foreach ([$project, $column] as $entity) {
            $em->persist($entity);
        }
        $em->persist(new Card($project, $column, 'Quartz card', '', 42));
        foreach (range(1, 3) as $number) {
            $em->persist(new Card($project, $column, 'Board card '.$number, '', 50 + $number));
        }
        $em->flush();
        $create = self::getContainer()->get(CreateDocumentHandler::class);
        $titles = 1 === $documents
            ? ['Quartz document']
            : array_map(static fn (int $number): string => 'Board document '.$number, range(1, $documents));
        foreach ($titles as $title) {
            $create(new CreateDocumentCommand($project, $title, 'body'));
        }
        $em->clear();
        $client->loginUser($owner);

        return [$client, $project];
    }
}
