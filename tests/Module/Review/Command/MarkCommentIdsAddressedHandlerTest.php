<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Command;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Command\MarkCommentIdsAddressedCommand;
use App\Module\Review\Command\MarkCommentIdsAddressedHandler;
use App\Module\Review\Entity\Comment;
use App\Module\Review\Entity\CommentStatus;
use App\Module\Review\Entity\Document;
use App\Module\Review\ValueObject\Anchor;
use App\Security\McpBoundProjectVoter;
use App\Tests\Support\RecordingAuditor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;

final class MarkCommentIdsAddressedHandlerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private MarkCommentIdsAddressedHandler $handler;
    private RecordingAuditor $audit;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $this->audit = RecordingAuditor::installedIn(self::getContainer());

        $handler = self::getContainer()->get(MarkCommentIdsAddressedHandler::class);
        self::assertInstanceOf(MarkCommentIdsAddressedHandler::class, $handler);
        $this->handler = $handler;
    }

    public function test_each_id_gets_its_own_result_in_the_order_given(): void
    {
        $owner = $this->user('ids-mixed@example.com');
        $pending = $this->rootComment($owner);
        $project = $pending->version->document->project;

        $addressed = $this->siblingComment($pending);
        $addressed->status = CommentStatus::Addressed;
        $resolved = $this->siblingComment($pending);
        $resolved->status = CommentStatus::Resolved;
        $reply = new Comment(
            version: $pending->version,
            author: $owner,
            body: 'A reply.',
            anchor: $pending->anchor,
            parent: $pending,
        );
        $this->em->persist($reply);
        $foreign = $this->rootComment($this->user('ids-foreign@example.com'));
        $this->em->flush();

        $result = ($this->handler)(new MarkCommentIdsAddressedCommand($project, [
            'not-a-uuid',
            'zzzzzzzz-9b1c-42f7-8e44-a10a6e57c3d9',
            '0192f3c4-5d6e-7f80-9123-456789abcdef',
            (string) $foreign->id,
            (string) $reply->id,
            (string) $addressed->id,
            (string) $resolved->id,
            (string) $pending->id,
        ]));

        self::assertSame([(string) $pending->id], $result->addressed);
        self::assertSame([
            ['id' => 'not-a-uuid', 'reason' => 'invalid_id'],
            ['id' => 'zzzzzzzz-9b1c-42f7-8e44-a10a6e57c3d9', 'reason' => 'invalid_id'],
            ['id' => '0192f3c4-5d6e-7f80-9123-456789abcdef', 'reason' => 'not_found'],
            ['id' => (string) $foreign->id, 'reason' => 'not_found'],
            ['id' => (string) $reply->id, 'reason' => 'is_reply'],
            ['id' => (string) $addressed->id, 'reason' => 'already_addressed'],
            ['id' => (string) $resolved->id, 'reason' => 'already_resolved'],
        ], $result->skipped);
        self::assertSame(CommentStatus::Addressed, $pending->status);
        self::assertSame(CommentStatus::Pending, $foreign->status);
    }

    public function test_a_comment_of_another_project_is_recorded_as_a_refused_access(): void
    {
        $project = $this->rootComment($this->user('ids-audit-own@example.com'))->version->document->project;
        $foreign = $this->rootComment($this->user('ids-audit-foreign@example.com'));
        $this->em->flush();
        $this->audit->forget();

        ($this->handler)(new MarkCommentIdsAddressedCommand($project, [(string) $foreign->id, '0192f3c4-5d6e-7f80-9123-456789abcdef']));

        $record = $this->audit->record('review.mcp_access_denied');
        self::assertSame(AuditOutcome::Refused, $record->outcome);
        self::assertSame(Auditor::CATEGORY_SECURITY, $record->category);
        self::assertNotNull($record->subject);
        self::assertSame('comment', $record->subject->type);
        self::assertSame((string) $foreign->id, $record->subject->id);
        self::assertSame([
            'attribute' => McpBoundProjectVoter::COMMENT_WRITE,
            'subjectId' => (string) $foreign->id,
            'subjectProjectId' => (string) $foreign->version->document->project->id,
            'boundProjectId' => (string) $project->id,
        ], $record->context);
    }

    public function test_an_empty_batch_returns_an_empty_result(): void
    {
        $project = $this->rootComment($this->user('ids-empty@example.com'))->version->document->project;

        $result = ($this->handler)(new MarkCommentIdsAddressedCommand($project, []));

        self::assertSame([], $result->addressed);
        self::assertSame([], $result->skipped);
    }

    /** @param non-empty-string $email */
    private function user(string $email): User
    {
        $user = new User(fullName: 'U', email: $email, password: 'hashed');
        $this->em->persist($user);

        return $user;
    }

    private function rootComment(User $owner): Comment
    {
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

    private function siblingComment(Comment $sibling): Comment
    {
        $comment = new Comment(
            version: $sibling->version,
            author: $sibling->author,
            body: 'Another thread.',
            anchor: $sibling->anchor,
        );
        $this->em->persist($comment);

        return $comment;
    }
}
