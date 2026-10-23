<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Contract;

use App\Module\Workflow\Contract\ForgeRequest;
use App\Module\Workflow\Contract\PullRequestState;
use App\Module\Workflow\Contract\StackParent;
use App\Tests\Module\Workflow\Fact\FactsMother;
use PHPUnit\Framework\TestCase;

final class PullRequestFactsTest extends TestCase
{
    public function test_a_request_counts_until_it_expires(): void
    {
        $facts = FactsMother::pullRequest(forgeRequests: [
            new ForgeRequest(new \DateTimeImmutable('11:50'), new \DateTimeImmutable('12:00'), merge: true),
            new ForgeRequest(new \DateTimeImmutable('11:55'), new \DateTimeImmutable('12:05')),
        ]);

        self::assertTrue($facts->mergeInFlight(new \DateTimeImmutable('11:59')));
        self::assertEquals(new \DateTimeImmutable('11:50'), $facts->forgeRequestedAt(new \DateTimeImmutable('11:59')));
        self::assertEquals(new \DateTimeImmutable('12:00'), $facts->firstForgeRequestExpiresAt(new \DateTimeImmutable('11:59')));

        self::assertFalse($facts->mergeInFlight(new \DateTimeImmutable('12:00')));
        self::assertEquals(new \DateTimeImmutable('11:55'), $facts->forgeRequestedAt(new \DateTimeImmutable('12:00')));
        self::assertEquals(new \DateTimeImmutable('12:05'), $facts->firstForgeRequestExpiresAt(new \DateTimeImmutable('12:00')));

        self::assertNull($facts->forgeRequestedAt(new \DateTimeImmutable('12:05')));
        self::assertNull($facts->firstForgeRequestExpiresAt(new \DateTimeImmutable('12:05')));
    }

    public function test_a_ready_pull_request_with_nothing_pending_needs_each_part(): void
    {
        $now = new \DateTimeImmutable('12:00');
        $ready = static fn (...$overrides) => FactsMother::pullRequest(...[
            'readyToMerge' => true,
            'readySince' => new \DateTimeImmutable('11:00'),
            ...$overrides,
        ]);

        self::assertTrue($ready()->readyWithNothingPending($now));
        self::assertFalse($ready(readyToMerge: false)->readyWithNothingPending($now));
        self::assertFalse($ready(readySince: null)->readyWithNothingPending($now));
        self::assertFalse($ready(waitsForApproval: true)->readyWithNothingPending($now));
        self::assertFalse($ready(state: PullRequestState::Merged)->readyWithNothingPending($now));
        self::assertFalse($ready(forgeRequests: [new ForgeRequest(new \DateTimeImmutable('11:59'), new \DateTimeImmutable('12:09'))])->readyWithNothingPending($now));
        self::assertTrue($ready(forgeRequests: [new ForgeRequest(new \DateTimeImmutable('11:40'), new \DateTimeImmutable('11:50'))])->readyWithNothingPending($now));
    }

    public function test_the_clock_fields_stay_out_of_the_fingerprint(): void
    {
        $plain = FactsMother::pullRequest();
        $timed = FactsMother::pullRequest(
            number: 12,
            stackParent: new StackParent('base', 3, 4),
            waitsForApproval: true,
            readyToMerge: true,
            readySince: new \DateTimeImmutable('11:00'),
            checksFailedSince: new \DateTimeImmutable('11:00'),
            checksPendingSince: new \DateTimeImmutable('11:00'),
            conflictingSince: new \DateTimeImmutable('11:00'),
            forgeRequests: [new ForgeRequest(new \DateTimeImmutable('11:00'), new \DateTimeImmutable('11:10'))],
        );

        self::assertSame($plain->fingerprint(), $timed->fingerprint());
    }
}
