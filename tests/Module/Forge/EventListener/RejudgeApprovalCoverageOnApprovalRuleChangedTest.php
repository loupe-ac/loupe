<?php

declare(strict_types=1);

namespace App\Tests\Module\Forge\EventListener;

use App\Module\Account\Entity\User;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Entity\PullRequestReview;
use App\Module\Forge\Entity\PullRequestState;
use App\Module\Forge\Messenger\RefreshPullRequestState;
use App\Module\Forge\PullRequestSnapshot;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\ApprovalRuleChanged;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class RejudgeApprovalCoverageOnApprovalRuleChangedTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
    }

    public function test_a_rule_change_resets_and_refreshes_each_open_approved_row_of_the_project_only(): void
    {
        $project = $this->project();
        $covered = $this->row($project, 1, PullRequestState::Open, approved: true, covered: 'merged1');
        $notCovered = $this->row($project, 2, PullRequestState::Open, approved: true, uncovered: 'pushed1');
        $unapproved = $this->row($project, 3, PullRequestState::Open, approved: false);
        $closed = $this->row($project, 4, PullRequestState::Closed, approved: true, covered: 'merged1');
        $elsewhere = $this->row($this->project(), 5, PullRequestState::Open, approved: true, covered: 'merged1');

        $dispatcher = self::getContainer()->get(EventDispatcherInterface::class);
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->dispatch(new ApprovalRuleChanged($project->id ?? throw new \LogicException('persisted')));
        $this->em->clear();

        self::assertSame(['approved1', null], $this->coverageOf($covered));
        self::assertSame(['approved1', null], $this->coverageOf($notCovered));
        self::assertSame(['merged1', null], $this->coverageOf($closed));
        self::assertSame(['merged1', null], $this->coverageOf($elsewhere));
        self::assertSame([null, null], $this->coverageOf($unapproved));

        $refreshed = [];
        $transport = self::getContainer()->get('messenger.transport.jobs');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        foreach ($transport->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof RefreshPullRequestState) {
                $refreshed[] = $message->pullRequestId;
            }
        }
        sort($refreshed);
        $expected = [(string) $covered->id, (string) $notCovered->id];
        sort($expected);
        self::assertSame($expected, $refreshed);
    }

    /** @return array{?string, ?string} */
    private function coverageOf(ForgePullRequest $row): array
    {
        $fresh = $this->em->find(ForgePullRequest::class, $row->id) ?? throw new \LogicException('The row exists.');

        return [$fresh->coveredSha, $fresh->uncoveredSha];
    }

    private function project(): Project
    {
        $owner = new User(fullName: 'Riley', email: 'rejudge-'.uniqid().'@example.com', password: 'hashed');
        $project = new Project($owner, 'rejudge-'.uniqid());
        $this->em->persist($owner);
        $this->em->persist($project);
        $this->em->flush();

        return $project;
    }

    private function row(Project $project, int $number, PullRequestState $state, bool $approved, ?string $covered = null, ?string $uncovered = null): ForgePullRequest
    {
        $row = new ForgePullRequest($project, 'github', 'acme/widgets', $number);
        $row->apply(new PullRequestSnapshot(
            state: $state,
            headSha: 'head1',
            review: $approved ? PullRequestReview::Approved : PullRequestReview::None,
            approvalSha: $approved ? 'approved1' : null,
            approvalId: $approved ? 'review1' : null,
        ));
        if (null !== $covered) {
            $row->coveredSha = $covered;
        }
        $row->uncoveredSha = $uncovered;
        $this->em->persist($row);
        $this->em->flush();

        return $row;
    }
}
