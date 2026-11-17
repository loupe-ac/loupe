<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Workflow;

use App\Module\Board\Entity\Card;
use App\Module\Board\Workflow\FixRunFactProvider;
use App\Module\Board\Workflow\FixRunFacts;
use App\Module\Board\Workflow\StaleApprovalFactProvider;
use App\Module\Board\Workflow\StaleApprovalFacts;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Tests\Module\Workflow\Action\ActionScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

final class CommentFactProvidersTest extends KernelTestCase
{
    use ActionScenario;

    public function test_the_fix_run_facts_list_the_open_or_recent_fix_runs_with_no_comment_by_id(): void
    {
        self::bootKernel();
        $card = $this->card($this->workflowProject('fix-run-facts'), 'in-review');
        $this->pullRequest($card);
        $second = $this->fixRun($card, WorkerRunState::Running);
        $first = $this->fixRun($card, WorkerRunState::Queued);
        $this->fixRun($card, WorkerRunState::Failed, new \DateTimeImmutable('-2 hours'));
        $ids = [(string) $first->id, (string) $second->id];
        sort($ids);

        $provider = $this->service(FixRunFactProvider::class);
        $facts = $provider->build($card->snapshot());

        self::assertEquals(new FixRunFacts($ids), $facts);
        self::assertSame($ids, $provider->fingerprint($facts));
    }

    public function test_the_fix_run_time_is_one_second_after_the_window_of_the_earliest_closed_run(): void
    {
        self::bootKernel();
        $clock = new MockClock('2026-11-16 12:00:00');
        self::getContainer()->set('clock', $clock);
        $card = $this->card($this->workflowProject('fix-run-time'), 'in-review');
        $this->pullRequest($card);
        $later = $this->fixRun($card, WorkerRunState::Failed, new \DateTimeImmutable('2026-11-16 11:20:00'), new \DateTimeImmutable('2026-11-16 11:40:00'));
        $earlier = $this->fixRun($card, WorkerRunState::Succeeded, new \DateTimeImmutable('2026-11-16 11:00:00'), new \DateTimeImmutable('2026-11-16 11:30:00'));
        $this->fixRun($card, WorkerRunState::Running, new \DateTimeImmutable('2026-11-16 10:00:00'));
        $provider = $this->service(FixRunFactProvider::class);

        $facts = $provider->build($card->snapshot());
        self::assertInstanceOf(FixRunFacts::class, $facts);
        self::assertEquals(new \DateTimeImmutable('2026-11-16 12:30:01'), $facts->changesAt());
        self::assertContains((string) $earlier->id, $facts->uncommentedRunIds);

        $clock->modify('2026-11-16 12:30:00');
        $atEdge = $provider->build($card->snapshot());
        self::assertInstanceOf(FixRunFacts::class, $atEdge);
        self::assertContains((string) $earlier->id, $atEdge->uncommentedRunIds, 'the exact edge still gives the old answer');

        $clock->modify('2026-11-16 12:30:01');
        $after = $provider->build($card->snapshot());
        self::assertInstanceOf(FixRunFacts::class, $after);
        self::assertNotContains((string) $earlier->id, $after->uncommentedRunIds);
        self::assertContains((string) $later->id, $after->uncommentedRunIds);
        self::assertEquals(new \DateTimeImmutable('2026-11-16 12:40:01'), $after->changesAt());
        self::assertSame($after->uncommentedRunIds, $provider->fingerprint($after));
    }

    public function test_an_open_fix_run_gives_no_time(): void
    {
        self::bootKernel();
        $card = $this->card($this->workflowProject('fix-run-open-time'), 'in-review');
        $this->pullRequest($card);
        $this->fixRun($card, WorkerRunState::Running);

        $facts = $this->service(FixRunFactProvider::class)->build($card->snapshot());

        self::assertInstanceOf(FixRunFacts::class, $facts);
        self::assertNull($facts->changesAt());
    }

    public function test_the_stale_approval_facts_map_each_pull_request_to_its_head(): void
    {
        self::bootKernel();
        $card = $this->card($this->workflowProject('stale-approval-facts'), 'in-review');
        $stale = $this->pullRequest($card, headSha: 'head2');
        $stale->approvalId = 'review-1';
        $stale->coveredSha = 'head1';
        $stale->uncoveredSha = 'head2';
        $covered = $this->pullRequest($card, headSha: 'head3');
        $covered->approvalId = 'review-2';
        $covered->coveredSha = 'head3';
        $this->em()->flush();

        $provider = $this->service(StaleApprovalFactProvider::class);
        $facts = $provider->build($card->snapshot());

        self::assertEquals(new StaleApprovalFacts([(string) $stale->id => 'head2']), $facts);
        self::assertSame([(string) $stale->id => 'head2'], $provider->fingerprint($facts));
    }

    private function fixRun(Card $card, WorkerRunState $state, \DateTimeImmutable $receivedAt = new \DateTimeImmutable(), ?\DateTimeImmutable $endedAt = null): WorkerRun
    {
        $run = new WorkerRun(
            project: $card->project,
            bridgeId: Uuid::v7(),
            subjectType: WorkSubject::CARD,
            subjectId: $card->id ?? throw new \LogicException('A flushed card has an id.'),
            cardNumber: $card->number,
            workKind: 'fix',
            state: $state,
            endedAt: $endedAt,
            receivedAt: $receivedAt,
            role: 'fix',
        );
        $this->em()->persist($run);
        $this->em()->flush();

        return $run;
    }
}
