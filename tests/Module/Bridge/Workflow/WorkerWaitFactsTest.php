<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Workflow;

use App\Module\Bridge\Workflow\WorkerWaitFacts;
use App\Module\Workflow\Contract\OpenWait;
use App\Module\Workflow\Contract\WaitedCondition;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class WorkerWaitFactsTest extends TestCase
{
    public function test_the_checks_of_a_pull_request_read_as_events_that_have_not_happened(): void
    {
        $pr = Uuid::v7();
        $facts = new WorkerWaitFacts(new OpenWait(Uuid::v7(), new \DateTimeImmutable('2026-11-23 10:00:00'), new \DateTimeImmutable('2026-11-23 11:00:00'), [
            new WaitedCondition('pr.checks_passed', [], $pr, 'pr:acme/app#12'),
            new WaitedCondition('pr.checks_failed', [], $pr, 'pr:acme/app#12'),
        ]));

        self::assertSame('the checks of pr:acme/app#12 to pass or the checks of pr:acme/app#12 to fail', $facts->conditionsText());
    }

    public function test_another_condition_reads_as_its_name(): void
    {
        $facts = new WorkerWaitFacts(new OpenWait(Uuid::v7(), new \DateTimeImmutable('2026-11-23 10:00:00'), new \DateTimeImmutable('2026-11-23 11:00:00'), [
            new WaitedCondition('card.in_review'),
        ]));

        self::assertSame('in review', $facts->conditionsText());
    }
}
