<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Controller;

use App\Mercure\ProjectTopicBuilder;
use App\Module\Board\Command\ListBacklogCardsHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\View\LiveCursor;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\Actor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;

final class ListBacklogCardsControllerTest extends WebTestCase
{
    use BoardScenario;

    public function test_the_backlog_lists_its_cards_newest_first_with_their_epic(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->user($em, 'backlog-page@example.com');
        $project = $this->project($em, $owner);
        $epic = $this->typed($em, $this->card($em, $project, 'Big epic', 'next'), 'epic');
        $this->card($em, $project, 'Older', 'backlog', 0);
        $this->childOf($em, $epic, $this->card($em, $project, 'Newer', 'backlog', 1));
        $this->card($em, $project, 'On the board', 'next', 1);
        $url = $this->backlogUrl($project);
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, $url);

        self::assertResponseIsSuccessful();
        $rows = $crawler->filter('[data-backlog-card-id]');
        self::assertSame(['Newer', 'Older'], $rows->filter('.lp-backlog-row__title')->each(
            static fn (Crawler $node): string => trim($node->text()),
        ));
        self::assertStringContainsString('Big epic', $rows->first()->text());
    }

    public function test_the_column_headers_sort_the_list_and_name_the_order(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->user($em, 'backlog-headers@example.com');
        $project = $this->project($em, $owner);
        $this->card($em, $project, 'Otter feature');
        $this->typed($em, $this->card($em, $project, 'Otter bug'), 'bug');
        $url = $this->backlogUrl($project);
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, $url);

        self::assertResponseIsSuccessful();
        self::assertSame(['Otter bug', 'Otter feature'], $this->titles($crawler));
        self::assertSame(
            ['Type' => 'none', 'Epic' => 'none', 'Added' => 'descending'],
            $this->sortHeaders($crawler),
        );
        self::assertSame($url.'?page=1&sort=type&dir=asc', $crawler->selectLink('Type')->attr('href'));
        self::assertSame($url.'?page=1&sort=created&dir=asc', $crawler->selectLink('Added')->attr('href'));
        self::assertCount(0, $crawler->filter('form.lp-filter-form input[name="sort"]'));

        $crawler = $client->request(Request::METHOD_GET, $url.'?sort=type&dir=asc&epic=none');

        self::assertSame(['Otter bug', 'Otter feature'], $this->titles($crawler));
        self::assertSame(
            ['Type' => 'ascending', 'Epic' => 'none', 'Added' => 'none'],
            $this->sortHeaders($crawler),
        );
        self::assertSame($url.'?page=1&epic=none&sort=type&dir=desc', $crawler->selectLink('Type')->attr('href'));
        self::assertSame($url.'?page=1&epic=none', $crawler->selectLink('Added')->attr('href'));
        self::assertSame('type', $crawler->filter('form.lp-filter-form input[name="sort"]')->attr('value'));
        self::assertSame('asc', $crawler->filter('form.lp-filter-form input[name="dir"]')->attr('value'));

        $crawler = $client->request(Request::METHOD_GET, $url.'?sort=type&dir=desc');
        self::assertSame(['Otter feature', 'Otter bug'], $this->titles($crawler));
    }

    public function test_an_old_sort_link_shows_the_newest_first(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->user($em, 'backlog-old-sort@example.com');
        $project = $this->project($em, $owner);
        $this->card($em, $project, 'Older');
        $this->card($em, $project, 'Newer');
        $url = $this->backlogUrl($project);
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, $url.'?sort=oldest');

        self::assertResponseIsSuccessful();
        self::assertSame(['Newer', 'Older'], $this->titles($crawler));
        self::assertSame('descending', $this->sortHeaders($crawler)['Added']);
    }

    public function test_the_backlog_paginates(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->user($em, 'backlog-paging@example.com');
        $project = $this->project($em, $owner);
        for ($index = 0; $index < ListBacklogCardsHandler::PER_PAGE + 3; ++$index) {
            $this->card($em, $project, 'Waiting '.$index, 'backlog', $index);
        }
        $url = $this->backlogUrl($project);
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, $url);
        self::assertResponseIsSuccessful();
        self::assertCount(ListBacklogCardsHandler::PER_PAGE, $crawler->filter('[data-backlog-card-id]'));

        $crawler = $client->request(Request::METHOD_GET, $url.'?page=2');
        self::assertResponseIsSuccessful();
        self::assertCount(3, $crawler->filter('[data-backlog-card-id]'));

        $client->request(Request::METHOD_GET, $url.'?page=9');
        self::assertResponseRedirects($url.'?page=2');
    }

    public function test_an_empty_backlog_says_so(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->user($em, 'backlog-empty@example.com');
        $project = $this->project($em, $owner);
        $this->card($em, $project, 'On the board', 'next');
        $url = $this->backlogUrl($project);
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, $url);

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-backlog-card-id]'));
        self::assertCount(1, $crawler->filter('.lp-empty-state'));
    }

    public function test_the_filters_narrow_the_list_and_keep_their_values(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->user($em, 'backlog-filters@example.com');
        $project = $this->project($em, $owner);
        $epic = $this->typed($em, $this->card($em, $project, 'Big epic', 'next'), 'epic');
        $this->typed($em, $this->childOf($em, $epic, $this->card($em, $project, 'Child bug', 'backlog', 0)), 'bug');
        $this->typed($em, $this->card($em, $project, 'Loose bug', 'backlog', 1), 'bug');
        $this->card($em, $project, 'Loose feature', 'backlog', 2);
        $url = $this->backlogUrl($project);
        $epicId = (string) $epic->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, $url.'?type=bug&epic='.$epicId.'&sort=epic&dir=desc');

        self::assertResponseIsSuccessful();
        self::assertSame(['Child bug'], $this->titles($crawler));
        self::assertSame('1 of 3 cards', trim($crawler->filter('#backlog-filter-count')->text()));
        self::assertSame('bug', $crawler->filter('#backlog-type option[selected]')->attr('value'));
        self::assertSame($epicId, $crawler->filter('#backlog-epic option[selected]')->attr('value'));
        self::assertSame('epic', $crawler->filter('form.lp-filter-form input[name="sort"]')->attr('value'));

        $crawler = $client->request(Request::METHOD_GET, $url.'?epic=none');
        self::assertSame(['Loose feature', 'Loose bug'], $this->titles($crawler));
    }

    public function test_filters_that_match_nothing_offer_to_clear_them(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->user($em, 'backlog-no-match@example.com');
        $project = $this->project($em, $owner);
        $this->card($em, $project, 'A feature');
        $url = $this->backlogUrl($project);
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, $url.'?type=bug');

        self::assertResponseIsSuccessful();
        self::assertSame('No card matches', trim($crawler->filter('.lp-empty-state__title')->text()));
        self::assertSame($url, $crawler->selectLink('Clear filters')->attr('href'));
    }

    public function test_a_page_past_the_end_keeps_the_filters_in_its_redirect(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->user($em, 'backlog-clamp-filters@example.com');
        $project = $this->project($em, $owner);
        $this->card($em, $project, 'A feature');
        $url = $this->backlogUrl($project);
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, $url.'?page=4&type=feature&sort=epic&dir=asc');

        self::assertResponseRedirects($url.'?page=1&type=feature&sort=epic&dir=asc');
    }

    public function test_the_first_page_is_a_live_frame_on_the_board_topic(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->user($em, 'backlog-live@example.com');
        $project = $this->project($em, $owner);
        $card = $this->card($em, $project, 'Waiting');
        $url = $this->backlogUrl($project);
        $projectId = $project->id;
        self::assertNotNull($projectId);
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, $url.'?type=feature');

        self::assertResponseIsSuccessful();
        $topics = static::getContainer()->get(ProjectTopicBuilder::class);
        self::assertInstanceOf(ProjectTopicBuilder::class, $topics);
        self::assertEqualsCanonicalizing(
            [$topics->forBoard($projectId), $topics->forWorkerRuns($projectId)],
            $crawler->filter('form#mercure-subscriptions input[data-mercure-topic]')->each(static fn (Crawler $input): ?string => $input->attr('value')),
        );
        $region = $crawler->filter('turbo-frame#backlog-live[data-controller="live-region"]');
        self::assertCount(1, $region);
        self::assertSame('frame', $region->attr('data-live-region-mode-value'));
        self::assertSame('["board.card_changed","board.columns_changed"]', $region->attr('data-live-region-types-value'));
        self::assertSame($url.'?page=1&type=feature', $region->attr('data-live-region-url-value'));
        self::assertCount(1, $region->filter('#backlog-row-'.$card->id.'[data-live-row="'.$card->id.'"][data-live-fade]'));
        self::assertCount(0, $crawler->filter('[data-backlog-live-target]'));
    }

    public function test_a_later_page_reads_its_own_rows_with_the_moment_it_rendered(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->user($em, 'backlog-later@example.com');
        $project = $this->project($em, $owner);
        for ($index = 1; $index <= ListBacklogCardsHandler::PER_PAGE + 1; ++$index) {
            $this->card($em, $project, 'Card '.$index);
        }
        $url = $this->backlogUrl($project);
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, $url.'?page=2');

        self::assertResponseIsSuccessful();
        $region = $crawler->filter('div#backlog-live[data-controller="live-region"]');
        self::assertCount(1, $region);
        self::assertSame('rows', $region->attr('data-live-region-mode-value'));
        self::assertSame($url.'/rows?page=2', $region->attr('data-live-region-url-value'));
        self::assertNotNull(LiveCursor::parse($region->attr('data-live-since')));
        self::assertCount(1, $region->filter('#backlog-live-band'));
        self::assertCount(1, $region->filter('[data-live-row]'));
    }

    public function test_a_title_opens_the_card_drawer_and_the_row_shows_no_source_tag(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->user($em, 'backlog-drawer@example.com');
        $project = $this->project($em, $owner);
        $card = new Card(
            project: $project,
            column: $this->column($project, 'backlog'),
            title: 'Reviewer note',
            body: '',
            number: 1,
            type: 'feature',
            origin: Actor::Reviewer,
            position: 0,
        );
        $em->persist($card);
        $em->flush();
        $url = $this->backlogUrl($project);
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, $url);

        self::assertResponseIsSuccessful();
        $title = $crawler->filter('[data-backlog-card-id] a.lp-backlog-row__title');
        self::assertCount(1, $title);
        self::assertSame('card-drawer-frame', $title->attr('data-turbo-frame'));
        self::assertSame('#1', $title->attr('data-drawer-title'));
        self::assertSame('click->card-drawer#prepare', $title->attr('data-action'));
        self::assertStringContainsString('/cards/', (string) $title->attr('href'));
        self::assertCount(1, $crawler->filter('[data-controller~="card-drawer"] turbo-frame#card-drawer-frame'));
        self::assertCount(1, $crawler->filter('[data-backlog-card-id] .lp-tag'), 'only the type tag stays');
    }

    public function test_a_stranger_is_forbidden(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->user($em, 'backlog-owner@example.com');
        $stranger = $this->user($em, 'backlog-stranger@example.com');
        $project = $this->project($em, $owner);
        $url = $this->backlogUrl($project);
        $em->clear();

        $client->loginUser($stranger);
        $client->request(Request::METHOD_GET, $url);

        self::assertResponseStatusCodeSame(403);
    }

    /** @return list<string> */
    private function titles(Crawler $crawler): array
    {
        return $crawler->filter('[data-backlog-card-id] .lp-backlog-row__title')->each(
            static fn (Crawler $node): string => trim($node->text()),
        );
    }

    /** @return array<string, string> the aria-sort of each sortable header, by its label */
    private function sortHeaders(Crawler $crawler): array
    {
        $headers = [];
        $crawler->filter('[role="columnheader"][aria-sort]')->each(
            static function (Crawler $node) use (&$headers): void {
                $headers[trim($node->text())] = (string) $node->attr('aria-sort');
            },
        );

        return $headers;
    }

    private function backlogUrl(Project $project): string
    {
        return '/projects/'.$project->id.'/board/backlog';
    }
}
