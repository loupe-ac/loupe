<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Service;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Service\RunSuggestions;
use App\Module\Inbox\View\SuggestedAction;
use App\Module\Project\Entity\Project;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class RunSuggestionsTest extends TestCase
{
    public function test_a_title_is_one_line_of_plain_text(): void
    {
        self::assertSame('Add the API key to the vault', RunSuggestions::title($this->runWith(['title' => "  Add the API key\n  to   the vault "])));
    }

    public function test_a_long_title_is_cut_to_the_item_limit(): void
    {
        $title = RunSuggestions::title($this->runWith(['title' => str_repeat('a', 400)]));

        self::assertNotNull($title);
        self::assertSame(InboxItem::MAX_TITLE_LENGTH, mb_strlen($title));
    }

    /** @param array<string, mixed>|null $fields */
    #[TestWith([null])]
    #[TestWith([[]])]
    #[TestWith([['title' => '']])]
    #[TestWith([['title' => " \n "]])]
    #[TestWith([['title' => 12]])]
    public function test_a_missing_or_empty_title_is_null(?array $fields): void
    {
        self::assertNull(RunSuggestions::title($this->runWith($fields)));
    }

    public function test_it_keeps_the_known_actions_in_order_and_drops_the_rest(): void
    {
        $actions = RunSuggestions::actions($this->runWith(['suggestedActions' => [
            ['action' => 'resume-run', 'reason' => 'The key is now in the vault.', 'recommended' => true],
            ['action' => 'delete-everything', 'reason' => 'Unknown key.'],
            ['action' => 'resume-run', 'reason' => 'A repeat.'],
            'not an entry',
            ['action' => 'open', 'reason' => 'See the card.', 'recommended' => null],
            ['action' => 'dismiss'],
            ['action' => 'review', 'reason' => "Two\nlines"],
        ]]));

        self::assertEquals([
            new SuggestedAction('resume-run', 'The key is now in the vault.', true),
            new SuggestedAction('open', 'See the card.', false),
            new SuggestedAction('review', 'Two lines', false),
        ], $actions);
    }

    public function test_only_the_first_recommended_suggestion_stays_recommended(): void
    {
        $actions = RunSuggestions::actions($this->runWith(['suggestedActions' => [
            ['action' => 'open', 'reason' => 'See the card.'],
            ['action' => 'resume-run', 'reason' => 'The key is in the vault.', 'recommended' => true],
            ['action' => 'retry-card', 'reason' => 'Try again.', 'recommended' => true],
        ]]));

        self::assertSame([false, true, false], array_map(static fn (SuggestedAction $action): bool => $action->recommended, $actions));
    }

    public function test_it_keeps_three_suggestions_at_most(): void
    {
        $actions = RunSuggestions::actions($this->runWith(['suggestedActions' => array_map(
            static fn (string $key): array => ['action' => $key, 'reason' => 'Why.'],
            RunSuggestions::ACTIONS,
        )]));

        self::assertCount(RunSuggestions::MAX_SUGGESTIONS, $actions);
    }

    /** @param array<string, mixed>|null $fields */
    #[TestWith([null])]
    #[TestWith([['suggestedActions' => 'resume-run']])]
    #[TestWith([['suggestedActions' => []]])]
    public function test_no_list_gives_no_suggestions(?array $fields): void
    {
        self::assertSame([], RunSuggestions::actions($this->runWith($fields)));
    }

    public function test_a_run_command_suggestion_carries_its_command_and_proposed_entry(): void
    {
        $actions = RunSuggestions::actions($this->runWith(['suggestedActions' => [
            ['action' => 'run-command', 'reason' => 'Run the tests.', 'command' => ['entry' => ' test ', 'branch' => 'feature/x', 'params' => ['filter' => 'abc']], 'proposedEntry' => "action: command\n"],
        ]]));

        self::assertEquals([new SuggestedAction('run-command', 'Run the tests.', false, ['entry' => 'test', 'branch' => 'feature/x', 'params' => ['filter' => 'abc']], "action: command\n")], $actions);
    }

    public function test_a_run_command_branch_defaults_to_main_and_params_to_none(): void
    {
        $actions = RunSuggestions::actions($this->runWith(['suggestedActions' => [
            ['action' => 'run-command', 'reason' => 'Run the tests.', 'command' => ['entry' => 'test', 'branch' => null, 'params' => null], 'proposedEntry' => null],
        ]]));

        self::assertEquals([new SuggestedAction('run-command', 'Run the tests.', false, ['entry' => 'test', 'branch' => 'main', 'params' => []])], $actions);
    }

    public function test_the_params_may_come_as_a_list_of_names_and_values(): void
    {
        $actions = RunSuggestions::actions($this->runWith(['suggestedActions' => [
            ['action' => 'run-command', 'reason' => 'Run.', 'command' => ['entry' => 'test', 'params' => [['name' => 'filter', 'value' => 'abc'], ['name' => 'depth', 'value' => '2']]]],
        ]]));

        self::assertSame(['filter' => 'abc', 'depth' => '2'], $actions[0]->command['params'] ?? null);
    }

    /** @param array<string, mixed> $suggestion */
    #[DataProvider('malformedRunCommands')]
    public function test_a_malformed_run_command_suggestion_is_dropped(array $suggestion): void
    {
        $actions = RunSuggestions::actions($this->runWith(['suggestedActions' => [
            ['action' => 'run-command', 'reason' => 'Run.', ...$suggestion],
            ['action' => 'open', 'reason' => 'See the card.'],
        ]]));

        self::assertSame(['open'], array_map(static fn (SuggestedAction $action): string => $action->action, $actions));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function malformedRunCommands(): iterable
    {
        yield 'no command' => [[]];
        yield 'a command that is no object' => [['command' => 'test']];
        yield 'no entry' => [['command' => ['branch' => 'main']]];
        yield 'a blank entry' => [['command' => ['entry' => '  ']]];
        yield 'an entry that is no string' => [['command' => ['entry' => 5]]];
        yield 'a branch that is no string' => [['command' => ['entry' => 'test', 'branch' => 5]]];
        yield 'params that are no map' => [['command' => ['entry' => 'test', 'params' => 'x']]];
        yield 'a param value that is no string' => [['command' => ['entry' => 'test', 'params' => ['a' => 1]]]];
        yield 'a list param with no value' => [['command' => ['entry' => 'test', 'params' => [['name' => 'a']]]]];
        yield 'a repeated list param' => [['command' => ['entry' => 'test', 'params' => [['name' => 'a', 'value' => '1'], ['name' => 'a', 'value' => '2']]]]];
        yield 'a proposed entry that is no string' => [['command' => ['entry' => 'test'], 'proposedEntry' => ['x']]];
        yield 'a proposed entry over the limit' => [['command' => ['entry' => 'test'], 'proposedEntry' => str_repeat('a', 4001)]];
    }

    public function test_another_action_ignores_a_command(): void
    {
        $actions = RunSuggestions::actions($this->runWith(['suggestedActions' => [
            ['action' => 'open', 'reason' => 'See the card.', 'command' => ['entry' => 'test'], 'proposedEntry' => 'x'],
        ]]));

        self::assertEquals([new SuggestedAction('open', 'See the card.')], $actions);
    }

    /** @param array<string, mixed>|null $fields */
    private function runWith(?array $fields): WorkerRun
    {
        $project = new Project(new User(fullName: 'Riley Chen', email: 'riley@example.com', password: 'x'), 'suggestions');
        $run = new WorkerRun($project, Uuid::v4(), 'card', Uuid::v4(), 7, 'implement', WorkerRunState::Blocked);
        $run->resultFields = $fields;

        return $run;
    }
}
