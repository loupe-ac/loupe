<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Controller;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Command\AddCommentCommand;
use App\Module\Review\Command\AddCommentHandler;
use App\Module\Review\Command\CreateDocumentCommand;
use App\Module\Review\Command\CreateDocumentHandler;
use App\Module\Review\Command\DeleteCommentCommand;
use App\Module\Review\Command\DeleteCommentHandler;
use App\Module\Review\Command\ReplyToCommentCommand;
use App\Module\Review\Command\ReplyToCommentHandler;
use App\Module\Review\Command\ReviseDocumentCommand;
use App\Module\Review\Command\ReviseDocumentHandler;
use App\Module\Review\Entity\Comment;
use App\Module\Review\Entity\Document;
use App\Tests\Support\AcceptedTerms;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

/** The fragment routes and the markup that make the document pages live. */
final class ReviewLiveRoutesTest extends WebTestCase
{
    private const string STREAM = 'text/vnd.turbo-stream.html';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $owner;
    private Project $project;
    private Document $document;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->owner = $this->user('live-routes-owner@example.com');
        $this->project = new Project($this->owner, 'live-'.bin2hex(random_bytes(4)));
        $this->em->persist($this->project);
        $this->em->flush();
        $this->document = $this->create('Live routes');
        $this->client->loginUser($this->owner);
    }

    public function test_the_band_names_a_newer_version_with_a_plain_link(): void
    {
        $this->revise();

        $this->get('/review/live-band?shown=1');

        $streams = $this->streams();
        self::assertCount(1, $streams);
        self::assertSame(['update', 'live-band'], [$streams[0]['action'], $streams[0]['target']]);
        $band = $streams[0]['content']->filter('[data-live-band="version"]');
        self::assertStringContainsString('Version 2 is out.', $band->text());
        $link = $band->filter('a');
        self::assertSame('Show version 2', trim($link->text()));
        self::assertSame($this->base().'/review', $link->attr('href'));
        self::assertCount(0, $streams[0]['content']->filter('[data-live-band="archived"]'));
    }

    public function test_the_band_shows_no_version_when_the_page_shows_the_latest(): void
    {
        $this->revise();

        $this->get('/review/live-band?shown=2');

        self::assertSame('', trim($this->streams()[0]['content']->text()));
    }

    public function test_the_band_says_archived_with_an_unarchive_form_and_clears_when_unarchived(): void
    {
        $this->document->archivedAt = new \DateTimeImmutable();
        $this->em->flush();

        $this->get('/review/live-band?shown=1');

        $band = $this->streams()[0]['content']->filter('[data-live-band="archived"]');
        self::assertStringContainsString('This document was archived.', $band->text());
        self::assertSame($this->base().'/unarchive', $band->filter('form')->attr('action'));

        $this->document->archivedAt = null;
        $this->em->flush();
        $this->get('/review/live-band?shown=1');

        self::assertCount(0, $this->streams()[0]['content']->filter('[data-live-band]'));
    }

    public function test_the_band_is_refused_to_a_stranger(): void
    {
        $this->client->loginUser($this->user('live-routes-stranger@example.com'));

        $this->get('/review/live-band?shown=1');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function test_every_thread_comes_as_morphing_streams(): void
    {
        $comment = $this->comment();

        $this->get('/review/threads?shown=1');

        self::assertStringStartsWith(self::STREAM, (string) $this->client->getResponse()->headers->get('Content-Type'));
        $streams = $this->streams();
        self::assertSame(
            ['comment-threads', 'comment-rows', 'sheet-comment-rows'],
            array_slice(array_column($streams, 'target'), 0, 3),
        );
        foreach (array_slice($streams, 0, 3) as $stream) {
            self::assertSame('replace', $stream['action']);
            self::assertSame('morph', $stream['method']);
        }
        self::assertContains('review-bar-comments-count', array_column($streams, 'target'));
        self::assertCount(1, $streams[0]['content']->filter('#comment-thread-'.$comment->id));
    }

    public function test_one_thread_comes_as_three_morphing_replaces(): void
    {
        $comment = $this->comment();
        $this->service(ReplyToCommentHandler::class)(new ReplyToCommentCommand($this->owner, $comment, 'A reply from elsewhere'));

        $this->get('/review/threads/'.$comment->id);

        $streams = $this->streams();
        self::assertSame(
            ['comment-thread-'.$comment->id, 'comment-row-'.$comment->id, 'sheet-comment-row-'.$comment->id],
            array_column($streams, 'target'),
        );
        foreach ($streams as $stream) {
            self::assertSame(['replace', 'morph'], [$stream['action'], $stream['method']]);
        }
        self::assertStringContainsString('A reply from elsewhere', $streams[0]['content']->text());
    }

    public function test_a_reply_id_reads_the_thread_of_its_root(): void
    {
        $comment = $this->comment();
        $reply = $this->service(ReplyToCommentHandler::class)(new ReplyToCommentCommand($this->owner, $comment, 'A reply'));

        $this->get('/review/threads/'.$reply->id);

        self::assertSame('comment-thread-'.$comment->id, $this->streams()[0]['target']);
    }

    public function test_a_thread_that_is_gone_answers_every_thread(): void
    {
        $comment = $this->comment();
        $this->service(DeleteCommentHandler::class)(new DeleteCommentCommand($comment));

        foreach ([(string) $comment->id, '0199aaaa-0000-7000-8000-000000000001', 'not-an-id'] as $id) {
            $this->get('/review/threads/'.$id);

            self::assertSame('comment-threads', $this->streams()[0]['target'], $id);
        }
    }

    public function test_a_thread_of_an_older_version_answers_every_thread(): void
    {
        $comment = $this->comment();
        $this->revise();

        $this->get('/review/threads/'.$comment->id);

        self::assertSame('comment-threads', $this->streams()[0]['target']);
    }

    public function test_a_thread_of_another_document_answers_every_thread(): void
    {
        $other = $this->create('Another document');
        $foreign = $this->service(AddCommentHandler::class)(new AddCommentCommand($this->owner, $other, 1, 'Another', '', '', 'Foreign'));

        $this->get('/review/threads/'.$foreign->id);

        self::assertSame('comment-threads', $this->streams()[0]['target']);
        self::assertStringNotContainsString('Foreign', (string) $this->client->getResponse()->getContent());
    }

    public function test_a_page_that_shows_an_older_version_reads_no_thread(): void
    {
        $comment = $this->comment();
        $this->revise();

        foreach (['/review/threads?shown=1', '/review/threads/'.$comment->id.'?shown=1'] as $suffix) {
            $this->get($suffix);

            self::assertResponseIsSuccessful();
            self::assertSame('', (string) $this->client->getResponse()->getContent(), $suffix);
        }

        $this->get('/review/threads?shown=2');
        self::assertSame('comment-threads', $this->streams()[0]['target']);
    }

    public function test_the_thread_routes_are_refused_to_a_stranger(): void
    {
        $comment = $this->comment();
        $this->client->loginUser($this->user('live-routes-stranger2@example.com'));

        $this->get('/review/threads');
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $this->get('/review/threads/'.$comment->id);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function test_the_streams_of_the_actor_do_not_morph_unless_asked(): void
    {
        $comment = $this->comment();
        $twig = $this->service(Environment::class);

        foreach ([
            ['@Review/_comment_thread.stream.html.twig', ['comment' => $comment, 'replies' => [], 'replyForm' => null]],
            ['@Review/_comment_added.stream.html.twig', ['comments' => [$comment]]],
        ] as [$template, $context]) {
            self::assertStringNotContainsString('method="morph"', $twig->render($template, $context));
            self::assertStringContainsString('method="morph"', $twig->render($template, $context + ['morph' => true]));
        }
    }

    public function test_the_review_page_carries_what_the_live_part_reads(): void
    {
        $this->revise();

        $crawler = $this->page('/review');
        $root = $crawler->filter('.lp-review');
        self::assertSame('2', $root->attr('data-live-shown-version'));
        self::assertSame('false', $root->attr('data-live-pinned'));
        self::assertStringContainsString('review-live', (string) $root->attr('data-controller'));
        self::assertSame((string) $this->document->id, $root->attr('data-review-live-document-value'));
        self::assertSame($this->base().'/review/live-band', $root->attr('data-review-live-band-url-value'));
        self::assertSame($this->base().'/review/threads', $root->attr('data-review-live-threads-url-value'));
        self::assertSame('true', $root->attr('data-review-live-threads-live-value'));
        self::assertCount(1, $crawler->filter('#live-band'));
    }

    public function test_a_pinned_page_is_marked_and_reads_no_threads(): void
    {
        $this->revise();

        $root = $this->page('/review/versions/1')->filter('.lp-review');

        self::assertSame('1', $root->attr('data-live-shown-version'));
        self::assertSame('true', $root->attr('data-live-pinned'));
        self::assertSame('false', $root->attr('data-review-live-threads-live-value'));
    }

    public function test_the_page_of_an_archived_document_shows_the_band_at_load(): void
    {
        $this->document->archivedAt = new \DateTimeImmutable();
        $this->em->flush();

        $crawler = $this->page('/review');

        self::assertStringContainsString('This document was archived.', $crawler->filter('#live-band')->text());
    }

    public function test_the_history_is_a_morphing_frame_that_follows_versions_threads_and_verdicts(): void
    {
        $frame = $this->page('/review/history')->filter('turbo-frame#document-history-live');

        self::assertCount(1, $frame);
        self::assertSame('morph', $frame->attr('refresh'));
        self::assertSame('_top', $frame->attr('target'));
        self::assertSame('live-region', $frame->attr('data-controller'));
        self::assertSame('frame', $frame->attr('data-live-region-mode-value'));
        self::assertSame(['review.version_published', 'review.thread_changed', 'review.document_state_changed'], json_decode((string) $frame->attr('data-live-region-types-value'), true));
        self::assertSame(['documentId' => (string) $this->document->id], json_decode((string) $frame->attr('data-live-region-match-value'), true));
        self::assertSame($this->base().'/review/history', $frame->attr('data-live-region-url-value'));
        self::assertCount(1, $frame->filter('[data-version-number="1"]'));
    }

    public function test_the_rename_form_holds_a_hidden_band_that_opens_the_document_in_a_new_tab(): void
    {
        $crawler = $this->page('/rename');

        $region = $crawler->filter('[data-controller="live-region"]');
        self::assertSame('band', $region->attr('data-live-region-mode-value'));
        self::assertSame(['review.document_state_changed'], json_decode((string) $region->attr('data-live-region-types-value'), true));
        self::assertSame($this->base().'/fingerprint', $region->attr('data-live-region-url-value'));
        $band = $region->filter('[data-live-region-target="band"]');
        self::assertNotNull($band->attr('hidden'));
        self::assertSame('_blank', $band->filter('a')->attr('target'));
        self::assertSame($this->base().'/review', $band->filter('a')->attr('href'));

        $this->get('/fingerprint');
        self::assertSame($region->attr('data-live-fingerprint'), $this->client->getResponse()->getContent());
    }

    public function test_the_fingerprint_changes_with_the_title_and_is_refused_to_a_stranger(): void
    {
        $this->get('/fingerprint');
        $before = $this->client->getResponse()->getContent();

        $this->document->title = 'A different title';
        $this->em->flush();
        $this->get('/fingerprint');
        self::assertNotSame($before, $this->client->getResponse()->getContent());

        $this->client->loginUser($this->user('live-routes-stranger3@example.com'));
        $this->get('/fingerprint');
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    private function base(): string
    {
        return '/projects/'.$this->project->id.'/documents/'.$this->document->id;
    }

    private function get(string $suffix): void
    {
        $this->client->request(Request::METHOD_GET, $this->base().$suffix, server: ['HTTP_X_LOUPE_LIVE_REFRESH' => '1']);
    }

    private function page(string $suffix): Crawler
    {
        $crawler = $this->client->request(Request::METHOD_GET, $this->base().$suffix);
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    private function create(string $title): Document
    {
        return $this->service(CreateDocumentHandler::class)(new CreateDocumentCommand($this->project, $title, "# {$title}\n\nThe passage under review."));
    }

    private function revise(): void
    {
        $this->service(ReviseDocumentHandler::class)(new ReviseDocumentCommand($this->document, "# Live routes\n\nRevised text.", 'Revised'));
    }

    private function comment(): Comment
    {
        return $this->service(AddCommentHandler::class)(new AddCommentCommand($this->owner, $this->document, 1, 'The passage', '', '', 'A comment'));
    }

    /** @param non-empty-string $email */
    private function user(string $email): User
    {
        $user = new User(fullName: 'Live User', email: $email, password: 'x');
        $user->emailVerifiedAt = new \DateTimeImmutable();
        AcceptedTerms::stamp($user, static::getContainer());
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /** @return list<array{action: string, target: string, method: ?string, content: Crawler}> */
    private function streams(): array
    {
        $crawler = new Crawler((string) $this->client->getResponse()->getContent());
        $streams = [];
        foreach ($crawler->filter('turbo-stream') as $node) {
            $stream = new Crawler($node);
            $template = $stream->filter('template');
            $streams[] = [
                'action' => (string) $stream->attr('action'),
                'target' => (string) $stream->attr('target'),
                'method' => $stream->attr('method'),
                'content' => new Crawler(\count($template) > 0 ? $template->html() : ''),
            ];
        }

        return $streams;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function service(string $class): object
    {
        $service = static::getContainer()->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }
}
