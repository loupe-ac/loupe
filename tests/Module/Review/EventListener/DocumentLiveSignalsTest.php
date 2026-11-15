<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\EventListener;

use App\Mercure\ProjectTopicBuilder;
use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Command\AddCommentCommand;
use App\Module\Review\Command\AddCommentHandler;
use App\Module\Review\Command\CreateDocumentCommand;
use App\Module\Review\Command\CreateDocumentHandler;
use App\Module\Review\Command\DeleteCommentCommand;
use App\Module\Review\Command\DeleteCommentHandler;
use App\Module\Review\Command\MarkCommentsAddressedCommand;
use App\Module\Review\Command\MarkCommentsAddressedHandler;
use App\Module\Review\Command\RenameDocumentCommand;
use App\Module\Review\Command\RenameDocumentHandler;
use App\Module\Review\Command\ReopenCommentCommand;
use App\Module\Review\Command\ReopenCommentHandler;
use App\Module\Review\Command\ReplyToCommentAsAgentCommand;
use App\Module\Review\Command\ReplyToCommentAsAgentHandler;
use App\Module\Review\Command\ReplyToCommentCommand;
use App\Module\Review\Command\ReplyToCommentHandler;
use App\Module\Review\Command\ResolveCommentCommand;
use App\Module\Review\Command\ResolveCommentHandler;
use App\Module\Review\Command\RestoreCommentCommand;
use App\Module\Review\Command\RestoreCommentHandler;
use App\Module\Review\Command\ReviseDocumentCommand;
use App\Module\Review\Command\ReviseDocumentHandler;
use App\Module\Review\Command\SetDocumentSeriesCommand;
use App\Module\Review\Command\SetDocumentSeriesHandler;
use App\Module\Review\Command\SetDocumentTagsCommand;
use App\Module\Review\Command\SetDocumentTagsHandler;
use App\Module\Review\Entity\Comment;
use App\Module\Review\Entity\Document;
use App\Module\Review\Event\CommentThreadChanged;
use App\Module\Review\Event\DocumentVersionPublished;
use App\Tests\Support\DispatchedEvents;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\MockHub;
use Symfony\Component\Mercure\Update;

/**
 * Drives each writer of a review signal through its real handler, ends the
 * request the way the kernel does, and reads what reaches the hub.
 */
final class DocumentLiveSignalsTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private User $owner;
    private Project $project;
    private Document $document;

    /** @var list<Update> */
    private array $published = [];

    protected function setUp(): void
    {
        self::bootKernel();
        self::getContainer()->set('mercure.hub.default', new MockHub(
            'http://mercure/.well-known/mercure',
            new StaticTokenProvider('token'),
            function (Update $update): string {
                $this->published[] = $update;

                return 'id';
            },
        ));

        $this->em = $this->service(EntityManagerInterface::class);
        $this->owner = new User(fullName: 'Signal Owner', email: 'signals-'.uniqid().'@example.com', password: 'x');
        $this->em->persist($this->owner);
        $this->project = new Project($this->owner, 'signals-'.bin2hex(random_bytes(4)));
        $this->em->persist($this->project);
        $this->em->flush();
        $this->document = $this->service(CreateDocumentHandler::class)(new CreateDocumentCommand($this->project, 'Signals', "# Signals\n\nThe passage under review."));
        $this->drain();
    }

    public function test_a_revision_signals_the_version_and_the_list(): void
    {
        $this->service(ReviseDocumentHandler::class)(new ReviseDocumentCommand($this->document, "# Signals\n\nRevised.", 'Revised'));

        $messages = $this->drain();
        self::assertContains(
            ['type' => 'review.version_published', 'documentId' => (string) $this->document->id, 'versionNumber' => 2, 'origin' => null],
            $this->on($messages, $this->documentTopic()),
        );
        self::assertContains('documents.changed', array_column($this->on($messages, $this->documentsTopic()), 'type'));
    }

    public function test_a_revision_dispatches_its_event_after_the_commit(): void
    {
        $published = DispatchedEvents::of(self::getContainer(), DocumentVersionPublished::class);

        $this->service(ReviseDocumentHandler::class)(new ReviseDocumentCommand($this->document, "# Signals\n\nRevised.", 'Revised'));

        self::assertCount(1, $published->events());
        self::assertSame(2, $published->events()[0]->versionNumber);
        self::assertSame([0], $published->transactionDepths());
    }

    public function test_a_new_comment_signals_the_collection(): void
    {
        $comment = $this->comment();

        $this->assertThread($this->drain(), $comment, 'collection');
    }

    public function test_a_reply_signals_the_thread_only(): void
    {
        $comment = $this->comment();
        $this->drain();

        $this->service(ReplyToCommentHandler::class)(new ReplyToCommentCommand($this->owner, $comment, 'A reply'));

        $this->assertThread($this->drain(), $comment, 'thread');
    }

    public function test_an_agent_reply_signals_the_thread_once(): void
    {
        $comment = $this->comment();
        $this->drain();

        $this->service(ReplyToCommentAsAgentHandler::class)(new ReplyToCommentAsAgentCommand($comment, 'An agent reply'));

        $threads = array_filter($this->on($this->drain(), $this->documentTopic()), static fn (array $m): bool => 'review.thread_changed' === $m['type']);
        self::assertCount(1, $threads);
        self::assertSame(['thread'], array_column($threads, 'scope'));
    }

    public function test_resolve_and_reopen_signal_the_collection(): void
    {
        $comment = $this->comment();
        $this->drain();

        $this->service(ResolveCommentHandler::class)(new ResolveCommentCommand($comment));
        $this->assertThread($this->drain(), $comment, 'collection');

        $this->service(ReopenCommentHandler::class)(new ReopenCommentCommand($comment));
        $this->assertThread($this->drain(), $comment, 'collection');
    }

    public function test_delete_and_restore_signal_the_collection(): void
    {
        $comment = $this->comment();
        $this->drain();

        $this->service(DeleteCommentHandler::class)(new DeleteCommentCommand($comment));
        $this->assertThread($this->drain(), $comment, 'collection');

        $this->service(RestoreCommentHandler::class)(new RestoreCommentCommand($comment, $comment->deletionSequence));
        $this->assertThread($this->drain(), $comment, 'collection');
    }

    public function test_marking_a_comment_addressed_signals_the_collection(): void
    {
        $comment = $this->comment();
        $this->drain();

        $this->service(MarkCommentsAddressedHandler::class)(new MarkCommentsAddressedCommand([$comment]));

        $this->assertThread($this->drain(), $comment, 'collection');
    }

    public function test_a_comment_that_was_not_addressed_signals_nothing(): void
    {
        $comment = $this->comment();
        $this->service(ResolveCommentHandler::class)(new ResolveCommentCommand($comment));
        $this->drain();

        $this->service(MarkCommentsAddressedHandler::class)(new MarkCommentsAddressedCommand([$comment]));

        self::assertSame([], $this->drain());
    }

    public function test_a_thread_event_is_dispatched_after_the_commit(): void
    {
        $published = DispatchedEvents::of(self::getContainer(), CommentThreadChanged::class);

        $comment = $this->comment();
        $this->service(ReplyToCommentHandler::class)(new ReplyToCommentCommand($this->owner, $comment, 'A reply'));
        $this->service(ResolveCommentHandler::class)(new ResolveCommentCommand($comment));

        self::assertCount(3, $published->events());
        self::assertSame([0, 0, 0], $published->transactionDepths());
    }

    public function test_a_rename_signals_the_state_and_the_list(): void
    {
        $this->service(RenameDocumentHandler::class)(new RenameDocumentCommand($this->document, 'A new title'));

        $messages = $this->drain();
        self::assertContains('review.document_state_changed', array_column($this->on($messages, $this->documentTopic()), 'type'));
        self::assertContains('documents.changed', array_column($this->on($messages, $this->documentsTopic()), 'type'));
    }

    public function test_tags_signal_the_state_and_the_list(): void
    {
        $this->service(SetDocumentTagsHandler::class)(new SetDocumentTagsCommand($this->document, ['plan']));

        $messages = $this->drain();
        self::assertContains('review.document_state_changed', array_column($this->on($messages, $this->documentTopic()), 'type'));
        self::assertContains('documents.changed', array_column($this->on($messages, $this->documentsTopic()), 'type'));
    }

    public function test_a_series_change_signals_the_list_only(): void
    {
        $this->service(SetDocumentSeriesHandler::class)(new SetDocumentSeriesCommand($this->document, 'Roadmap', 1));

        $messages = $this->drain();
        self::assertSame(['documents.changed'], array_column($this->on($messages, $this->documentsTopic()), 'type'));
        self::assertSame([], $this->on($messages, $this->documentTopic()));
    }

    public function test_a_new_document_signals_the_list(): void
    {
        $this->service(CreateDocumentHandler::class)(new CreateDocumentCommand($this->project, 'Another', '# Another'));

        self::assertContains('documents.changed', array_column($this->on($this->drain(), $this->documentsTopic()), 'type'));
    }

    /** @param list<array{topic: string, data: array<string, mixed>}> $messages */
    private function assertThread(array $messages, Comment $comment, string $scope): void
    {
        $thread = array_values(array_filter(
            $this->on($messages, $this->documentTopic()),
            static fn (array $message): bool => 'review.thread_changed' === $message['type'],
        ));
        self::assertSame(
            [['type' => 'review.thread_changed', 'documentId' => (string) $this->document->id, 'commentId' => (string) $comment->id, 'scope' => $scope, 'origin' => null]],
            $thread,
        );
        self::assertContains('documents.changed', array_column($this->on($messages, $this->documentsTopic()), 'type'));
    }

    private function comment(): Comment
    {
        return $this->service(AddCommentHandler::class)(new AddCommentCommand($this->owner, $this->document, 1, 'The passage', '', '', 'A comment'));
    }

    /**
     * @param list<array{topic: string, data: array<string, mixed>}> $messages
     *
     * @return list<array<string, mixed>>
     */
    private function on(array $messages, string $topic): array
    {
        return array_values(array_map(
            static fn (array $message): array => $message['data'],
            array_filter($messages, static fn (array $message): bool => $message['topic'] === $topic),
        ));
    }

    /**
     * Ends the request the way the kernel does, and answers what the hub received since the last call.
     *
     * @return list<array{topic: string, data: array<string, mixed>}>
     */
    private function drain(): array
    {
        self::assertNotNull(self::$kernel);
        $this->service(EventDispatcherInterface::class)->dispatch(new TerminateEvent(self::$kernel, Request::create('/'), new Response()), KernelEvents::TERMINATE);

        $messages = [];
        foreach ($this->published as $update) {
            self::assertTrue($update->isPrivate());
            $data = json_decode($update->getData(), true, flags: \JSON_THROW_ON_ERROR);
            self::assertIsArray($data);
            $messages[] = ['topic' => $update->getTopics()[0], 'data' => $data];
        }
        $this->published = [];

        return $messages;
    }

    private function documentTopic(): string
    {
        return $this->service(ProjectTopicBuilder::class)->forDocument($this->project->requireId(), $this->document->id ?? throw new \LogicException('No id.'));
    }

    private function documentsTopic(): string
    {
        return $this->service(ProjectTopicBuilder::class)->forDocuments($this->project->requireId());
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
        $service = self::getContainer()->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }
}
