<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Controller;

use App\Module\Inbox\Controller\ShowInboxRowsController;
use App\Tests\Module\Inbox\InboxScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;
use Symfony\UX\Turbo\TurboBundle;

final class ShowInboxRowsControllerTest extends WebTestCase
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

    public function test_a_changed_row_leaves_and_comes_back_before_the_next_unchanged_row(): void
    {
        $owner = $this->signedUpUser($this->em, 'inbox-rows-order');
        $project = $this->inboxProject($this->em, $owner);
        $oldQuiet = $this->askHolding($this->em, $project, [$this->todo($this->em, $project, 1, 'Quiet and old')], createdAt: new \DateTimeImmutable('-3 days'));
        $newBlocking = $this->askHolding($this->em, $project, [$this->question($this->em, $project, 2, title: 'Blocking and new')], createdAt: new \DateTimeImmutable('-1 hour'));
        $oldBlocking = $this->askHolding($this->em, $project, [$this->question($this->em, $project, 3, title: 'Blocking and old')], createdAt: new \DateTimeImmutable('-2 days'));
        $this->setInboxFlag(true);

        $this->client->loginUser($owner);
        $this->streamRequest('/projects/'.$project->id.'/inbox/rows?ask='.$oldBlocking->id.'&changed[]='.$newBlocking->id);

        self::assertResponseIsSuccessful();
        self::assertStringStartsWith(TurboBundle::STREAM_MEDIA_TYPE, (string) $this->client->getResponse()->headers->get('Content-Type'));
        self::assertSame(
            implode(',', [$oldBlocking->id, $newBlocking->id, $oldQuiet->id]),
            $this->client->getResponse()->headers->get(ShowInboxRowsController::KEYS_HEADER),
        );
        $stream = (string) $this->client->getResponse()->getContent();
        self::assertSame(1, substr_count($stream, 'action="remove"'));
        self::assertStringContainsString("[data-inbox-request='".$newBlocking->id."']", $stream);
        self::assertSame(1, substr_count($stream, 'action="before"'));
        self::assertStringContainsString("[data-inbox-request='".$oldQuiet->id."']", $stream);
        self::assertStringContainsString('Blocking and new', $stream);
        self::assertStringNotContainsString('Blocking and old', $stream);
        self::assertStringContainsString('target="inbox-open-tab"', $stream);
    }

    public function test_a_changed_row_that_sorts_last_is_appended(): void
    {
        $owner = $this->signedUpUser($this->em, 'inbox-rows-append');
        $project = $this->inboxProject($this->em, $owner);
        $first = $this->askHolding($this->em, $project, [$this->question($this->em, $project, 1)], createdAt: new \DateTimeImmutable('-2 days'));
        $last = $this->askHolding($this->em, $project, [$this->question($this->em, $project, 2)], createdAt: new \DateTimeImmutable('-1 hour'));
        $this->setInboxFlag(true);

        $this->client->loginUser($owner);
        $this->streamRequest('/projects/'.$project->id.'/inbox/rows?ask='.$first->id.'&changed[]='.$last->id);

        $stream = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('action="append" target="inbox-rows"', $stream);
        self::assertStringNotContainsString('action="before"', $stream);
    }

    public function test_a_changed_ask_that_closed_only_leaves(): void
    {
        $owner = $this->signedUpUser($this->em, 'inbox-rows-closed');
        $project = $this->inboxProject($this->em, $owner);
        $kept = $this->askHolding($this->em, $project, [$this->question($this->em, $project, 1)]);
        $this->setInboxFlag(true);

        $this->client->loginUser($owner);
        $gone = '0199aaaa-0000-7000-8000-000000000001';
        $this->streamRequest('/projects/'.$project->id.'/inbox/rows?ask='.$kept->id.'&changed[]='.$gone);

        $stream = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString("[data-inbox-request='".$gone."']", $stream);
        self::assertStringNotContainsString('action="before"', $stream);
        self::assertStringNotContainsString('action="append"', $stream);
        self::assertSame((string) $kept->id, $this->client->getResponse()->headers->get(ShowInboxRowsController::KEYS_HEADER));
    }

    public function test_a_changed_value_that_is_no_id_is_dropped(): void
    {
        $owner = $this->signedUpUser($this->em, 'inbox-rows-crafted');
        $project = $this->inboxProject($this->em, $owner);
        $this->askHolding($this->em, $project, [$this->question($this->em, $project, 1)]);
        $this->setInboxFlag(true);

        $this->client->loginUser($owner);
        $this->streamRequest('/projects/'.$project->id.'/inbox/rows?changed[]='.rawurlencode("x'] , body"));

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('action="remove"', (string) $this->client->getResponse()->getContent());
    }

    public function test_the_closed_side_and_a_search_are_refused(): void
    {
        $owner = $this->signedUpUser($this->em, 'inbox-rows-side');
        $project = $this->inboxProject($this->em, $owner);
        $this->setInboxFlag(true);

        $this->client->loginUser($owner);
        $this->streamRequest('/projects/'.$project->id.'/inbox/rows?side=closed');
        self::assertResponseStatusCodeSame(400);
        $this->streamRequest('/projects/'.$project->id.'/inbox/rows?q=csv');
        self::assertResponseStatusCodeSame(400);
    }

    public function test_a_burst_over_the_row_limit_is_refused_so_the_page_reads_the_whole_view(): void
    {
        $owner = $this->signedUpUser($this->em, 'inbox-rows-burst');
        $project = $this->inboxProject($this->em, $owner);
        $this->setInboxFlag(true);
        $ids = array_map(static fn (): string => Uuid::v7()->toRfc4122(), range(1, ShowInboxRowsController::MAX_CHANGED + 1));

        $this->client->loginUser($owner);
        $this->streamRequest('/projects/'.$project->id.'/inbox/rows?changed[]='.implode('&changed[]=', $ids));
        self::assertResponseStatusCodeSame(400);
        $this->streamRequest('/projects/'.$project->id.'/inbox/rows?changed[]='.implode('&changed[]=', \array_slice($ids, 1)));
        self::assertResponseIsSuccessful();
    }

    public function test_a_plain_visit_goes_to_the_page(): void
    {
        $owner = $this->signedUpUser($this->em, 'inbox-rows-plain');
        $project = $this->inboxProject($this->em, $owner);
        $this->setInboxFlag(true);

        $this->client->loginUser($owner);
        $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/inbox/rows');

        self::assertResponseRedirects('/projects/'.$project->id.'/inbox');
    }

    public function test_a_stranger_is_refused(): void
    {
        $project = $this->inboxProject($this->em, $this->signedUpUser($this->em, 'inbox-rows-owner'));
        $this->setInboxFlag(true);

        $this->client->loginUser($this->signedUpUser($this->em, 'inbox-rows-stranger'));
        $this->streamRequest('/projects/'.$project->id.'/inbox/rows');

        self::assertResponseStatusCodeSame(403);
    }

    public function test_the_route_answers_404_while_the_inbox_is_off(): void
    {
        $owner = $this->signedUpUser($this->em, 'inbox-rows-off');
        $project = $this->inboxProject($this->em, $owner);
        $this->setInboxFlag(false);

        $this->client->loginUser($owner);
        $this->streamRequest('/projects/'.$project->id.'/inbox/rows');

        self::assertResponseStatusCodeSame(404);
    }

    private function streamRequest(string $uri): void
    {
        $this->client->request(Request::METHOD_GET, $uri, server: ['HTTP_ACCEPT' => TurboBundle::STREAM_MEDIA_TYPE]);
    }
}
