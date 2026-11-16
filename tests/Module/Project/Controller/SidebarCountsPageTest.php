<?php

declare(strict_types=1);

namespace App\Tests\Module\Project\Controller;

use App\Tests\Module\Inbox\InboxScenario;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class SidebarCountsPageTest extends WebTestCase
{
    use InboxScenario;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->setInboxFlag(true);
    }

    public function test_no_pill_of_the_project_sidebar_reloads_itself(): void
    {
        $owner = $this->signedUpUser($this->em, 'page-pills');
        $project = $this->inboxProject($this->em, $owner);

        $this->client->loginUser($owner);
        $crawler = $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/documents');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-controller="sidebar-counts"]'));
        self::assertCount(0, $crawler->filter('.lp-sidebar [data-controller~="live-pill"]'));
        self::assertCount(1, $crawler->filter('.lp-sidebar [data-controller~="tab-count"]'));
    }

    public function test_a_quiet_project_answers_the_first_open_check_with_no_content(): void
    {
        $owner = $this->signedUpUser($this->em, 'page-quiet');
        $project = $this->inboxProject($this->em, $owner);

        $this->client->loginUser($owner);
        foreach ([1, 2] as $view) {
            $crawler = $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/documents');
            $since = $crawler->filter('[data-controller="sidebar-counts"]')->attr('data-sidebar-counts-since-value');
            self::assertMatchesRegularExpression('/^[^.]+\.[^.]+$/', (string) $since, 'view '.$view);

            $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/sidebar-counts', ['since' => $since]);
            self::assertResponseStatusCodeSame(204, 'view '.$view);
        }
    }

    public function test_the_first_view_after_a_lost_cache_item_still_answers_with_no_content(): void
    {
        $owner = $this->signedUpUser($this->em, 'page-lost');
        $project = $this->inboxProject($this->em, $owner);
        $this->client->loginUser($owner);
        $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/documents');

        $cache = static::getContainer()->get('cache.app');
        self::assertInstanceOf(CacheItemPoolInterface::class, $cache);
        $cache->clear();

        $crawler = $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/documents');
        $since = $crawler->filter('[data-controller="sidebar-counts"]')->attr('data-sidebar-counts-since-value');
        $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/sidebar-counts', ['since' => $since]);

        self::assertResponseStatusCodeSame(204);
    }
}
