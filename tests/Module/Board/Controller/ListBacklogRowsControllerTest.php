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

    public function test_a_read_with_no_valid_cursor_is_not_found(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->user($em, 'backlog-rows-cursor@example.com');
        $project = $this->project($em, $owner);
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board/backlog/rows?ids=&since=yesterday');

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
        return '/projects/'.$project->id.'/board/backlog/rows?ids='.$ids.'&since='.rawurlencode($since);
    }
}
