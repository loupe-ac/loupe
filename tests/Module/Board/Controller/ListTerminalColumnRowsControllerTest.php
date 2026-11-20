<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Controller;

use App\Module\Board\View\LiveCursor;
use App\Module\Project\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class ListTerminalColumnRowsControllerTest extends WebTestCase
{
    use BoardScenario;

    public function test_a_shown_row_is_morphed_a_reopened_row_is_removed_and_the_band_counts_new_finishes(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->user($em, 'done-rows@example.com');
        $project = $this->project($em, $owner);
        $stays = $this->card($em, $project, 'Stays done', 'done');
        $reopened = $this->card($em, $project, 'Reopened', 'next');
        $this->card($em, $project, 'Fresh finish', 'done');
        $since = LiveCursor::format(new \DateTimeImmutable('-1 minute'));
        $url = $this->rowsUrl($project, $stays->id.','.$reopened->id, $since);
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, $url);

        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('text/vnd.turbo-stream.html', (string) $client->getResponse()->headers->get('Content-Type'));
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('<turbo-stream action="replace" method="morph" target="terminal-row-'.$stays->id.'">', $body);
        self::assertStringContainsString('<turbo-stream action="remove" target="terminal-row-'.$reopened->id.'">', $body);
        self::assertStringContainsString('One more card finished.', $body);
        self::assertStringContainsString('<turbo-stream action="update" target="terminal-total">', $body);
        self::assertStringContainsString('<template>2 cards</template>', $body);
    }

    public function test_page_one_inserts_a_new_finish_before_the_row_that_follows_it_and_sends_no_band(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->user($em, 'done-rows-first@example.com');
        $project = $this->project($em, $owner);
        $older = $this->card($em, $project, 'Older finish', 'done');
        $reopened = $this->card($em, $project, 'Reopened', 'next');
        $newer = $this->card($em, $project, 'Newer finish', 'done');
        $em->getConnection()->executeStatement('UPDATE board_cards SET completed_at = ? WHERE id = ?', [new \DateTimeImmutable('-1 hour')->format('Y-m-d H:i:s'), (string) $older->id]);
        $url = '/projects/'.$project->id.'/board/terminal/'.$this->column($project, 'done')->id.'/rows?page=1&ids='.$older->id.','.$reopened->id;
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, $url);

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('<turbo-stream action="replace" method="morph" target="terminal-row-'.$older->id.'">', $body);
        self::assertStringContainsString('<turbo-stream action="remove" target="terminal-row-'.$reopened->id.'">', $body);
        self::assertStringContainsString('<turbo-stream action="before" target="terminal-row-'.$older->id.'">', $body);
        self::assertStringContainsString('id="terminal-row-'.$newer->id.'"', $body);
        self::assertStringContainsString('<turbo-stream action="update" target="terminal-total">', $body);
        self::assertStringNotContainsString('terminal-live-band', $body);
    }

    public function test_page_one_replaces_the_results_when_the_screen_was_empty_and_the_page_is_not(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->user($em, 'done-rows-empty@example.com');
        $project = $this->project($em, $owner);
        $card = $this->card($em, $project, 'First finish', 'done');
        $url = '/projects/'.$project->id.'/board/terminal/'.$this->column($project, 'done')->id.'/rows?page=1&ids=';
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, $url);

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('<turbo-stream action="update" target="terminal-results">', $body);
        self::assertStringContainsString('id="terminal-row-'.$card->id.'"', $body);
    }

    public function test_page_one_appends_the_card_that_moved_up_from_page_two(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->user($em, 'done-rows-moved-up@example.com');
        $project = $this->project($em, $owner);
        $cards = [];
        for ($i = 1; $i <= 26; ++$i) {
            $card = $this->card($em, $project, 'Done '.$i, 'done');
            $em->getConnection()->executeStatement('UPDATE board_cards SET completed_at = ? WHERE id = ?', [new \DateTimeImmutable('+'.$i.' seconds')->format('Y-m-d H:i:s'), (string) $card->id]);
            $cards[] = $card;
        }
        $shown = array_map(static fn ($card): string => (string) $card->id, \array_slice(array_reverse($cards), 0, 25));
        $newest = $cards[25];
        $em->getConnection()->executeStatement('UPDATE board_cards SET column_id = ? WHERE id = ?', [(string) $this->column($project, 'next')->id, (string) $newest->id]);
        $url = '/projects/'.$project->id.'/board/terminal/'.$this->column($project, 'done')->id.'/rows?page=1&ids='.implode(',', $shown);
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, $url);

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('<turbo-stream action="remove" target="terminal-row-'.$newest->id.'">', $body);
        self::assertSame(1, substr_count($body, '<turbo-stream action="append" target="terminal-rows">'));
        self::assertStringContainsString('id="terminal-row-'.$cards[0]->id.'"', $body);
    }

    public function test_a_column_that_is_not_terminal_is_not_found(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->user($em, 'done-rows-open@example.com');
        $project = $this->project($em, $owner);
        $url = '/projects/'.$project->id.'/board/terminal/'.$this->column($project, 'next')->id.'/rows?page=2&ids=&since='.rawurlencode(LiveCursor::format(new \DateTimeImmutable()));
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, $url);

        self::assertResponseStatusCodeSame(404);
    }

    public function test_a_stranger_gets_the_answer_of_the_page(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->user($em, 'done-rows-owner@example.com');
        $stranger = $this->user($em, 'done-rows-stranger@example.com');
        $project = $this->project($em, $owner);
        $url = $this->rowsUrl($project, '', LiveCursor::format(new \DateTimeImmutable()));
        $em->clear();

        $client->loginUser($stranger);
        $client->request(Request::METHOD_GET, $url);

        self::assertResponseStatusCodeSame(403);
    }

    private function rowsUrl(Project $project, string $ids, string $since): string
    {
        return '/projects/'.$project->id.'/board/terminal/'.$this->column($project, 'done')->id.'/rows?page=2&ids='.$ids.'&since='.rawurlencode($since);
    }
}
