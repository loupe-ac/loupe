<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Entity;

use App\Module\Inbox\Entity\InboxActionKind;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InboxActionKindTest extends TestCase
{
    /** @return iterable<string, array{InboxActionKind, bool}> */
    public static function kinds(): iterable
    {
        foreach (InboxActionKind::cases() as $kind) {
            yield $kind->value => [$kind, \in_array($kind, [InboxActionKind::ResumeRun, InboxActionKind::SendAgent, InboxActionKind::StartSession], true)];
        }
    }

    #[DataProvider('kinds')]
    public function test_only_the_actions_that_always_start_an_agent_send_it_the_note(InboxActionKind $kind, bool $expected): void
    {
        self::assertSame($expected, $kind->sendsAgent());
    }
}
