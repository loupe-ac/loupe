<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Controller;

use App\Module\Board\View\LiveCursor;
use App\Module\Project\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class ListBacklogRowsControllerTest extends WebTestCase
{
    use BoardScenario;

    public function test_a_shown_row_is_morphed_a_moved_row_is_removed_and_the_band_counts_new_cards(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->user($em, 'backlog-rows@example.com');
        $project = $this->project($em, $owner);
        $stays = $this->card($em, $project, 'Stays');
        $moved = $this->card($em, $project, 'Moved on', 'next');
        $since = LiveCursor::format(new \DateTimeImmutable('-1 minute'));
        $this->card($em, $project, 'Arrived later');
        $url = $this->rowsUrl($project, $stays->id.','.$moved->id, $since);
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, $url);

        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('text/vnd.turbo-stream.html', (string) $client->getResponse()->headers->get('Content-Type'));
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('<turbo-stream action="replace" method="morph" target="backlog-row-'.$stays->id.'">', $body);
        self::assertStringContainsString('<turbo-stream action="remove" target="backlog-row-'.$moved->id.'">', $body);
        self::assertStringContainsString('target="backlog-live-band"', $body);
        self::assertStringContainsString('One new card matches this list.', $body);
        self::assertStringContainsString('target="backlog-filter-count"', $body);
    }

    public function test_the_read_refreshes_the_bulk_bar_with_the_current_next_column(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->user($em, 'backlog-rows-bulk@example.com');
        $project = $this->project($em, $owner);
        $this->card($em, $project, 'Shown');
        $next = $this->column($project, 'next');
        $url = $this->rowsUrl($project, '', LiveCursor::format(new \DateTimeImmutable('+1 minute')));
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, $url);

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('<turbo-stream action="replace" method="morph" target="backlog-bulk-form">', $body);
        self::assertStringContainsString('name="backlog_bulk_move[column]" value="'.$next->id.'">Move to Next', $body);
    }

    public function test_a_row_that_stops_matching_the_filter_is_removed(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->user($em, 'backlog-rows-filter@example.com');
        $project = $this->project($em, $owner);
        $card = $this->typed($em, $this->card($em, $project, 'Was a feature'), 'bug');
        $since = LiveCursor::format(new \DateTimeImmutable('+1 minute'));
        $url = $this->rowsUrl($project, (string) $card->id, $since).'&type=feature';
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, $url);

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('<turbo-stream action="remove" target="backlog-row-'.$card->id.'">', $body);
        self::assertStringNotContainsString('method="morph" target="backlog-row-', $body);
        self::assertStringNotContainsString('new cards match', $body);
    }

    public function test_page_one_inserts_a_new_card_before_the_row_that_follows_it_and_sends_no_band(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->user($em, 'backlog-rows-first@example.com');
        $project = $this->project($em, $owner);
        $older = $this->card($em, $project, 'Older');
        $gone = $this->card($em, $project, 'Gone', 'next');
        $newer = $this->card($em, $project, 'Newer');
        $url = '/projects/'.$project->id.'/board/backlog/rows?page=1&ids='.$older->id.','.$gone->id;
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, $url);

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('<turbo-stream action="replace" method="morph" target="backlog-row-'.$older->id.'">', $body);
        self::assertStringContainsString('<turbo-stream action="remove" target="backlog-row-'.$gone->id.'">', $body);
        self::assertStringContainsString('<turbo-stream action="before" target="backlog-row-'.$older->id.'">', $body);
        self::assertStringContainsString('id="backlog-row-'.$newer->id.'"', $body);
        self::assertStringContainsString('target="backlog-pagination"', $body);
        self::assertStringContainsString('target="backlog-filter-count"', $body);
        self::assertStringContainsString('target="backlog-bulk-form"', $body);
        self::assertStringNotContainsString('backlog-live-band', $body);
    }

    public function test_page_one_appends_only_the_card_that_moved_up_from_page_two(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->user($em, 'backlog-rows-moved-up@example.com');
        $project = $this->project($em, $owner);
        $cards = [];
        for ($i = 1; $i <= 26; ++$i) {
            $cards[] = $this->card($em, $project, 'Card '.$i);
        }
        $shown = array_map(static fn ($card): string => (string) $card->id, \array_slice(array_reverse($cards), 0, 25));
        $newest = $cards[25];
        $newest->column = $this->column($project, 'next');
        $em->flush();
        $url = '/projects/'.$project->id.'/board/backlog/rows?page=1&ids='.implode(',', $shown);
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, $url);

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('<turbo-stream action="remove" target="backlog-row-'.$newest->id.'">', $body);
        self::assertSame(1, substr_count($body, '<turbo-stream action="append" target="backlog-rows">'));
        self::assertStringContainsString('id="backlog-row-'.$cards[0]->id.'"', $body);
        self::assertStringNotContainsString('action="before"', $body);
    }

    public function test_page_one_replaces_the_results_when_the_screen_was_empty_and_the_page_is_not(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->user($em, 'backlog-rows-empty@example.com');
        $project = $this->project($em, $owner);
        $card = $this->card($em, $project, 'First card');
        $url = '/projects/'.$project->id.'/board/backlog/rows?page=1&ids=';
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, $url);

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('<turbo-stream action="update" target="backlog-results">', $body);
        self::assertStringContainsString('id="backlog-row-'.$card->id.'"', $body);
        self::assertStringNotContainsString('target="backlog-pagination"', $body);
    }

    public function test_a_read_with_no_valid_cursor_is_not_found(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->user($em, 'backlog-rows-cursor@example.com');
        $project = $this->project($em, $owner);
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board/backlog/rows?page=2&ids=&since=yesterday');

        self::assertResponseStatusCodeSame(404);
    }

    public function test_a_stranger_gets_the_answer_of_the_page(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->user($em, 'backlog-rows-owner@example.com');
        $stranger = $this->user($em, 'backlog-rows-stranger@example.com');
        $project = $this->project($em, $owner);
        $url = $this->rowsUrl($project, '', LiveCursor::format(new \DateTimeImmutable()));
        $em->clear();

        $client->loginUser($stranger);
        $client->request(Request::METHOD_GET, $url);

        self::assertResponseStatusCodeSame(403);
    }

    private function rowsUrl(Project $project, string $ids, string $since): string
    {
        return '/projects/'.$project->id.'/board/backlog/rows?page=2&ids='.$ids.'&since='.rawurlencode($since);
    }
}
