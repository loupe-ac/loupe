<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Controller;

use App\Tests\Module\Inbox\InboxScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class ShowInboxOpenTotalControllerTest extends WebTestCase
{
    use InboxScenario;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
    }

    public function test_it_answers_the_total_over_all_projects_without_caching(): void
    {
        $owner = $this->signedUpUser($this->em, 'total-page');
        $this->question($this->em, $this->inboxProject($this->em, $owner), 1);
        $this->question($this->em, $this->inboxProject($this->em, $owner), 1);
        $this->setInboxFlag(true);

        $this->client->loginUser($owner);
        $crawler = $this->client->request(Request::METHOD_GET, '/inbox/open-total');

        self::assertResponseIsSuccessful();
        self::assertSame('2', trim($crawler->filter('turbo-frame#inbox-open-total [data-inbox-open-total]')->text()));
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
    }

    public function test_it_shows_no_counter_at_zero(): void
    {
        $owner = $this->signedUpUser($this->em, 'total-zero');
        $this->setInboxFlag(true);

        $this->client->loginUser($owner);
        $crawler = $this->client->request(Request::METHOD_GET, '/inbox/open-total');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('turbo-frame#inbox-open-total'));
        self::assertCount(0, $crawler->filter('[data-inbox-open-total]'));
    }

    public function test_it_is_absent_while_the_inbox_is_off(): void
    {
        $owner = $this->signedUpUser($this->em, 'total-off');
        $this->setInboxFlag(false);

        $this->client->loginUser($owner);
        $this->client->request(Request::METHOD_GET, '/inbox/open-total');

        self::assertResponseStatusCodeSame(404);
    }

    public function test_an_anonymous_visitor_is_sent_to_sign_in(): void
    {
        $this->setInboxFlag(true);

        $this->client->request(Request::METHOD_GET, '/inbox/open-total');

        self::assertResponseRedirects();
    }
}
