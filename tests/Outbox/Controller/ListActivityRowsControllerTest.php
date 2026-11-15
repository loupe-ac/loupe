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

    public function test_it_morphs_the_shown_rows_removes_the_missing_ones_and_counts_the_newer_events(): void
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

        $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/activity/rows?ids='.$ids.'&since='.$since, server: self::STREAM);

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

        $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/activity/rows?search=renamed&ids='.$moved->id.'&since='.$moved->sequence, server: self::STREAM);

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

        $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/activity/rows?ids='.$event->id.'&since='.$event->sequence, server: self::STREAM);

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
