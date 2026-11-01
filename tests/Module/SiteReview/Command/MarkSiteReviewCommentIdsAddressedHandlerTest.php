<?php

declare(strict_types=1);

namespace App\Tests\Module\SiteReview\Command;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\SiteReview\Command\MarkSiteReviewCommentIdsAddressedCommand;
use App\Module\SiteReview\Command\MarkSiteReviewCommentIdsAddressedHandler;
use App\Module\SiteReview\Entity\SiteReviewComment;
use App\Module\SiteReview\Entity\SiteReviewCommentStatus;
use App\Security\McpBoundProjectVoter;
use App\Tests\Support\RecordingAuditor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;

final class MarkSiteReviewCommentIdsAddressedHandlerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private MarkSiteReviewCommentIdsAddressedHandler $handler;
    private RecordingAuditor $audit;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $this->audit = RecordingAuditor::installedIn(self::getContainer());

        $handler = self::getContainer()->get(MarkSiteReviewCommentIdsAddressedHandler::class);
        self::assertInstanceOf(MarkSiteReviewCommentIdsAddressedHandler::class, $handler);
        $this->handler = $handler;
    }

    public function test_each_id_gets_its_own_result_in_the_order_given(): void
    {
        $project = $this->project('ids-mixed@example.com');
        $pending = $this->feedback($project, SiteReviewCommentStatus::Pending);
        $addressed = $this->feedback($project, SiteReviewCommentStatus::Addressed);
        $resolved = $this->feedback($project, SiteReviewCommentStatus::Resolved);
        $foreign = $this->feedback($this->project('ids-foreign@example.com'), SiteReviewCommentStatus::Pending);
        $this->em->flush();

        $result = ($this->handler)(new MarkSiteReviewCommentIdsAddressedCommand($project, [
            (string) $pending->id,
            (string) $addressed->id,
            (string) $resolved->id,
            (string) $foreign->id,
            '0192f3c4-5d6e-7f80-9123-456789abcdef',
            'not-a-uuid',
        ]));

        self::assertSame([(string) $pending->id], $result->addressed);
        self::assertSame([
            ['id' => (string) $addressed->id, 'reason' => 'already_addressed'],
            ['id' => (string) $resolved->id, 'reason' => 'resolved'],
            ['id' => (string) $foreign->id, 'reason' => 'unknown'],
            ['id' => '0192f3c4-5d6e-7f80-9123-456789abcdef', 'reason' => 'unknown'],
            ['id' => 'not-a-uuid', 'reason' => 'invalid_id'],
        ], $result->skipped);
        self::assertSame(SiteReviewCommentStatus::Addressed, $pending->status);
        self::assertSame(SiteReviewCommentStatus::Pending, $foreign->status);
    }

    public function test_a_comment_of_another_project_is_recorded_as_a_refused_access(): void
    {
        $project = $this->project('ids-audit-own@example.com');
        $foreign = $this->feedback($this->project('ids-audit-foreign@example.com'), SiteReviewCommentStatus::Pending);
        $this->em->flush();
        $this->audit->forget();

        ($this->handler)(new MarkSiteReviewCommentIdsAddressedCommand($project, [(string) $foreign->id, '0192f3c4-5d6e-7f80-9123-456789abcdef']));

        $record = $this->audit->record('site_review.mcp_access_denied');
        self::assertSame(AuditOutcome::Refused, $record->outcome);
        self::assertSame(Auditor::CATEGORY_SECURITY, $record->category);
        self::assertNotNull($record->subject);
        self::assertSame('site_review_comment', $record->subject->type);
        self::assertSame((string) $foreign->id, $record->subject->id);
        self::assertSame([
            'attribute' => McpBoundProjectVoter::SITE_REVIEW_WRITE,
            'subjectId' => (string) $foreign->id,
            'subjectProjectId' => (string) $foreign->project->id,
            'boundProjectId' => (string) $project->id,
        ], $record->context);
    }

    public function test_an_empty_batch_returns_an_empty_result(): void
    {
        $result = ($this->handler)(new MarkSiteReviewCommentIdsAddressedCommand($this->project('ids-empty@example.com'), []));

        self::assertSame([], $result->addressed);
        self::assertSame([], $result->skipped);
    }

    /** @param non-empty-string $email */
    private function project(string $email): Project
    {
        $user = new User(fullName: 'U', email: $email, password: 'hashed');
        $this->em->persist($user);
        $project = new Project($user, 'p-'.uniqid());
        $this->em->persist($project);

        return $project;
    }

    private function feedback(Project $project, SiteReviewCommentStatus $status): SiteReviewComment
    {
        $comment = new SiteReviewComment($project, 0, 'Feedback', 'https://app.example/page');
        $comment->status = $status;
        $this->em->persist($comment);

        return $comment;
    }
}
