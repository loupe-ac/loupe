<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Service;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Inbox\Command\AskInboxHandler;
use App\Module\Inbox\Command\AskInboxRunCommand;
use App\Module\Inbox\Service\SuggestedCommandCheck;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class SuggestedCommandCheckTest extends TestCase
{
    #[TestWith(['main'])]
    #[TestWith(['feature/card-883-run-command'])]
    #[TestWith(['release-1.2'])]
    #[TestWith(['a@b'])]
    #[TestWith(['a.b/c.d'])]
    public function test_a_plain_branch_name_passes(string $branch): void
    {
        self::assertTrue(SuggestedCommandCheck::isBranchName($branch));
    }

    #[TestWith([''])]
    #[TestWith(['my branch'])]
    #[TestWith(['a..b'])]
    #[TestWith(['-rf'])]
    #[TestWith(["a\tb"])]
    #[TestWith(["a\x7fb"])]
    #[TestWith(['a~1'])]
    #[TestWith(['a^'])]
    #[TestWith(['a:b'])]
    #[TestWith(['a?'])]
    #[TestWith(['a*'])]
    #[TestWith(['a[0]'])]
    #[TestWith(['a\\b'])]
    #[TestWith(['dir/'])]
    #[TestWith(['topic.lock'])]
    #[TestWith(['topic.'])]
    #[TestWith(['foo//bar'])]
    #[TestWith(['foo@{bar'])]
    #[TestWith(['foo/.bar'])]
    #[TestWith(['.foo'])]
    #[TestWith(['foo.lock/bar'])]
    #[TestWith(['/foo'])]
    #[TestWith(['@'])]
    public function test_a_wrong_branch_name_fails(string $branch): void
    {
        self::assertFalse(SuggestedCommandCheck::isBranchName($branch));
    }

    public function test_a_branch_name_of_the_limit_passes_and_one_more_fails(): void
    {
        self::assertTrue(SuggestedCommandCheck::isBranchName(str_repeat('a', 200)));
        self::assertFalse(SuggestedCommandCheck::isBranchName(str_repeat('a', 201)));
    }

    public function test_a_wrong_branch_is_refused_first(): void
    {
        self::assertSame(AskInboxHandler::SUGGESTION_BRANCH_INVALID, SuggestedCommandCheck::error($this->command(branch: 'a b'), null, null));
    }

    public function test_a_command_with_no_pattern_source_stays_unchecked(): void
    {
        self::assertNull(SuggestedCommandCheck::error($this->command(['anything' => 'goes']), null, null));
        self::assertNull(SuggestedCommandCheck::error($this->command(['anything' => 'goes']), null, $this->bridge([])));
        self::assertNull(SuggestedCommandCheck::error($this->command(['anything' => 'goes']), null, $this->bridge([$this->row('other', 'x', '^x$')])));
    }

    public function test_the_pattern_of_the_bridge_entry_is_checked_against_the_whole_value(): void
    {
        $bridge = $this->bridge([$this->row('test', 'filter', '[a-z]+')]);

        self::assertNull(SuggestedCommandCheck::error($this->command(['filter' => 'abc']), null, $bridge));
        self::assertSame(AskInboxHandler::SUGGESTION_PARAM_INVALID, SuggestedCommandCheck::error($this->command(['filter' => 'abc1']), null, $bridge));
        self::assertSame(AskInboxHandler::SUGGESTION_PARAM_INVALID, SuggestedCommandCheck::error($this->command(['filter' => "abc\n"]), null, $bridge));
        self::assertSame(AskInboxHandler::SUGGESTION_PARAM_INVALID, SuggestedCommandCheck::error($this->command(['filter' => '1abc']), null, $bridge));
    }

    public function test_an_alternation_cannot_escape_the_whole_value_match(): void
    {
        $bridge = $this->bridge([$this->row('test', 'filter', 'a|b')]);

        self::assertNull(SuggestedCommandCheck::error($this->command(['filter' => 'b']), null, $bridge));
        self::assertSame(AskInboxHandler::SUGGESTION_PARAM_INVALID, SuggestedCommandCheck::error($this->command(['filter' => 'bx']), null, $bridge));
    }

    public function test_a_param_the_entry_does_not_declare_is_refused(): void
    {
        $bridge = $this->bridge([$this->row('test', 'filter', '.*')]);

        self::assertSame(AskInboxHandler::SUGGESTION_PARAM_INVALID, SuggestedCommandCheck::error($this->command(['filter' => 'a', 'extra' => 'b']), null, $bridge));
    }

    public function test_a_declared_param_with_no_value_is_refused(): void
    {
        $bridge = $this->bridge([$this->row('test', 'filter', '.*')]);

        self::assertSame(AskInboxHandler::SUGGESTION_PARAM_INVALID, SuggestedCommandCheck::error($this->command(), null, $bridge));
    }

    public function test_a_pattern_that_pcre_cannot_compile_leaves_the_value_unchecked(): void
    {
        $bridge = $this->bridge([$this->row('test', 'filter', '(?<name>a)(?P<name>b')]);

        self::assertNull(SuggestedCommandCheck::error($this->command(['filter' => 'whatever']), null, $bridge));
    }

    public function test_a_pattern_that_holds_the_delimiter_leaves_the_value_unchecked(): void
    {
        $bridge = $this->bridge([$this->row('test', 'filter', "a\x01b")]);

        self::assertNull(SuggestedCommandCheck::error($this->command(['filter' => 'whatever']), null, $bridge));
    }

    public function test_the_pattern_of_the_proposed_entry_is_used_when_the_bridge_lacks_the_entry(): void
    {
        $proposed = "action: command\nrun: [make, test]\nparams:\n  filter:\n    pattern: '[a-z]+'\n";

        self::assertNull(SuggestedCommandCheck::error($this->command(['filter' => 'abc']), $proposed, $this->bridge([])));
        self::assertNull(SuggestedCommandCheck::error($this->command(['filter' => 'abc']), $proposed, null));
        self::assertSame(AskInboxHandler::SUGGESTION_PARAM_INVALID, SuggestedCommandCheck::error($this->command(['filter' => 'ABC']), $proposed, null));
        self::assertSame(AskInboxHandler::SUGGESTION_PARAM_INVALID, SuggestedCommandCheck::error($this->command(['filter' => 'abc', 'extra' => 'x']), $proposed, null));
    }

    public function test_the_entry_of_the_bridge_wins_over_the_proposed_entry(): void
    {
        $bridge = $this->bridge([$this->row('test', 'filter', '[0-9]+')]);
        $proposed = "action: command\nparams:\n  filter:\n    pattern: '[a-z]+'\n";

        self::assertNull(SuggestedCommandCheck::error($this->command(['filter' => '42']), $proposed, $bridge));
        self::assertSame(AskInboxHandler::SUGGESTION_PARAM_INVALID, SuggestedCommandCheck::error($this->command(['filter' => 'abc']), $proposed, $bridge));
    }

    public function test_a_proposed_entry_with_no_params_declares_none(): void
    {
        $proposed = "action: command\nrun: [make]\n";

        self::assertNull(SuggestedCommandCheck::error($this->command(), $proposed, null));
        self::assertSame(AskInboxHandler::SUGGESTION_PARAM_INVALID, SuggestedCommandCheck::error($this->command(['x' => 'y']), $proposed, null));
    }

    #[DataProvider('wrongProposedEntries')]
    public function test_a_wrong_proposed_entry_is_refused_even_when_the_bridge_has_the_entry(string $proposed): void
    {
        $bridge = $this->bridge([$this->row('test', 'filter', '.*')]);

        self::assertSame(AskInboxHandler::SUGGESTION_ENTRY_INVALID, SuggestedCommandCheck::error($this->command(['filter' => 'a']), $proposed, $bridge));
    }

    /** @return iterable<string, array{string}> */
    public static function wrongProposedEntries(): iterable
    {
        yield 'invalid YAML' => ["action: command\n  bad: [unclosed"];
        yield 'a scalar' => ['just text'];
        yield 'a list' => ["- action\n- command"];
        yield 'an empty text' => [''];
        yield 'another action' => ["action: interactive\nprompt: x"];
        yield 'no action' => ["run: [make]\n"];
        yield 'params that are no mapping' => ["action: command\nparams: filter\n"];
        yield 'a param with no pattern' => ["action: command\nparams:\n  out: {}\n"];
        yield 'a param that is no mapping' => ["action: command\nparams:\n  out: x\n"];
        yield 'a pattern that is no string' => ["action: command\nparams:\n  out:\n    pattern: [a]\n"];
        yield 'an empty pattern' => ["action: command\nparams:\n  out:\n    pattern: ''\n"];
        yield 'a text over the limit' => ["action: command\nrun: [".str_repeat('a, ', 1400).']'];
    }

    /**
     * @param array<string, string> $params
     */
    private function command(array $params = [], string $branch = 'main'): AskInboxRunCommand
    {
        return new AskInboxRunCommand('test', $branch, $params);
    }

    /** @param list<array{name: string, description: ?string, params: list<array{name: string, pattern: string, description: ?string}>, run: list<string>, timeout: string}> $entries */
    private function bridge(array $entries): Bridge
    {
        $bridge = new Bridge(new User(fullName: 'Riley Chen', email: 'riley@example.com', password: 'x'), Uuid::v4(), [], '1.0.0', new \DateTimeImmutable());
        $bridge->commandEntries = $entries;

        return $bridge;
    }

    /** @return array{name: string, description: ?string, params: list<array{name: string, pattern: string, description: ?string}>, run: list<string>, timeout: string} */
    private function row(string $name, string $param, string $pattern): array
    {
        return ['name' => $name, 'description' => null, 'params' => [['name' => $param, 'pattern' => $pattern, 'description' => null]], 'run' => ['make', 'test'], 'timeout' => '5m'];
    }
}
