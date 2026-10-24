<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Controller;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Comment;
use App\Module\Review\Entity\CommentStatus;
use App\Module\Review\Entity\Document;
use App\Module\Review\ValueObject\Anchor;
use App\Tests\Support\AcceptedTerms;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\UX\Turbo\TurboBundle;

final class ShowDocumentCommentSheetTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $owner;
    private Document $document;
    private Comment $thread;
    private Comment $resolved;
    private Comment $general;
    private Comment $orphan;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $this->owner = new User(fullName: 'Sheet Owner', email: 'sheet@example.com', password: 'hashed-password-placeholder');
        $this->owner->emailVerifiedAt = new \DateTimeImmutable();
        AcceptedTerms::stamp($this->owner, static::getContainer());
        $this->em->persist($this->owner);
        $project = new Project($this->owner, 'p-'.uniqid());
        $this->em->persist($project);

        $this->document = new Document(owner: $this->owner, project: $project, title: 'Sheet Doc');
        $version = $this->document->addVersion('# Body', '<h1>Body</h1>');
        $this->em->persist($this->document);

        $this->thread = new Comment($version, $this->owner, 'Say more here', new Anchor('Body', '', '', 0));
        $this->resolved = new Comment($version, $this->owner, 'Already fixed', new Anchor('Body', '', '', 10));
        $this->resolved->status = CommentStatus::Resolved;
        $this->general = new Comment($version, $this->owner, 'On the whole thing', new Anchor('', '', '', 0));
        $this->orphan = new Comment($version, $this->owner, 'About removed text', new Anchor('Gone words', '', '', 0));
        $this->orphan->orphaned = true;
        $liveReply = new Comment($version, $this->owner, 'First reply', $this->thread->anchor, $this->thread);
        $secondReply = new Comment($version, $this->owner, 'Second reply', $this->thread->anchor, $this->thread);
        $deletedReply = new Comment($version, $this->owner, 'Gone reply', $this->thread->anchor, $this->thread);
        $deletedReply->deletedAt = new \DateTimeImmutable();
        foreach ([$this->thread, $this->resolved, $this->general, $this->orphan, $liveReply, $secondReply, $deletedReply] as $comment) {
            $this->em->persist($comment);
        }
        $this->em->flush();
        $this->client->loginUser($this->owner);
    }

    private function reviewPage(): Crawler
    {
        $crawler = $this->client->request(
            Request::METHOD_GET,
            '/projects/'.$this->document->project->id.'/documents/'.$this->document->id.'/review',
        );
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    public function test_the_sheet_lists_every_thread_as_a_flat_list_with_author_age_and_reply_count(): void
    {
        $crawler = $this->reviewPage();
        $rows = $crawler->filter('#sheet-comment-rows .lp-comment-row');

        self::assertCount(4, $rows);
        $row = $crawler->filter('#sheet-comment-row-'.$this->thread->id);
        self::assertSame('comment-thread-'.$this->thread->id, $row->attr('data-thread-id'));
        self::assertSame('pending', $row->attr('data-anchor-status'));
        self::assertSame('Body', trim($row->filter('.lp-comment-row__quote')->text()));
        self::assertSame('Say more here', trim($row->filter('.lp-comment-row__body')->text()));
        self::assertSame('Sheet Owner', trim($row->filter('.lp-comment-row__author')->text()));
        self::assertCount(1, $row->filter('.lp-comment-row__age time'));
        self::assertSame('2 replies', trim($row->filter('.lp-comment-row__replies')->text()));
        self::assertStringContainsString('click->comment-anchor#jumpToRow', (string) $row->attr('data-action'));
        self::assertStringNotContainsString('openRow', (string) $row->attr('data-action'));
        self::assertSame('resolved', $crawler->filter('#sheet-comment-row-'.$this->resolved->id)->attr('data-anchor-status'));
        self::assertCount(0, $crawler->filter('#sheet-comment-row-'.$this->resolved->id.' .lp-comment-row__replies'));
        self::assertCount(0, $crawler->filter('#sheet-comment-rows .lp-general-comments, #sheet-comment-rows .lp-orphan-group'));
    }

    public function test_a_sheet_row_stays_out_of_the_margin_targets(): void
    {
        $crawler = $this->reviewPage();

        self::assertCount(0, $crawler->filter('#sheet-comment-rows [data-comment-anchor-target]'));
        self::assertCount(0, $crawler->filter('#sheet-comment-rows [data-review-panels-target]'));
        self::assertCount(4, $crawler->filter('#comment-rows .lp-comment-row[data-comment-anchor-target="row"]'));
    }

    public function test_no_id_repeats_on_the_page(): void
    {
        $crawler = $this->reviewPage();
        $ids = $crawler->filter('[id]')->each(static fn (Crawler $node): string => (string) $node->attr('id'));
        $repeated = array_keys(array_filter(array_count_values($ids), static fn (int $count): bool => $count > 1));

        self::assertSame([], $repeated);
        self::assertCount(1, $crawler->filter('#general-comments-list'));
        self::assertCount(1, $crawler->filter('#orphaned-threads'));
    }

    public function test_the_bar_offers_the_comments_sheet_with_the_open_count(): void
    {
        $crawler = $this->reviewPage();
        $group = $crawler->filter('.lp-review-bar__group')->reduce(
            static fn (Crawler $node): bool => $node->filter('#review-bar-comments-count')->count() > 0,
        );

        self::assertCount(1, $group);
        self::assertSame('3', trim($crawler->filter('#review-bar-comments-count')->text()));
        self::assertSame('true', $crawler->filter('#review-bar-comments-count')->attr('aria-hidden'));
        self::assertSame('Comments, 3 open', trim($crawler->filter('#review-bar-comments-label')->text()));
        self::assertCount(1, $group->filter('[role="tab"][data-comments-sheet-status-param="open"]'));
        self::assertCount(1, $group->filter('[role="tab"][data-comments-sheet-status-param="resolved"]'));
        self::assertSame('Comment on the whole document', trim($group->filter('[data-action*="commentWholeDocument"]')->text()));
    }

    public function test_the_comments_sheet_comes_after_contents_and_before_decisions(): void
    {
        $crawler = $this->reviewPage();
        $buttons = $crawler->filter('.lp-review-bar__group .lp-review-bar__button')->each(
            static fn (Crawler $node): string => (string) ($node->attr('aria-label') ?? $node->filter('.sr-only')->text('')),
        );

        $commentsAt = array_search(true, array_map(static fn (string $label): bool => str_starts_with($label, 'Comments'), $buttons), true);
        self::assertNotFalse($commentsAt);
    }

    /** @return iterable<string, array{string}> */
    public static function actions(): iterable
    {
        yield 'resolve' => ['resolve'];
        yield 'reopen' => ['reopen'];
        yield 'delete' => ['delete'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('actions')]
    public function test_a_comment_stream_replaces_both_copies_and_updates_the_bar_count(string $action): void
    {
        $id = 'reopen' === $action ? $this->resolved->id : $this->thread->id;
        $this->client->request(
            Request::METHOD_POST,
            '/comments/'.$id.'/'.$action,
            ['_csrf_token' => 'csrf-token'],
            server: [
                'HTTP_ACCEPT' => TurboBundle::STREAM_MEDIA_TYPE,
                'HTTP_REFERER' => 'http://localhost/comments/'.$id.'/'.$action,
            ],
        );

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('target="comment-rows"', $content);
        self::assertStringContainsString('target="sheet-comment-rows"', $content);
        self::assertStringContainsString('target="review-bar-comments-count"', $content);
        self::assertStringContainsString('target="review-bar-comments-label"', $content);
        self::assertSame(1, substr_count($content, 'id="sheet-comment-rows"'));
        self::assertSame(1, substr_count($content, 'id="comment-rows"'));
    }

    public function test_the_bar_count_in_a_stream_counts_the_open_threads(): void
    {
        $this->client->request(
            Request::METHOD_POST,
            '/comments/'.$this->thread->id.'/resolve',
            ['_csrf_token' => 'csrf-token'],
            server: [
                'HTTP_ACCEPT' => TurboBundle::STREAM_MEDIA_TYPE,
                'HTTP_REFERER' => 'http://localhost/comments/'.$this->thread->id.'/resolve',
            ],
        );

        $content = (string) $this->client->getResponse()->getContent();
        self::assertMatchesRegularExpression('/target="review-bar-comments-count">\s*<template>\s*2\s*<\/template>/', $content);
        self::assertStringContainsString('Comments, 2 open', $content);
    }

    public function test_a_reply_stream_replaces_the_row_in_both_copies_with_its_reply_count(): void
    {
        $formName = 'reply_'.$this->orphan->id?->toBase32();
        $this->client->request(
            Request::METHOD_POST,
            '/comments/'.$this->orphan->id.'/reply',
            [$formName => ['body' => 'One reply', '_token' => 'csrf-token']],
            server: [
                'HTTP_ACCEPT' => TurboBundle::STREAM_MEDIA_TYPE,
                'HTTP_ORIGIN' => 'http://localhost',
            ],
        );

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('target="comment-row-'.$this->orphan->id.'"', $content);
        self::assertStringContainsString('target="sheet-comment-row-'.$this->orphan->id.'"', $content);
        self::assertStringContainsString('id="sheet-comment-row-'.$this->orphan->id.'"', $content);
        self::assertStringContainsString('1 reply', $content);
    }

    public function test_an_earlier_version_with_no_comment_has_no_comments_button(): void
    {
        $empty = new Document(owner: $this->owner, project: $this->document->project, title: 'Empty Doc');
        $empty->addVersion('# First', '<h1>First</h1>');
        $empty->addVersion('# Second', '<h1>Second</h1>');
        $this->em->persist($empty);
        $this->em->flush();

        $crawler = $this->client->request(
            Request::METHOD_GET,
            '/projects/'.$empty->project->id.'/documents/'.$empty->id.'/review/versions/1',
        );

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('#review-bar-comments-count'));
        self::assertCount(0, $crawler->filter('#sheet-comment-rows'));
    }
}
