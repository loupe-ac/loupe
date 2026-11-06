<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Contract;

use App\Module\Workflow\Contract\SetupFailure;
use PHPUnit\Framework\TestCase;

final class SetupFailureTest extends TestCase
{
    public function test_it_reads_the_exit_code_the_command_and_the_last_line(): void
    {
        $failure = SetupFailure::fromOutput("the before command exited with code 3\ncommand: 'bin/before.sh' '7'\nnpm ci failed\n\nERR! boom\n");

        self::assertNotNull($failure);
        self::assertSame([3, "'bin/before.sh' '7'", 'ERR! boom'], [$failure->exitCode, $failure->command, $failure->errorLine]);
    }

    public function test_an_output_from_an_older_bridge_names_no_command(): void
    {
        $failure = SetupFailure::fromOutput("the before command exited with code 1\nnpm ci failed");

        self::assertNotNull($failure);
        self::assertSame([1, null, 'npm ci failed'], [$failure->exitCode, $failure->command, $failure->errorLine]);
    }

    public function test_a_reason_without_a_code_has_no_exit_code_and_a_silent_command_has_an_empty_line(): void
    {
        $failure = SetupFailure::fromOutput("the before command ran past its timeout, so the bridge killed it\ncommand: 'x'");

        self::assertNotNull($failure);
        self::assertSame([null, "'x'", ''], [$failure->exitCode, $failure->command, $failure->errorLine]);
    }

    public function test_it_reads_the_hash_of_the_whole_command_and_keeps_it_out_of_the_error_line(): void
    {
        $hash = str_repeat('ab', 32);
        $failure = SetupFailure::fromOutput("the before command exited with code 1\ncommand: 'x'\ncommand-sha256: {$hash}");

        self::assertNotNull($failure);
        self::assertSame(["'x'", $hash, ''], [$failure->command, $failure->commandHash, $failure->errorLine]);
    }

    public function test_two_commands_cut_to_the_same_line_differ_by_their_hash(): void
    {
        $one = SetupFailure::fromOutput("the before command exited with code 1\ncommand: 'a'\ncommand-sha256: ".str_repeat('1', 64)."\nboom");
        $two = SetupFailure::fromOutput("the before command exited with code 1\ncommand: 'a'\ncommand-sha256: ".str_repeat('2', 64)."\nboom");

        self::assertNotNull($one);
        self::assertNotNull($two);
        self::assertNotSame($one->fingerprint(), $two->fingerprint());
    }

    public function test_an_output_of_another_failure_is_not_a_setup_failure(): void
    {
        self::assertNull(SetupFailure::fromOutput("the command exited with code 2\nboom"));
        self::assertNull(SetupFailure::fromOutput(''));
    }

    public function test_the_fingerprint_tells_two_failures_apart_by_each_of_its_three_parts(): void
    {
        $base = SetupFailure::fromOutput("the before command exited with code 1\ncommand: 'a'\nboom");
        $same = SetupFailure::fromOutput("the before command exited with code 1\ncommand: 'a'\nboom");
        self::assertNotNull($base);
        self::assertNotNull($same);
        self::assertSame($base->fingerprint(), $same->fingerprint());
        foreach (["exited with code 2\ncommand: 'a'\nboom", "exited with code 1\ncommand: 'b'\nboom", "exited with code 1\ncommand: 'a'\nboom 2", "exited with code 1\nboom"] as $other) {
            self::assertNotSame($base->fingerprint(), SetupFailure::fromOutput('the before command '.$other)?->fingerprint(), $other);
        }
    }
}
