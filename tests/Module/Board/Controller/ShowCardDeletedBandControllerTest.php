<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

final class ShowCardDeletedBandControllerTest extends WebTestCase
{
    use BoardScenario;

    public function test_the_full_card_page_declares_a_stream_region_that_matches_its_card_and_a_delete(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->user($em, 'deleted-band-page@example.com');
        $project = $this->project($em, $owner);
        $card = $this->card($em, $project, 'Short lived');
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board/cards/'.$card->id);

        self::assertResponseIsSuccessful();
        $region = $crawler->filter('#card-deleted-live[data-controller="live-region"]');
        self::assertCount(1, $region);
        self::assertSame('stream', $region->attr('data-live-region-mode-value'));
        self::assertSame('{"cardId":"'.$card->id.'","change":"deleted"}', $region->attr('data-live-region-match-value'));
        self::assertSame('/projects/'.$project->id.'/board/cards/'.$card->id.'/deleted-band', $region->attr('data-live-region-url-value'));
        self::assertCount(1, $region->filter('#card-deleted-band:empty'));
        self::assertCount(1, $crawler->filter('[data-card-drawer-card-id="'.$card->id.'"][data-live-content]'));
    }

    public function test_the_drawer_response_has_no_deleted_region(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->user($em, 'deleted-band-drawer@example.com');
        $project = $this->project($em, $owner);
        $card = $this->card($em, $project, 'In the drawer');
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board/cards/'.$card->id, server: ['HTTP_TURBO_FRAME' => 'card-drawer-frame']);

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('#card-deleted-live'));
    }

    public function test_a_card_that_is_gone_gets_the_band_and_a_card_that_is_there_gets_nothing(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->user($em, 'deleted-band-read@example.com');
        $project = $this->project($em, $owner);
        $card = $this->card($em, $project, 'Still here');
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board/cards/'.$card->id.'/deleted-band');
        self::assertResponseIsSuccessful();
        self::assertSame('', $client->getResponse()->getContent());

        $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board/cards/'.Uuid::v7().'/deleted-band');
        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('text/vnd.turbo-stream.html', (string) $client->getResponse()->headers->get('Content-Type'));
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('<turbo-stream action="update" target="card-deleted-band">', $body);
        self::assertStringContainsString('This card was deleted.', $body);
        self::assertStringContainsString('href="/projects/'.$project->id.'/board"', $body);
    }

    public function test_a_stranger_gets_the_answer_of_the_page(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->user($em, 'deleted-band-owner@example.com');
        $stranger = $this->user($em, 'deleted-band-stranger@example.com');
        $project = $this->project($em, $owner);
        $em->clear();

        $client->loginUser($stranger);
        $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board/cards/'.Uuid::v7().'/deleted-band');

        self::assertResponseStatusCodeSame(403);
    }
}
