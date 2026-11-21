<?php

declare(strict_types=1);

namespace App\Tests\Outbox\Controller;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Outbox\Entity\OutboxEvent;
use App\Tests\Support\AcceptedTerms;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

final class ListActivityRowsControllerTest extends WebTestCase
{
    private const array STREAM = ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html'];

    public function test_a_later_page_morphs_the_shown_rows_removes_the_missing_ones_and_counts_the_newer_events(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'activity-rows@example.com');
        $project = new Project($owner, 'Rows');
        $other = new Project($owner, 'Other');
        $em->persist($project);
        $em->persist($other);
        $shown = new OutboxEvent($project, 'project.renamed', 'topic', '{}', new \DateTimeImmutable('-3 minutes'));
        $foreign = new OutboxEvent($other, 'project.renamed', 'topic', '{}', new \DateTimeImmutable('-2 minutes'));
        $em->persist($shown);
        $em->persist($foreign);
        $em->flush();
        $since = (int) $shown->sequence;
        $em->persist(new OutboxEvent($project, 'project.renamed', 'topic', '{}'));
        $em->persist(new OutboxEvent($project, 'board.card_moved', 'topic', '{}'));
        $em->flush();
        $missing = (string) Uuid::v7();
        $ids = implode(',', [(string) $shown->id, (string) $foreign->id, $missing, 'not-a-uuid']);
        $client->loginUser($owner);

        $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/activity/rows?page=2&ids='.$ids.'&since='.$since, server: self::STREAM);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/vnd.turbo-stream.html; charset=UTF-8');
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('<turbo-stream action="replace" method="morph" target="activity-row-'.$shown->id.'">', $body);
        self::assertStringContainsString('<turbo-stream action="remove" target="activity-row-'.$foreign->id.'">', $body);
        self::assertStringContainsString('<turbo-stream action="remove" target="activity-row-'.$missing.'">', $body);
        self::assertStringNotContainsString('not-a-uuid', $body);
        self::assertStringContainsString('target="activity-live-band"', $body);
        self::assertStringContainsString('2 newer events were recorded.', $body);
        self::assertMatchesRegularExpression('~targets="\[data-activity-count\]">\s*<template>\s*3 events\s*</template>~', $body);
    }

    public function test_page_one_morphs_a_shown_row_inserts_a_new_row_before_the_one_that_follows_it_and_removes_a_dropped_row(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'activity-rows-first@example.com');
        $project = new Project($owner, 'First page');
        $em->persist($project);
        $events = [];
        foreach (range(0, 21) as $index) {
            $events[] = $event = new OutboxEvent($project, 'project.renamed', 'topic', '{}', new \DateTimeImmutable('-'.$index.' minutes'));
            $em->persist($event);
        }
        $em->flush();
        // The page shows events 0 to 19. The screen shows 1 to 19 and the dropped event 20.
        $shown = array_map(static fn (OutboxEvent $event): string => (string) $event->id, [...\array_slice($events, 1, 19), $events[20]]);
        $client->loginUser($owner);

        $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/activity/rows?page=1&ids='.implode(',', $shown), server: self::STREAM);

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('<turbo-stream action="replace" method="morph" target="activity-row-'.$events[1]->id.'">', $body);
        self::assertStringContainsString('<turbo-stream action="before" target="activity-row-'.$events[1]->id.'">', $body);
        self::assertStringContainsString('data-activity-event-id="'.$events[0]->id.'"', $body);
        self::assertStringContainsString('<turbo-stream action="remove" target="activity-row-'.$events[20]->id.'">', $body);
        self::assertStringNotContainsString('target="activity-live-band"', $body);
        self::assertStringNotContainsString('action="replace" target="activity-results"', $body);
        self::assertStringContainsString('target="activity-pagination"', $body);
        self::assertMatchesRegularExpression('~targets="\[data-activity-count\]">\s*<template>\s*22 events\s*</template>~', $body);
    }

    public function test_page_one_inserts_adjacent_new_rows_last_row_first(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'activity-rows-adjacent@example.com');
        $project = new Project($owner, 'Adjacent');
        $em->persist($project);
        $events = [];
        foreach (range(0, 2) as $index) {
            $events[] = $event = new OutboxEvent($project, 'project.renamed', 'topic', '{}', new \DateTimeImmutable('-'.$index.' minutes'));
            $em->persist($event);
        }
        $em->flush();
        $client->loginUser($owner);

        $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/activity/rows?page=1&ids='.$events[2]->id, server: self::STREAM);

        $body = (string) $client->getResponse()->getContent();
        $nearest = strpos($body, '<turbo-stream action="before" target="activity-row-'.$events[2]->id.'">');
        $farther = strpos($body, '<turbo-stream action="before" target="activity-row-'.$events[1]->id.'">');
        self::assertNotFalse($nearest);
        self::assertNotFalse($farther);
        self::assertLessThan($farther, $nearest, 'The row that follows is in place before a row is put in front of it.');
    }

    public function test_page_one_appends_a_new_last_row_to_the_rows(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'activity-rows-append@example.com');
        $project = new Project($owner, 'Append');
        $em->persist($project);
        $newer = new OutboxEvent($project, 'project.renamed', 'topic', '{}', new \DateTimeImmutable('-1 minutes'));
        $older = new OutboxEvent($project, 'project.renamed', 'topic', '{}', new \DateTimeImmutable('-2 minutes'));
        $em->persist($newer);
        $em->persist($older);
        $em->flush();
        $client->loginUser($owner);

        $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/activity/rows?page=1&ids='.$newer->id, server: self::STREAM);

        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('<turbo-stream action="append" target="activity-rows">', $body);
        self::assertStringContainsString('data-activity-event-id="'.$older->id.'"', $body);
        self::assertStringNotContainsString('action="before"', $body);
    }

    public function test_page_one_replaces_the_whole_list_when_it_goes_from_empty_to_rows(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'activity-rows-fill@example.com');
        $project = new Project($owner, 'Fill');
        $em->persist($project);
        $em->persist(new OutboxEvent($project, 'project.renamed', 'topic', '{}'));
        $em->flush();
        $client->loginUser($owner);

        $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/activity/rows?page=1', server: self::STREAM);

        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('<turbo-stream action="replace" target="activity-results">', $body);
        self::assertStringContainsString('data-live-row=', $body);
        self::assertStringContainsString('<turbo-stream action="update" target="activity-filters">', $body);
        self::assertStringContainsString('id="activity-search"', $body);
        self::assertStringNotContainsString('action="before"', $body);
    }

    public function test_page_one_replaces_the_whole_list_when_the_last_row_goes(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'activity-rows-drain@example.com');
        $project = new Project($owner, 'Drain');
        $em->persist($project);
        $em->flush();
        $gone = (string) Uuid::v7();
        $client->loginUser($owner);

        $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/activity/rows?page=1&ids='.$gone, server: self::STREAM);

        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('<turbo-stream action="replace" target="activity-results">', $body);
        self::assertStringContainsString('data-activity-empty', $body);
        self::assertStringNotContainsString('id="activity-search"', $body);
    }

    public function test_page_one_changes_nothing_when_the_list_stays_empty(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'activity-rows-still-empty@example.com');
        $project = new Project($owner, 'Still empty');
        $em->persist($project);
        $em->flush();
        $client->loginUser($owner);

        $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/activity/rows?page=1', server: self::STREAM);

        self::assertResponseIsSuccessful();
        self::assertSame('', trim((string) $client->getResponse()->getContent()));
    }

    public function test_the_filters_decide_which_shown_rows_stay_and_which_events_count_as_newer(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'activity-rows-filter@example.com');
        $project = new Project($owner, 'Filtered rows');
        $em->persist($project);
        $moved = new OutboxEvent($project, 'board.card_moved', 'topic', '{}', new \DateTimeImmutable('-3 minutes'));
        $em->persist($moved);
        $em->flush();
        $em->persist(new OutboxEvent($project, 'project.renamed', 'topic', '{}'));
        $em->flush();
        $client->loginUser($owner);

        $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/activity/rows?page=2&search=renamed&ids='.$moved->id.'&since='.$moved->sequence, server: self::STREAM);

        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('<turbo-stream action="remove" target="activity-row-'.$moved->id.'">', $body);
        self::assertStringContainsString('One newer event was recorded.', $body);
        self::assertStringContainsString('/projects/'.$project->id.'/activity?page=1&amp;search=renamed', $body);
    }

    public function test_it_shows_no_band_when_nothing_is_newer(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'activity-rows-quiet@example.com');
        $project = new Project($owner, 'Quiet rows');
        $em->persist($project);
        $event = new OutboxEvent($project, 'project.renamed', 'topic', '{}');
        $em->persist($event);
        $em->flush();
        $client->loginUser($owner);

        $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/activity/rows?page=2&ids='.$event->id.'&since='.$event->sequence, server: self::STREAM);

        $body = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('data-activity-live-newer', $body);
        self::assertStringContainsString('target="activity-live-band"', $body);
    }

    public function test_a_plain_request_goes_to_the_page(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'activity-rows-plain@example.com');
        $project = new Project($owner, 'Plain rows');
        $em->persist($project);
        $em->flush();
        $client->loginUser($owner);

        $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/activity/rows?page=2');

        self::assertResponseRedirects('/projects/'.$project->id.'/activity?page=2');
    }

    public function test_another_user_cannot_read_the_rows(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'activity-rows-owner@example.com');
        $stranger = $this->user($em, 'activity-rows-stranger@example.com');
        $project = new Project($owner, 'Private rows');
        $em->persist($project);
        $em->flush();
        $client->loginUser($stranger);

        $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/activity/rows', server: self::STREAM);

        self::assertResponseStatusCodeSame(403);
    }

    private function em(): EntityManagerInterface
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }

    /** @param non-empty-string $email */
    private function user(EntityManagerInterface $em, string $email): User
    {
        $user = new User(fullName: 'Owner', email: $email, password: 'x');
        $user->emailVerifiedAt = new \DateTimeImmutable();
        AcceptedTerms::stamp($user, static::getContainer());
        $em->persist($user);

        return $user;
    }
}
