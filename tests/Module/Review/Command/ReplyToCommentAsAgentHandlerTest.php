<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Command;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Command\CommentNotInDocument;
use App\Module\Review\Command\ReplyToCommentAsAgentCommand;
use App\Module\Review\Command\ReplyToCommentAsAgentHandler;
use App\Module\Review\Entity\Comment;
use App\Module\Review\Entity\Document;
use App\Module\Review\ValueObject\Anchor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ReplyToCommentAsAgentHandlerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ReplyToCommentAsAgentHandler $handler;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $handler = self::getContainer()->get(ReplyToCommentAsAgentHandler::class);
        self::assertInstanceOf(ReplyToCommentAsAgentHandler::class, $handler);
        $this->handler = $handler;
    }

    public function test_a_reply_without_a_document_lands_in_the_thread(): void
    {
        $comment = $this->rootComment('reply-none@example.com');

        $reply = ($this->handler)(new ReplyToCommentAsAgentCommand($comment, 'Done.'));

        self::assertSame($comment, $reply->parent);
        self::assertSame('Done.', $reply->body);
    }

    public function test_a_reply_through_the_document_of_the_comment_lands_in_the_thread(): void
    {
        $comment = $this->rootComment('reply-same@example.com');

        $reply = ($this->handler)(new ReplyToCommentAsAgentCommand($comment, 'Done.', $comment->version->document));

        self::assertSame($comment, $reply->parent);
    }

    public function test_a_reply_through_another_document_is_refused_and_writes_nothing(): void
    {
        $comment = $this->rootComment('reply-mine@example.com');
        $other = $this->rootComment('reply-other@example.com')->version->document;
        $before = (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM comments');

        try {
            ($this->handler)(new ReplyToCommentAsAgentCommand($comment, 'Done.', $other));
            self::fail('A comment of another document must be refused.');
        } catch (CommentNotInDocument) {
        }

        self::assertSame($before, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM comments'));
    }

    /** @param non-empty-string $email */
    private function rootComment(string $email): Comment
    {
        $owner = new User(fullName: 'U', email: $email, password: 'hashed');
        $this->em->persist($owner);
        $project = new Project($owner, 'p-'.uniqid());
        $this->em->persist($project);

        $document = new Document(owner: $owner, project: $project, title: 'Reviewable');
        $document->addVersion('# Hello', '<h1>Hello</h1>');
        $this->em->persist($document);

        $comment = new Comment(
            version: $document->currentVersion(),
            author: $owner,
            body: 'Please clarify.',
            anchor: new Anchor('Hello', '', '', 2),
        );
        $this->em->persist($comment);
        $this->em->flush();

        return $comment;
    }
}
