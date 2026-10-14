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

    /** @param array<string, mixed>|null $fields */
    private function runWith(?array $fields): WorkerRun
    {
        $project = new Project(new User(fullName: 'Riley Chen', email: 'riley@example.com', password: 'x'), 'suggestions');
        $run = new WorkerRun($project, Uuid::v4(), 'card', Uuid::v4(), 7, 'implement', WorkerRunState::Blocked);
        $run->resultFields = $fields;

        return $run;
    }
}
