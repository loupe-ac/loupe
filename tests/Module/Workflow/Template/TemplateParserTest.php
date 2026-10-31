<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Template;

use App\Module\AgentReview\Workflow\Condition\DesignReviewFinished;
use App\Module\Board\Workflow\ApproveDocument;
use App\Module\Board\Workflow\Condition\CardChildrenFinished;
use App\Module\Board\Workflow\Condition\CardDocument;
use App\Module\Board\Workflow\Condition\CardDocumentApproved;
use App\Module\Board\Workflow\Condition\CardHasOpenBlocker;
use App\Module\Board\Workflow\Condition\CardHasType;
use App\Module\Board\Workflow\Condition\CardPaused;
use App\Module\Board\Workflow\Condition\CardValue;
use App\Module\Board\Workflow\Condition\PullRequestApprovalCoversHead;
use App\Module\Board\Workflow\Condition\PullRequestOpen;
use App\Module\Board\Workflow\Detach;
use App\Module\Board\Workflow\ForgeWrite;
use App\Module\Board\Workflow\LinkDocument;
use App\Module\Board\Workflow\MoveCard;
use App\Module\Board\Workflow\PublishDocuments;
use App\Module\Board\Workflow\RequestChanges;
use App\Module\Board\Workflow\RequestWork;
use App\Module\Board\Workflow\StopWork;
use App\Module\Bridge\Metric\BuiltInMetricKeys;
use App\Module\Bridge\Workflow\Condition\AccountUsage;
use App\Module\Bridge\Workflow\Condition\RunWorkActive;
use App\Module\Inbox\Workflow\Condition\CardHasOpenQuestion;
use App\Module\Workflow\Action\Actions;
use App\Module\Workflow\Action\Ask;
use App\Module\Workflow\Action\EvaluateChildren;
use App\Module\Workflow\Action\MissingAction;
use App\Module\Workflow\Action\PauseCard;
use App\Module\Workflow\Action\ReleasePause;
use App\Module\Workflow\Condition\CardInSlot;
use App\Module\Workflow\Condition\Conditions;
use App\Module\Workflow\Contract\Action;
use App\Module\Workflow\Contract\CardValueType;
use App\Module\Workflow\Contract\LabelTone;
use App\Module\Workflow\Contract\MarkerKind;
use App\Module\Workflow\Contract\MarkerLink;
use App\Module\Workflow\Contract\RepositorySuggestion;
use App\Module\Workflow\Contract\RepositorySuggestionKey;
use App\Module\Workflow\Contract\SuggestionMark;
use App\Module\Workflow\Contract\TemplateValueKey;
use App\Module\Workflow\Contract\Unreadable;
use App\Module\Workflow\Contract\UnreadableKind;
use App\Module\Workflow\Expression\AllOf;
use App\Module\Workflow\Expression\AnyOf;
use App\Module\Workflow\Expression\ConditionLeaf;
use App\Module\Workflow\Expression\MissingActionLeaf;
use App\Module\Workflow\Expression\MissingConditionLeaf;
use App\Module\Workflow\Expression\NamedFact;
use App\Module\Workflow\Expression\Not;
use App\Module\Workflow\Template\AppRequest;
use App\Module\Workflow\Template\AskOption;
use App\Module\Workflow\Template\InvalidTemplate;
use App\Module\Workflow\Template\ManualMoveActor;
use App\Module\Workflow\Template\RuleOrigin;
use App\Module\Workflow\Template\TemplateMetricParser;
use App\Module\Workflow\Template\TemplateParser;
use App\Module\Workflow\Template\TemplatePrompts;
use App\Tests\Module\Workflow\Action\ExpressionPluggedAction;
use App\Tests\Module\Workflow\Action\PluggedAction;
use App\Tests\Module\Workflow\Fact\FactsMother;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class TemplateParserTest extends TestCase
{
    private const string TONES = 'neutral, lime, purple, green, amber, red, teal, sky, blue, indigo, pink, orange';

    private TemplateParser $parser;

    private Actions $actions;

    #[\Override]
    protected function setUp(): void
    {
        $this->actions = new Actions(array_map(
            static fn (string $class): Action => new \ReflectionClass($class)->newInstanceWithoutConstructor(),
            [MoveCard::class, RequestWork::class, ForgeWrite::class, PauseCard::class, ReleasePause::class, EvaluateChildren::class, Ask::class, LinkDocument::class, Detach::class, PublishDocuments::class, ApproveDocument::class, RequestChanges::class, StopWork::class, MissingAction::class],
        ));
        $this->parser = new TemplateParser(new Conditions([
            new AccountUsage(),
            new CardChildrenFinished(),
            new CardDocument(),
            new CardDocumentApproved(),
            new CardHasOpenBlocker(),
            new CardHasOpenQuestion(),
            new CardHasType(),
            new CardInSlot(),
            new CardPaused(),
            new CardValue(),
            new DesignReviewFinished(),
            new PullRequestApprovalCoversHead(),
            new PullRequestOpen(),
            new RunWorkActive(),
        ]), $this->actions, new TemplatePrompts(self::PROMPTS_FIXTURE), self::metricParser());
    }

    private static function metricParser(): TemplateMetricParser
    {
        return new TemplateMetricParser(new BuiltInMetricKeys());
    }

    /** @return array<string, mixed> */
    private static function valid(): array
    {
        return [
            'key' => 'test',
            'version' => 1,
            'defaultType' => 'feature',
            'types' => [
                ['key' => 'feature', 'label' => 'board.card.type.feature', 'tone' => 'lime'],
                ['key' => 'bug', 'label' => 'board.card.type.bug', 'tone' => 'amber'],
                ['key' => 'epic', 'label' => 'board.card.type.epic', 'tone' => 'blue', 'capabilities' => ['children', 'lane']],
            ],
            'slots' => [
                ['key' => 'build', 'label' => 'workflow.slot.build'],
                ['key' => 'review', 'label' => 'workflow.slot.review'],
            ],
            'manualMoves' => [
                ['from' => '@backlog', 'to' => 'build'],
                ['from' => '*', 'to' => '*'],
                ['from' => '@backlog', 'to' => 'review', 'by' => 'parent-run'],
            ],
            'backoffMinutes' => [10, 60],
            'rules' => [
                [
                    'id' => 'start',
                    'slot' => 'build',
                    'when' => ['all' => [
                        ['card.type' => ['types' => ['feature']]],
                        ['not' => ['card.blocker.open' => []]],
                    ]],
                    'then' => ['request' => ['kind' => 'implement', 'capability' => 'interactive', 'limit' => 3]],
                ],
                [
                    'id' => 'to-review',
                    'slot' => 'build',
                    'when' => ['any' => [
                        ['pr.open' => []],
                        ['card.in_slot' => ['slot' => 'review']],
                    ]],
                    'then' => ['move' => ['to' => 'review']],
                ],
                [
                    'id' => 'merge',
                    'slot' => 'review',
                    'when' => ['pr.approval_covers_head' => ['min' => 1]],
                    'then' => ['forge-write' => ['write' => 'merge', 'methods' => ['squash', 'merge', 'rebase'], 'fallback' => 'merge']],
                ],
                [
                    'id' => 'wait',
                    'when' => ['card.run.work_active' => []],
                    'then' => ['pause' => ['reason' => 'busy', 'until' => ['not' => ['card.run.work_active' => []]]]],
                ],
                [
                    'id' => 'done',
                    'slot' => '@terminal',
                    'when' => ['card.document.approved' => ['tag' => 'design']],
                    'then' => ['release' => ['reason' => 'busy']],
                ],
            ],
        ];
    }

    public function test_a_slot_names_the_work_of_its_stage(): void
    {
        $source = self::valid();
        $source['slots'][0]['stageWork'] = 'implement';

        $template = $this->parser->parse($source);

        self::assertSame('implement', $template->slot('build')?->stageWork);
        self::assertNull($template->slot('review')?->stageWork);
    }

    public function test_a_slot_names_the_tone_of_its_column(): void
    {
        $source = self::valid();
        $source['slots'][0]['tone'] = 'lime';

        $template = $this->parser->parse($source);

        self::assertSame(LabelTone::Lime, $template->slot('build')?->tone);
        self::assertNull($template->slot('review')?->tone);
    }

    public function test_the_app_rules_name_the_slot_of_the_proposal_cards(): void
    {
        self::assertSame('next', $this->parser->parseAppProposalSlot(['rules' => [], 'proposalSlot' => 'next']));
        self::assertNull($this->parser->parseAppProposalSlot(['rules' => []]));
        self::assertSame([], $this->parser->parseAppRules(['rules' => [], 'proposalSlot' => 'next']));
    }

    public function test_it_parses_a_valid_template(): void
    {
        $template = $this->parser->parse(self::valid());

        self::assertSame('test', $template->key);
        self::assertSame(1, $template->version);
        self::assertSame(['build', 'review'], array_map(static fn ($slot) => $slot->key, $template->slots));
        self::assertSame('workflow.slot.review', $template->slot('review')?->label);
        self::assertNull($template->slot('nowhere'));
        self::assertSame([10, 60], $template->backoffMinutes);
        self::assertSame('@backlog', $template->manualMoves[0]->from);
        self::assertSame('build', $template->manualMoves[0]->to);
        self::assertNull($template->manualMoves[0]->by);
        self::assertSame(ManualMoveActor::ParentRun, $template->manualMoves[2]->by);

        $ids = static fn (array $rules): array => array_map(static fn ($rule) => $rule->id, $rules);
        self::assertSame(['start', 'to-review', 'wait'], $ids($template->rulesFor('build')));
        self::assertSame(['wait', 'done'], $ids($template->rulesFor('@terminal')));
        self::assertSame(['wait'], $ids($template->rulesFor(null)));

        $start = $template->rulesFor('build')[0];
        self::assertSame('request', $start->lead->key);
        self::assertSame(['kind' => 'implement', 'capability' => 'interactive', 'limit' => 3], $start->lead->params);
        self::assertNull($start->lead->until);
        self::assertNull($start->lead->refill);

        $wait = $template->rulesFor(null)[0];
        self::assertSame('pause', $wait->lead->key);
        self::assertSame(['reason' => 'busy'], $wait->lead->params);
        self::assertInstanceOf(Not::class, $wait->lead->until);
    }

    public function test_it_reads_the_card_types_and_the_default_type(): void
    {
        $template = $this->parser->parse(self::valid());

        self::assertSame(['feature', 'bug', 'epic'], array_map(static fn ($type) => $type->key, $template->types));
        self::assertSame('feature', $template->defaultType);
        $epic = $template->type('epic') ?? throw new \LogicException('The template declares an epic.');
        self::assertSame('board.card.type.epic', $epic->label);
        self::assertSame(LabelTone::Blue, $epic->tone);
        self::assertTrue($epic->children);
        self::assertTrue($epic->lane);
        $bug = $template->type('bug') ?? throw new \LogicException('The template declares a bug.');
        self::assertSame(LabelTone::Amber, $bug->tone);
        self::assertFalse($bug->children);
        self::assertFalse($bug->lane);
        self::assertNull($template->type('chore'));
    }

    public function test_a_type_with_children_may_have_a_rule_that_reads_them(): void
    {
        $template = self::valid();
        $template['rules'][0]['when'] = ['all' => [['card.type' => ['types' => ['epic']]], ['card.children.finished' => []]]];
        $template['rules'][4]['when'] = ['card.type' => ['types' => ['epic']]];
        $template['rules'][4]['then'] = ['evaluate' => ['cards' => 'children']];

        self::assertCount(5, $this->parser->parse($template)->rules);
    }

    public function test_a_type_without_children_under_a_not_or_in_another_branch_does_not_read_them(): void
    {
        $template = self::valid();
        $template['rules'][0]['when'] = ['all' => [
            ['not' => ['card.type' => ['types' => ['bug']]]],
            ['any' => [['card.type' => ['types' => ['bug']]], ['card.children.finished' => []]]],
        ]];
        $template['rules'][4]['when'] = ['all' => [['not' => ['card.type' => ['types' => ['bug']]]]]];
        $template['rules'][4]['then'] = ['evaluate' => ['cards' => 'children']];

        self::assertCount(5, $this->parser->parse($template)->rules);
    }

    public function test_many_any_lists_beside_a_child_read_parse_without_expanding_every_combination(): void
    {
        $template = self::valid();
        $template['rules'][0]['when'] = ['all' => [
            ['card.type' => ['types' => ['epic']]],
            ['card.children.finished' => []],
            ...array_fill(0, 30, ['any' => [['pr.open' => []], ['card.type' => ['types' => ['epic']]]]]),
        ]];

        self::assertCount(5, $this->parser->parse($template)->rules);
    }

    public function test_a_pause_until_that_reads_children_only_for_epics_allows_a_rule_that_also_matches_bugs(): void
    {
        $template = self::valid();
        $template['rules'][3]['when'] = ['any' => [['card.type' => ['types' => ['bug']]], ['card.type' => ['types' => ['epic']]]]];
        $template['rules'][3]['then']['pause']['until'] = ['all' => [['card.type' => ['types' => ['epic']]], ['card.children.finished' => []]]];

        self::assertCount(5, $this->parser->parse($template)->rules);
    }

    public function test_a_branch_that_names_a_second_type_never_holds_and_reads_nothing(): void
    {
        $template = self::valid();
        $template['rules'][0]['when'] = ['all' => [
            ['card.type' => ['types' => ['bug']]],
            ['any' => [['pr.open' => []], ['all' => [['card.type' => ['types' => ['epic']]], ['card.children.finished' => []]]]]],
        ]];

        self::assertCount(5, $this->parser->parse($template)->rules);
    }

    public function test_a_template_reads_its_epic_branch(): void
    {
        $template = $this->parser->parse(self::valid() + ['epicBranch' => 'feature/epic-{number}']);

        self::assertSame('feature/epic-{number}', $template->epicBranch);
        self::assertSame('feature/epic-42', $template->epicBranchOf(42));
    }

    public function test_a_template_reads_its_repository_settings_in_order(): void
    {
        $template = $this->parser->parse(self::valid() + ['repositorySettings' => [
            ['key' => 'merge-method', 'mark' => 'blocking'],
            ['key' => 'branch-protection', 'mark' => 'warning'],
        ]]);

        self::assertEquals([
            new RepositorySuggestion(RepositorySuggestionKey::MergeMethod, SuggestionMark::Blocking),
            new RepositorySuggestion(RepositorySuggestionKey::BranchProtection, SuggestionMark::Warning),
        ], $template->repositorySettings);
    }

    public function test_a_template_with_no_repository_settings_suggests_none(): void
    {
        self::assertSame([], $this->parser->parse(self::valid())->repositorySettings);
        self::assertSame([], $this->parser->parse(self::valid() + ['repositorySettings' => []])->repositorySettings);
    }

    /**
     * @param array<string, mixed>       $when
     * @param list<array<string, mixed>> $values
     *
     * @return array<string, mixed>
     */
    private static function withValueRule(array $when, array $values = [], string $id = 'value-rule'): array
    {
        $source = self::valid();
        $source['rules'][] = ['id' => $id, 'slot' => 'build', 'when' => $when, 'then' => ['move' => ['to' => 'review']]];

        return $source + ['values' => $values];
    }

    public function test_a_rule_may_test_a_declared_value_key(): void
    {
        $template = $this->parser->parse(self::withValueRule(['card.value' => ['key' => 'good', 'equals' => true]], [self::valueKey('good')]));

        self::assertEquals(new ConditionLeaf(new CardValue(), ['key' => 'good', 'equals' => true]), array_last($template->rules)?->when);
        $held = $this->parser->parse(self::withValueRule(['card.value' => ['key' => 'good']], [self::valueKey('good')]));
        self::assertEquals(new ConditionLeaf(new CardValue(), ['key' => 'good']), array_last($held->rules)?->when);
    }

    public function test_a_rule_that_names_an_undeclared_value_key_is_refused_and_a_stored_copy_loads(): void
    {
        $source = self::withValueRule(['card.value' => ['key' => 'ghost']], [self::valueKey('good')]);

        try {
            $this->parser->parse($source);
            self::fail('The template should be refused.');
        } catch (InvalidTemplate $e) {
            self::assertCount(1, $e->errors);
            self::assertStringContainsString('unknown value key "ghost"; declared keys: good', $e->errors[0]);
        }
        self::assertSame('value-rule', array_last($this->parser->parseStored($source)->rules)?->id);

        try {
            $this->parser->parse(self::withValueRule(['card.value' => ['key' => 'ghost']]));
            self::fail('The template should be refused.');
        } catch (InvalidTemplate $e) {
            self::assertStringContainsString('the template declares no values', $e->errors[0]);
        }
    }

    public function test_a_value_condition_with_a_wrong_equals_or_no_key_is_refused(): void
    {
        foreach ([['key' => 'good', 'equals' => 'yes'], ['equals' => true], ['key' => 'good', 'extra' => 1]] as $params) {
            try {
                $this->parser->parse(self::withValueRule(['card.value' => $params], [self::valueKey('good')]));
                self::fail('The template should be refused.');
            } catch (InvalidTemplate $e) {
                self::assertNotSame([], $e->errors);
            }
        }
    }

    public function test_a_template_reads_its_value_keys_in_order(): void
    {
        $template = $this->parser->parse(self::valid() + ['values' => [
            self::valueKey('tech-design-interactive'),
            self::valueKey('review-2', 'review2'),
        ]]);

        self::assertEquals([
            new TemplateValueKey('tech-design-interactive', CardValueType::Bool, 'workflow.value.tech-design-interactive.label', 'workflow.value.tech-design-interactive.caption', 'workflow.value.tech-design-interactive.help', 'workflow.value.tech-design-interactive.history'),
            new TemplateValueKey('review-2', CardValueType::Bool, 'workflow.value.review2.label', 'workflow.value.review2.caption', 'workflow.value.review2.help', 'workflow.value.review2.history'),
        ], $template->values);
    }

    public function test_a_template_with_no_values_section_has_no_value_keys(): void
    {
        self::assertSame([], $this->parser->parse(self::valid())->values);
        self::assertSame([], $this->parser->parse(self::valid() + ['values' => []])->values);
        self::assertSame([], $this->parser->parseStored(self::valid())->values);
    }

    /**
     * @param list<string> $errors
     * @param list<string> $kept
     */
    #[DataProvider('invalidValueKeys')]
    public function test_value_keys_that_are_not_valid_are_refused_and_a_stored_copy_drops_them(mixed $value, array $errors, array $kept): void
    {
        $source = self::valid() + ['values' => $value];

        try {
            $this->parser->parse($source);
            self::fail('The template should be refused.');
        } catch (InvalidTemplate $e) {
            self::assertSame($errors, $e->errors);
        }
        self::assertSame($kept, array_map(static fn (TemplateValueKey $key): string => $key->key, $this->parser->parseStored($source)->values));
    }

    /** @return iterable<string, array{mixed, list<string>, list<string>}> */
    public static function invalidValueKeys(): iterable
    {
        $good = self::valueKey('good');

        yield 'an explicit null' => [null, ['values: must be a list of value keys'], []];
        yield 'a map' => [['good' => $good], ['values: must be a list of value keys'], []];
        yield 'an unknown type' => [[['type' => 'text'] + self::valueKey('other'), $good], ['values[0]: unknown type "text"'], ['good']];
        yield 'a missing type' => [[array_diff_key(self::valueKey('other'), ['type' => 1]), $good], ['values[0]: unknown type "null"'], ['good']];
        yield 'a duplicate key' => [[$good, $good], ['values[1]: duplicate key "good"'], ['good']];
        yield 'an uppercase key' => [[['key' => 'Good'] + $good], ['values[0]: key "Good" must be lowercase letters, digits and hyphens'], []];
        yield 'an underscore key' => [[['key' => 'tech_design'] + $good], ['values[0]: key "tech_design" must be lowercase letters, digits and hyphens'], []];
        yield 'a key with a leading hyphen' => [[['key' => '-good'] + $good], ['values[0]: key "-good" must be lowercase letters, digits and hyphens'], []];
        yield 'a missing key' => [[array_diff_key($good, ['key' => 1])], ['values[0]: key "null" must be lowercase letters, digits and hyphens'], []];
        yield 'a missing caption' => [[array_diff_key($good, ['caption' => 1])], ['values[0]: caption must be a non-empty string'], []];
        yield 'an empty history' => [[['history' => ''] + $good], ['values[0]: history must be a non-empty string'], []];
        yield 'an extra field' => [[$good + ['note' => 'x']], ['values[0]: unknown field "note"'], []];
        yield 'an entry that is not a map' => [['good'], ['values[0]: must be a map'], []];
    }

    /** @return array<string, string> */
    private static function valueKey(string $key, ?string $translationName = null): array
    {
        $name = $translationName ?? $key;

        return [
            'key' => $key,
            'type' => 'bool',
            'label' => 'workflow.value.'.$name.'.label',
            'caption' => 'workflow.value.'.$name.'.caption',
            'help' => 'workflow.value.'.$name.'.help',
            'history' => 'workflow.value.'.$name.'.history',
        ];
    }

    /** @param list<string> $errors */
    #[DataProvider('invalidRepositorySettings')]
    public function test_repository_settings_that_are_not_valid_are_refused_and_a_stored_copy_drops_them(mixed $value, array $errors, int $kept): void
    {
        $source = self::valid() + ['repositorySettings' => $value];

        try {
            $this->parser->parse($source);
            self::fail('The template should be refused.');
        } catch (InvalidTemplate $e) {
            self::assertSame($errors, $e->errors);
        }
        self::assertCount($kept, $this->parser->parseStored($source)->repositorySettings);
    }

    /** @return iterable<string, array{mixed, list<string>, int}> */
    public static function invalidRepositorySettings(): iterable
    {
        yield 'an explicit null' => [null, ['repositorySettings: must be a list of suggestions'], 0];
        yield 'a string' => ['merge-method', ['repositorySettings: must be a list of suggestions'], 0];
        yield 'a map' => [['merge-method' => 'blocking'], ['repositorySettings: must be a list of suggestions'], 0];
        yield 'an unknown key' => [[['key' => 'signed-commits', 'mark' => 'warning'], ['key' => 'ci-checks', 'mark' => 'warning']], ['repositorySettings[0]: unknown key "signed-commits"'], 1];
        yield 'an unknown mark' => [[['key' => 'ci-checks', 'mark' => 'fatal']], ['repositorySettings[0]: mark must be blocking or warning'], 0];
        yield 'a missing mark' => [[['key' => 'ci-checks']], ['repositorySettings[0]: mark must be blocking or warning'], 0];
        yield 'an extra field' => [[['key' => 'ci-checks', 'mark' => 'warning', 'note' => 'x']], ['repositorySettings[0]: must hold a key and a mark only'], 0];
        yield 'an entry that is not a map' => [['ci-checks'], ['repositorySettings[0]: must hold a key and a mark only'], 0];
        yield 'a duplicate key' => [[['key' => 'ci-checks', 'mark' => 'warning'], ['key' => 'ci-checks', 'mark' => 'blocking']], ['repositorySettings[1]: duplicate key "ci-checks"'], 1];
    }

    public function test_a_template_with_no_epic_branch_has_none(): void
    {
        $template = $this->parser->parse(self::valid());

        self::assertNull($template->epicBranch);
        self::assertNull($template->epicBranchOf(42));
    }

    public function test_a_template_lets_an_approval_cover_a_conflict_resolution_unless_it_says_otherwise(): void
    {
        self::assertTrue($this->parser->parse(self::valid())->approvalCoversConflictResolution);
        self::assertTrue($this->parser->parse(self::valid() + ['approvalCoversConflictResolution' => true])->approvalCoversConflictResolution);
        self::assertFalse($this->parser->parse(self::valid() + ['approvalCoversConflictResolution' => false])->approvalCoversConflictResolution);
    }

    #[DataProvider('invalidApprovalRules')]
    public function test_an_approval_rule_that_is_not_a_boolean_is_refused_and_a_stored_copy_reads_true(mixed $value): void
    {
        $source = self::valid() + ['approvalCoversConflictResolution' => $value];

        try {
            $this->parser->parse($source);
            self::fail('The template should be refused.');
        } catch (InvalidTemplate $e) {
            self::assertContains('approvalCoversConflictResolution: must be true or false', $e->errors);
        }
        self::assertTrue($this->parser->parseStored($source)->approvalCoversConflictResolution);
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidApprovalRules(): iterable
    {
        yield 'a string' => ['false'];
        yield 'an integer' => [0];
        yield 'a list' => [[false]];
        yield 'an explicit null' => [null];
    }

    #[DataProvider('invalidEpicBranches')]
    public function test_an_epic_branch_that_is_not_a_branch_name_with_one_placeholder_is_refused_and_a_stored_copy_has_none(mixed $value): void
    {
        $source = self::valid() + ['epicBranch' => $value];

        try {
            $this->parser->parse($source);
            self::fail('The template should be refused.');
        } catch (InvalidTemplate $e) {
            self::assertContains('epicBranch: must be a Git branch name that holds the placeholder {number} exactly once', $e->errors);
        }
        self::assertNull($this->parser->parseStored($source)->epicBranch);
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidEpicBranches(): iterable
    {
        yield 'blank' => [''];
        yield 'no placeholder' => ['epic'];
        yield 'two placeholders' => ['epic/{number}/{number}'];
        yield 'a space' => ['epic /{number}'];
        yield 'two dots' => ['epic..{number}'];
        yield 'a slash at the end' => ['epic/{number}/'];
        yield 'not a string' => [7];
        yield 'too long' => [str_repeat('a', 250).'/{number}'];
    }

    public function test_a_template_reads_its_agent_review_failing_severities_in_the_order_of_the_severities(): void
    {
        $template = $this->parser->parse(self::valid() + ['agentReviewFailingSeverities' => ['pre-existing', 'important', 'pre-existing']]);

        self::assertSame(['important', 'pre-existing'], $template->agentReviewFailingSeverities);
    }

    public function test_a_template_with_no_agent_review_failing_severities_fails_on_an_important_finding(): void
    {
        self::assertSame(['important'], $this->parser->parse(self::valid())->agentReviewFailingSeverities);
    }

    #[DataProvider('invalidAgentReviewFailingSeverities')]
    public function test_agent_review_failing_severities_that_are_not_known_severities_are_refused_and_a_stored_copy_has_the_default(mixed $value): void
    {
        $source = self::valid() + ['agentReviewFailingSeverities' => $value];

        try {
            $this->parser->parse($source);
            self::fail('The template should be refused.');
        } catch (InvalidTemplate $e) {
            self::assertContains('agentReviewFailingSeverities: must list one or more of important, nit and pre-existing', $e->errors);
        }
        self::assertSame(['important'], $this->parser->parseStored($source)->agentReviewFailingSeverities);
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidAgentReviewFailingSeverities(): iterable
    {
        yield 'empty' => [[]];
        yield 'an unknown severity' => [['important', 'blocker']];
        yield 'not a list' => ['important'];
        yield 'not a string' => [[7]];
    }

    public function test_a_template_reads_the_retry_policy_for_a_refused_request(): void
    {
        self::assertNull($this->parser->parse(self::valid())->onWorkFailed);

        $policy = $this->parser->parse(self::valid() + ['onWorkFailed' => ['retryOn' => ['failed', 'timeout'], 'retries' => 1, 'backoffMinutes' => [2, 3]]])->onWorkFailed;

        self::assertNotNull($policy);
        self::assertSame(1, $policy->retries);
        self::assertSame([2, 3], $policy->backoffMinutes);
        self::assertTrue($policy->retries('timeout'));
        self::assertFalse($policy->retries('needs-person'));
        self::assertNull($policy->repairKind);
    }

    public function test_a_retry_policy_reads_the_kind_of_its_repair_request(): void
    {
        $policy = $this->parser->parse(self::valid() + ['onWorkFailed' => ['retryOn' => ['failed'], 'retries' => 1, 'backoffMinutes' => [2], 'repair' => ['kind' => 'repair']]])->onWorkFailed;

        self::assertSame('repair', $policy?->repairKind);
    }

    public function test_an_evaluate_action_names_the_children(): void
    {
        $template = self::valid();
        $template['rules'][4]['then'] = ['evaluate' => ['cards' => 'children']];

        $then = $this->parser->parse($template)->rulesFor('@terminal')[1]->lead;
        self::assertSame('evaluate', $then->key);
        self::assertSame(['cards' => 'children'], $then->params);
    }

    public function test_a_state_write_and_the_epic_opening_need_no_fallback(): void
    {
        foreach (['draft', 'ready', 'close', 'open-epic'] as $write) {
            $template = self::valid();
            $template['rules'][2]['then'] = ['forge-write' => ['write' => $write]];

            self::assertSame(['write' => $write], $this->parser->parse($template)->rulesFor('review')[0]->lead->params);
        }
    }

    public function test_a_comment_write_names_its_comment_and_needs_no_fallback(): void
    {
        $template = self::valid();
        $template['rules'][2]['then'] = ['forge-write' => ['write' => 'comment', 'comment' => 'fix-run']];

        self::assertSame(['write' => 'comment', 'comment' => 'fix-run'], $this->parser->parse($template)->rulesFor('review')[0]->lead->params);

        $template['rules'][2]['then'] = ['forge-write' => ['write' => 'comment']];
        $this->expectException(InvalidTemplate::class);
        $this->expectExceptionMessage('missing parameter "comment"');
        $this->parser->parse($template);
    }

    public function test_the_parameters_of_an_action_come_from_its_declarations(): void
    {
        $parser = new TemplateParser(new Conditions([new CardInSlot()]), new Actions([new PluggedAction()]), new TemplatePrompts(self::PROMPTS_FIXTURE), self::metricParser());
        $template = self::valid();
        $template['rules'] = [['id' => 'plugged', 'when' => ['card.in_slot' => ['slot' => 'build']], 'then' => ['plugged' => ['from' => 'review', 'times' => 2, 'mode' => 'fast']]]];

        $parsed = $parser->parse($template);
        $call = $parsed->rules[0]->lead;

        self::assertSame('plugged', $call->key);
        self::assertSame(['from' => 'review', 'times' => 2, 'mode' => 'fast'], $call->params);
        self::assertSame('review', $call->from);
        self::assertTrue($call->traits->endsPass);
        self::assertSame([], $parsed->rulesFor('build'), 'A rule that acts from another slot is not a rule of this slot.');
        self::assertCount(1, $parsed->rulesFor('review'));
    }

    public function test_an_action_cannot_declare_an_expression_parameter_that_a_call_drops(): void
    {
        $parser = new TemplateParser(new Conditions([new CardInSlot()]), new Actions([new ExpressionPluggedAction()]), new TemplatePrompts(self::PROMPTS_FIXTURE), self::metricParser());
        $template = self::valid();
        $template['rules'] = [['id' => 'expressive', 'when' => ['card.in_slot' => ['slot' => 'build']], 'then' => ['expressive' => ['from' => 'review']]]];

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('declares the expression parameter "only"');
        $parser->parse($template);
    }

    /** @param array<string, mixed> $then */
    #[DataProvider('pluggedActionErrors')]
    public function test_the_declared_parameters_of_an_action_are_checked(array $then, string $error): void
    {
        $parser = new TemplateParser(new Conditions([new CardInSlot()]), new Actions([new PluggedAction()]), new TemplatePrompts(self::PROMPTS_FIXTURE), self::metricParser());
        $template = self::valid();
        $template['rules'] = [['id' => 'plugged', 'when' => ['card.in_slot' => ['slot' => 'build']], 'then' => ['plugged' => $then]]];

        try {
            $parser->parse($template);
            self::fail('The template must be refused.');
        } catch (InvalidTemplate $e) {
            self::assertSame([$error], $e->errors);
        }
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function pluggedActionErrors(): iterable
    {
        yield 'a missing required parameter' => [['from' => 'review'], 'rules[0] (plugged) then.plugged: missing parameter "times"'];
        yield 'a slot that is not declared' => [['from' => 'shipping', 'times' => 2], 'rules[0] (plugged) then.plugged.from: unknown slot "shipping"'];
        yield 'an int under its minimum' => [['from' => 'review', 'times' => 1], 'rules[0] (plugged) then.plugged: parameter "times" must be an integer of at least 2'];
        yield 'an int over its maximum' => [['from' => 'review', 'times' => 2, 'cap' => 10], 'rules[0] (plugged) then.plugged: parameter "cap" must be an integer from 2 to 9'];
        yield 'an int under the minimum of a bounded range' => [['from' => 'review', 'times' => 2, 'cap' => 1], 'rules[0] (plugged) then.plugged: parameter "cap" must be an integer from 2 to 9'];
        yield 'a value outside the choices' => [['from' => 'review', 'times' => 2, 'mode' => 'slow'], 'rules[0] (plugged) then.plugged: parameter "mode" must be one of fast, safe'];
        yield 'a parameter the action does not declare' => [['from' => 'review', 'times' => 2, 'speed' => 1], 'rules[0] (plugged) then.plugged: unknown parameter "speed"'];
        yield 'a rule on the parameters as a whole' => [['from' => 'review', 'times' => 2, 'mode' => 'safe'], 'rules[0] (plugged) then.plugged: safe mode needs the slot build'];
    }

    public function test_an_app_rule_and_a_template_rule_both_carry_a_prompt(): void
    {
        $rules = [['id' => 'groom', 'slot' => '@backlog', 'when' => ['card.in_slot' => ['slot' => '@backlog']], 'then' => ['request' => ['kind' => 'groom', 'prompt' => 'groom-card']]]];

        self::assertSame('groom-card', $this->parser->parseAppRules(['rules' => $rules])[0]->lead->params['prompt']);
        $template = self::valid();
        $template['rules'][0]['then']['request']['prompt'] = 'groom-card';

        self::assertSame('groom-card', $this->parser->parse($template)->rules[0]->lead->params['prompt']);
        self::assertSame('groom-card', $this->parser->parseStored($template)->rules[0]->lead->params['prompt']);
    }

    public function test_a_request_can_name_a_document_by_tag(): void
    {
        $template = self::valid();
        $template['rules'][0]['then'] = ['request' => ['kind' => 'tech-design-revise', 'document' => ['tag' => 'design']]];

        self::assertSame(['kind' => 'tech-design-revise', 'document.tag' => 'design'], $this->parser->parse($template)->rulesFor('build')[0]->lead->params);
    }

    public function test_a_request_can_name_a_document_by_tag_and_status(): void
    {
        $template = self::valid();
        $template['rules'][0]['then'] = ['request' => ['kind' => 'tech-design-revise', 'document' => ['tag' => 'design', 'status' => 'changes-requested']]];

        self::assertSame(
            ['kind' => 'tech-design-revise', 'document.tag' => 'design', 'document.status' => 'changes-requested'],
            $this->parser->parse($template)->rulesFor('build')[0]->lead->params,
        );
    }

    public function test_a_request_carries_its_checks_apart_from_its_params(): void
    {
        $template = self::valid();
        $template['rules'][0]['then']['request']['checks'] = ['A worktree per card', 'A preview URL per branch'];

        foreach ([$this->parser->parse(...), $this->parser->parseStored(...)] as $parse) {
            $then = $parse($template)->rulesFor('build')[0]->lead;
            self::assertSame(['A worktree per card', 'A preview URL per branch'], $then->checks);
            self::assertSame(['kind' => 'implement', 'capability' => 'interactive', 'limit' => 3], $then->params);
        }
    }

    public function test_a_request_carries_its_cancel_when_apart_from_its_params(): void
    {
        $template = self::valid();
        $template['rules'][0]['then']['request']['cancelWhen'] = ['pr.open' => []];

        foreach ([$this->parser->parse(...), $this->parser->parseStored(...)] as $parse) {
            $then = $parse($template)->rulesFor('build')[0]->lead;
            self::assertNotNull($then->cancelWhen);
            self::assertSame(['kind' => 'implement', 'capability' => 'interactive', 'limit' => 3], $then->params);
        }
    }

    public function test_a_request_without_cancel_when_has_none(): void
    {
        self::assertNull($this->parser->parse(self::valid())->rulesFor('build')[0]->lead->cancelWhen);
    }

    public function test_publish_documents_keeps_its_tags_as_a_list_param(): void
    {
        $template = self::valid();
        $template['rules'][] = ['id' => 'publish', 'when' => ['pr.open' => []], 'then' => ['publish-documents' => ['tags' => ['tech-design', 'deploy-notes']]]];

        $then = array_find($this->parser->parse($template)->rules, static fn ($rule): bool => 'publish' === $rule->id)?->lead;

        self::assertSame(['tags' => ['tech-design', 'deploy-notes']], $then?->lists);
        self::assertSame([], $then->params);
    }

    public function test_the_verdict_actions_keep_their_tag_tags_and_note(): void
    {
        $template = self::valid();
        $template['rules'][] = ['id' => 'approve', 'when' => ['pr.open' => []], 'then' => ['approve-document' => ['tag' => 'tech-design']]];
        $template['rules'][] = ['id' => 'changes', 'when' => ['pr.open' => []], 'then' => ['request-changes' => ['tags' => ['tech-design'], 'note' => 'workflow.note.rework']]];
        $rules = $this->parser->parse($template)->rules;

        $approve = array_find($rules, static fn ($rule): bool => 'approve' === $rule->id)?->lead;
        $changes = array_find($rules, static fn ($rule): bool => 'changes' === $rule->id)?->lead;

        self::assertSame(['tag' => 'tech-design'], $approve?->params);
        self::assertSame(['note' => 'workflow.note.rework'], $changes?->params);
        self::assertSame(['tags' => ['tech-design']], $changes->lists);
    }

    public function test_a_request_with_no_checks_has_an_empty_list(): void
    {
        self::assertSame([], $this->parser->parse(self::valid())->rulesFor('build')[0]->lead->checks);
    }

    public function test_app_rules_accept_a_request_with_checks(): void
    {
        $rules = $this->parser->parseAppRules(['rules' => [
            ['id' => 'app-done', 'slot' => '@terminal', 'when' => ['all' => []], 'then' => ['request' => ['kind' => 'teardown', 'checks' => ['A teardown command']]]],
        ]]);

        self::assertSame(['A teardown command'], $rules[0]->lead->checks);
    }

    public function test_app_requests_are_optional_and_parse_to_value_objects(): void
    {
        self::assertSame([], $this->parser->parseAppRequests(['rules' => []]));

        $requests = $this->parser->parseAppRequests(['requests' => [
            ['id' => 'insights.analysis', 'kind' => 'analysis', 'prompt' => 'analysis', 'checks' => ['A skill']],
        ]]);

        self::assertEquals([new AppRequest('insights.analysis', 'analysis', 'analysis', ['A skill'])], $requests);
    }

    /** @return iterable<string, array{array<mixed>, list<string>}> */
    public static function appRequestRefusals(): iterable
    {
        $request = static fn (array $change): array => ['requests' => [[...['id' => 'r', 'kind' => 'analysis', 'prompt' => 'analysis', 'checks' => ['A skill']], ...$change]]];

        yield 'not a list' => [['requests' => ['a' => 1]], ['requests: must be a list']];
        yield 'not a map' => [['requests' => ['x']], ['requests[0]: must be a map']];
        yield 'no id' => [$request(['id' => '']), ['requests[0] id: must be a non-empty string']];
        yield 'no kind' => [$request(['kind' => '']), ['requests[0] (r): parameter "kind" must be a non-empty string']];
        yield 'a prompt with a path' => [$request(['prompt' => '../secret']), ['requests[0] (r): parameter "prompt" must match [a-z][a-z0-9-], at most 40 characters']];
        yield 'empty checks' => [$request(['checks' => []]), ['requests[0] (r): parameter "checks" must be a non-empty list of non-empty strings']];
        yield 'an unknown key' => [$request(['slot' => 'x']), ['requests[0] (r): unknown key "slot"']];
        yield 'a duplicate id' => [['requests' => [
            ['id' => 'r', 'kind' => 'a', 'prompt' => 'a', 'checks' => ['c']],
            ['id' => 'r', 'kind' => 'b', 'prompt' => 'b', 'checks' => ['c']],
        ]], ['requests[1] (r): duplicate request id "r"']];
    }

    /**
     * @param array<mixed> $source
     * @param list<string> $errors
     */
    #[DataProvider('appRequestRefusals')]
    public function test_app_requests_refuse_an_invalid_source(array $source, array $errors): void
    {
        try {
            $this->parser->parseAppRequests($source);
            self::fail('The parser must refuse the app requests.');
        } catch (InvalidTemplate $e) {
            self::assertSame($errors, $e->errors);
        }
    }

    public function test_the_account_usage_condition_takes_a_bridge_name_with_a_slash_and_a_window_key(): void
    {
        $template = self::valid();
        $template['rules'][2]['when'] = ['all' => [
            ['bridge.account.usage' => ['account' => 'home/mac/work', 'window' => 'week', 'above' => 100]],
            ['bridge.account.usage' => ['account' => 'mac/work-2', 'window' => '3d', 'above' => 1]],
        ]];

        $leaves = $this->parser->parse($template)->rules[2]->when->leaves();

        self::assertSame(
            [['account' => 'home/mac/work', 'window' => 'week', 'above' => 100], ['account' => 'mac/work-2', 'window' => '3d', 'above' => 1]],
            array_map(static fn (ConditionLeaf $leaf): array => $leaf->params, $leaves),
        );
    }

    public function test_a_document_condition_takes_a_tag_and_an_optional_status(): void
    {
        $template = self::valid();
        $template['rules'][2]['when'] = ['all' => [
            ['card.document.linked' => ['tag' => 'design']],
            ['card.document.linked' => ['tag' => 'design', 'status' => 'in-review']],
        ]];

        $leaves = $this->parser->parse($template)->rules[2]->when->leaves();

        self::assertSame([['tag' => 'design'], ['tag' => 'design', 'status' => 'in-review']], array_map(static fn (ConditionLeaf $leaf): array => $leaf->params, $leaves));
    }

    public function test_a_slot_reads_the_tag_of_a_document_condition(): void
    {
        $template = self::valid();
        $template['rules'][0]['when'] = ['not' => ['card.document.linked' => ['tag' => 'plan']]];

        self::assertSame(['plan'], $this->parser->parse($template)->documentTagsFor('build'));
    }

    /**
     * @param array<string, mixed> $when
     * @param array<string, mixed> $then
     * @param list<string>         $tags
     */
    private function reviewLimit(array $when, array $then, array $tags): ?int
    {
        $template = self::valid();
        $template['rules'] = [['id' => 'publish', 'slot' => 'build', 'when' => $when, 'then' => $then]];

        return $this->parser->parse($template)->reviewRoundLimit($tags);
    }

    /** @return array<string, array{0: array<string, mixed>, 1: ?int}> */
    public static function reviewLimitConditions(): array
    {
        $finished = static fn (string $tag, int $rounds): array => ['design_review.finished' => ['tag' => $tag, 'rounds' => $rounds]];

        return [
            'the whole condition' => [$finished('tech-design', 4), 4],
            'an entry of a top-level all' => [['all' => [['not' => ['card.blocker.open' => []]], $finished('tech-design', 5)]], 5],
            'an entry of a nested all' => [['all' => [['all' => [$finished('tech-design', 6)]]]], 6],
            'a leaf under any' => [['any' => [$finished('tech-design', 4), ['card.blocker.open' => []]]], null],
            'a leaf under not' => [['not' => $finished('tech-design', 4)], null],
            'a leaf of another tag' => [$finished('product-design', 4), null],
        ];
    }

    /** @param array<string, mixed> $when */
    #[DataProvider('reviewLimitConditions')]
    public function test_the_review_limit_comes_from_a_check_the_publish_rule_requires(array $when, ?int $expected): void
    {
        self::assertSame($expected, $this->reviewLimit($when, ['publish-documents' => ['tags' => ['tech-design']]], ['tech-design']));
    }

    public function test_a_check_in_a_rule_that_publishes_nothing_gives_no_limit(): void
    {
        $when = ['design_review.finished' => ['tag' => 'tech-design', 'rounds' => 2]];

        self::assertNull($this->reviewLimit($when, ['move' => ['to' => 'review']], ['tech-design']));
    }

    public function test_a_check_for_a_tag_the_rule_does_not_publish_gives_no_limit(): void
    {
        $when = ['design_review.finished' => ['tag' => 'tech-design', 'rounds' => 2]];

        self::assertNull($this->reviewLimit($when, ['publish-documents' => ['tags' => ['deploy-notes']]], ['tech-design']));
    }

    public function test_a_document_with_neither_design_tag_has_no_limit(): void
    {
        $when = ['design_review.finished' => ['tag' => 'tech-design', 'rounds' => 2]];

        self::assertNull($this->reviewLimit($when, ['publish-documents' => ['tags' => ['tech-design']]], ['plan']));
    }

    public function test_a_document_with_both_design_tags_takes_the_smaller_limit(): void
    {
        $template = self::valid();
        $publish = static fn (string $id, string $tag, int $rounds): array => [
            'id' => $id,
            'slot' => 'build',
            'when' => ['design_review.finished' => ['tag' => $tag, 'rounds' => $rounds]],
            'then' => ['publish-documents' => ['tags' => [$tag]]],
        ];
        $template['rules'] = [$publish('a', 'product-design', 6), $publish('b', 'tech-design', 3)];

        self::assertSame(3, $this->parser->parse($template)->reviewRoundLimit(['product-design', 'tech-design']));
    }

    public function test_a_valid_template_round_trips_through_json(): void
    {
        $decoded = json_decode(json_encode(self::valid(), \JSON_THROW_ON_ERROR), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        self::assertEquals($this->parser->parse(self::valid()), $this->parser->parse($decoded));
    }

    public function test_card_in_slot_accepts_the_backlog_and_terminal_flags(): void
    {
        foreach (['@backlog', '@terminal'] as $flag) {
            $source = self::valid();
            $source['rules'][1]['when']['any'][1] = ['card.in_slot' => ['slot' => $flag]];

            $leaf = $this->parser->parse($source)->rules[1]->when;
            self::assertInstanceOf(AnyOf::class, $leaf);
            self::assertEquals(new ConditionLeaf(new CardInSlot(), ['slot' => $flag]), $leaf->children[1]);
        }
    }

    /** @return iterable<string, array{\Closure(array<string, mixed>): array<string, mixed>, string}> */
    public static function refusals(): iterable
    {
        yield 'missing top-level key' => [static function (array $t): array {
            unset($t['backoffMinutes']);

            return $t;
        }, 'backoffMinutes: is missing'];
        yield 'wrongly typed version' => [static fn (array $t): array => ['version' => '1'] + $t, 'version: must be an integer'];
        yield 'wrongly typed key' => [static fn (array $t): array => ['key' => ''] + $t, 'key: must be a non-empty string'];
        yield 'slots not a list' => [static fn (array $t): array => ['slots' => 'build', 'manualMoves' => [], 'rules' => []] + $t, 'slots: must be a list'];
        yield 'rules not a list' => [static fn (array $t): array => ['rules' => ['a' => 1]] + $t, 'rules: must be a list'];
        yield 'manual moves not a list' => [static fn (array $t): array => ['manualMoves' => 3] + $t, 'manualMoves: must be a list'];
        yield 'wrongly typed backoff' => [static fn (array $t): array => ['backoffMinutes' => [10, '60']] + $t, 'backoffMinutes: must be a list of positive integers'];
        yield 'retry policy with no list' => [static fn (array $t): array => ['onWorkFailed' => ['retries' => 1, 'backoffMinutes' => [2]]] + $t, 'onWorkFailed: must be a map with a list "retryOn"'];
        yield 'retry policy with a bad code' => [static fn (array $t): array => ['onWorkFailed' => ['retryOn' => ['Not A Code'], 'retries' => 1, 'backoffMinutes' => [2]]] + $t, 'onWorkFailed.retryOn: each entry must be a refusal code'];
        yield 'repair that is not a map' => [static fn (array $t): array => ['onWorkFailed' => ['retryOn' => ['failed'], 'retries' => 1, 'backoffMinutes' => [2], 'repair' => 'repair']] + $t, 'onWorkFailed.repair: must be a map with a string "kind"'];
        yield 'repair with no kind' => [static fn (array $t): array => ['onWorkFailed' => ['retryOn' => ['failed'], 'retries' => 1, 'backoffMinutes' => [2], 'repair' => []]] + $t, 'onWorkFailed.repair: must be a map with a string "kind"'];
        yield 'repair with a bad kind' => [static fn (array $t): array => ['onWorkFailed' => ['retryOn' => ['failed'], 'retries' => 1, 'backoffMinutes' => [2], 'repair' => ['kind' => 'Not A Kind']]] + $t, 'onWorkFailed.repair.kind: must be a work request kind'];
        yield 'repair kind that a rule asks for' => [static fn (array $t): array => ['onWorkFailed' => ['retryOn' => ['failed'], 'retries' => 1, 'backoffMinutes' => [2], 'repair' => ['kind' => 'implement']]] + $t, 'onWorkFailed.repair.kind: the rule "start" already asks for the kind "implement"'];
        yield 'repair kind that a forge write falls back to' => [static fn (array $t): array => ['onWorkFailed' => ['retryOn' => ['failed'], 'retries' => 1, 'backoffMinutes' => [2], 'repair' => ['kind' => 'merge']]] + $t, 'onWorkFailed.repair.kind: the rule "merge" already asks for the kind "merge"'];
        yield 'retry policy with a negative count' => [static fn (array $t): array => ['onWorkFailed' => ['retryOn' => ['failed'], 'retries' => -1, 'backoffMinutes' => [2]]] + $t, 'onWorkFailed.retries: must be a non-negative integer'];
        yield 'retry policy with no delays' => [static fn (array $t): array => ['onWorkFailed' => ['retryOn' => ['failed'], 'retries' => 1]] + $t, 'onWorkFailed.backoffMinutes: must be a list of positive integers'];
        yield 'retry policy with a zero delay' => [static fn (array $t): array => ['onWorkFailed' => ['retryOn' => ['failed'], 'retries' => 1, 'backoffMinutes' => [0]]] + $t, 'onWorkFailed.backoffMinutes: must be a list of positive integers'];
        yield 'retry policy with a text delay' => [static fn (array $t): array => ['onWorkFailed' => ['retryOn' => ['failed'], 'retries' => 1, 'backoffMinutes' => 'x']] + $t, 'onWorkFailed.backoffMinutes: must be a list of positive integers'];

        yield 'missing types' => [static function (array $t): array {
            unset($t['types']);

            return $t;
        }, 'types: is missing'];
        yield 'empty types' => [static fn (array $t): array => ['types' => []] + $t, 'types: must be a non-empty list'];
        yield 'missing default type' => [static function (array $t): array {
            unset($t['defaultType']);

            return $t;
        }, 'defaultType: is missing'];
        yield 'default type that is not a string' => [static fn (array $t): array => ['defaultType' => 3] + $t, 'defaultType: must be a non-empty string'];
        yield 'default type that is not declared' => [static fn (array $t): array => ['defaultType' => 'chore'] + $t, 'defaultType: unknown type "chore"'];
        yield 'default type that may have children' => [static fn (array $t): array => ['defaultType' => 'epic'] + $t, 'defaultType: type "epic" may have children'];
        yield 'type with no label' => [static function (array $t): array {
            unset($t['types'][1]['label']);

            return $t;
        }, 'types[1]: must be a map with a string "key" and a string "label"'];
        yield 'type with a bad tone' => [static function (array $t): array {
            $t['types'][1]['tone'] = 'beige';

            return $t;
        }, 'types[1] (bug): "tone" must be one of neutral, lime, purple, green, amber, red, teal, sky, blue, indigo, pink, orange'];
        yield 'type with no tone' => [static function (array $t): array {
            unset($t['types'][1]['tone']);

            return $t;
        }, 'types[1] (bug): "tone" must be one of neutral, lime, purple, green, amber, red, teal, sky, blue, indigo, pink, orange'];
        yield 'type with an unknown capability' => [static function (array $t): array {
            $t['types'][1]['capabilities'] = ['children', 'swimming'];

            return $t;
        }, 'types[1] (bug): "capabilities" must be a list of children, lane'];
        yield 'type with an unknown key' => [static function (array $t): array {
            $t['types'][1]['colour'] = 'amber';

            return $t;
        }, 'types[1] (bug): unknown key "colour"'];
        yield 'duplicate type key' => [static function (array $t): array {
            $t['types'][] = ['key' => 'bug', 'label' => 'board.card.type.bug', 'tone' => 'red'];

            return $t;
        }, 'types[3] (bug): duplicate type key "bug"'];
        yield 'type key longer than the card column' => [static function (array $t): array {
            $t['types'][1]['key'] = 'a-type-key-of-21-char';

            return $t;
        }, 'types[1] (a-type-key-of-21-char): "key" must have at most 20 characters'];
        yield 'card type condition naming an undeclared type' => [static function (array $t): array {
            $t['rules'][0]['when']['all'][0] = ['card.type' => ['types' => ['chore']]];

            return $t;
        }, 'rules[0] (start) when.all[0]: card.type: unknown type "chore"'];
        yield 'pause until naming an undeclared type' => [static function (array $t): array {
            $t['rules'][3]['then']['pause']['until'] = ['card.type' => ['types' => ['chore']]];

            return $t;
        }, 'rules[3] (wait) then.pause.until: card.type: unknown type "chore"'];
        yield 'request refill naming an undeclared type' => [static function (array $t): array {
            $t['rules'][0]['then']['request']['refill'] = ['card.type' => ['types' => ['chore']]];

            return $t;
        }, 'rules[0] (start) then.request.refill: card.type: unknown type "chore"'];
        yield 'request cancel when naming an undeclared type' => [static function (array $t): array {
            $t['rules'][0]['then']['request']['cancelWhen'] = ['card.type' => ['types' => ['chore']]];

            return $t;
        }, 'rules[0] (start) then.request.cancelWhen: card.type: unknown type "chore"'];
        yield 'publish-documents with an empty tags list' => [static function (array $t): array {
            $t['rules'][] = ['id' => 'publish', 'when' => ['pr.open' => []], 'then' => ['publish-documents' => ['tags' => []]]];

            return $t;
        }, 'rules[5] (publish) then.publish-documents: parameter "tags" must be a non-empty list of non-empty strings'];
        yield 'publish-documents with no tags' => [static function (array $t): array {
            $t['rules'][] = ['id' => 'publish', 'when' => ['pr.open' => []], 'then' => ['publish-documents' => []]];

            return $t;
        }, 'rules[5] (publish) then.publish-documents: missing parameter "tags"'];
        yield 'card type condition with the old single type key' => [static function (array $t): array {
            $t['rules'][0]['when']['all'][0] = ['card.type' => ['type' => 'epic']];

            return $t;
        }, 'rules[0] (start) when.all[0]: card.type: parameter "type" is gone, use the "types" list'];
        yield 'card type condition with a list entry that is an undeclared type' => [static function (array $t): array {
            $t['rules'][0]['when']['all'][0] = ['card.type' => ['types' => ['bug', 'chore']]];

            return $t;
        }, 'rules[0] (start) when.all[0]: card.type: unknown type "chore"'];
        yield 'card type condition with an empty list' => [static function (array $t): array {
            $t['rules'][0]['when']['all'][0] = ['card.type' => ['types' => []]];

            return $t;
        }, 'rules[0] (start) when.all[0]: card.type: parameter "types" must be a non-empty list of non-empty strings'];
        yield 'card type condition with a string where the list goes' => [static function (array $t): array {
            $t['rules'][0]['when']['all'][0] = ['card.type' => ['types' => 'epic']];

            return $t;
        }, 'rules[0] (start) when.all[0]: card.type: parameter "types" must be a non-empty list of non-empty strings'];
        yield 'rule reading the children of one type in a list without children' => [static function (array $t): array {
            $t['rules'][0]['when'] = ['all' => [['card.type' => ['types' => ['epic', 'bug']]], ['card.children.finished' => []]]];

            return $t;
        }, 'rules[0] (start): the type "bug" may not have children, but the rule reads them'];
        yield 'rule reading the children of a type without children' => [static function (array $t): array {
            $t['rules'][0]['when'] = ['all' => [['card.type' => ['types' => ['bug']]], ['card.children.finished' => []]]];

            return $t;
        }, 'rules[0] (start): the type "bug" may not have children, but the rule reads them'];
        yield 'nested all reading the children of a type without children' => [static function (array $t): array {
            $t['rules'][1]['when']['any'][] = ['all' => [['card.children.finished' => []], ['card.type' => ['types' => ['feature']]]]];

            return $t;
        }, 'rules[1] (to-review): the type "feature" may not have children, but the rule reads them'];
        yield 'all inside all reading the children of a type without children' => [static function (array $t): array {
            $t['rules'][0]['when'] = ['all' => [['card.type' => ['types' => ['bug']]], ['all' => [['card.children.finished' => []]]]]];

            return $t;
        }, 'rules[0] (start): the type "bug" may not have children, but the rule reads them'];
        yield 'any branch reading the children of the type around it' => [static function (array $t): array {
            $t['rules'][0]['when'] = ['all' => [['card.type' => ['types' => ['bug']]], ['any' => [['card.children.finished' => []], ['pr.open' => []]]]]];

            return $t;
        }, 'rules[0] (start): the type "bug" may not have children, but the rule reads them'];
        yield 'any branch naming a type that the list around it reads the children of' => [static function (array $t): array {
            $t['rules'][0]['when'] = ['all' => [['card.children.finished' => []], ['any' => [['card.type' => ['types' => ['bug']]], ['pr.open' => []]]]]];

            return $t;
        }, 'rules[0] (start): the type "bug" may not have children, but the rule reads them'];
        yield 'two sibling any lists, one naming the type and one reading the children' => [static function (array $t): array {
            $t['rules'][0]['when'] = ['all' => [
                ['any' => [['card.type' => ['types' => ['bug']]], ['pr.open' => []]]],
                ['any' => [['card.children.finished' => []], ['pr.open' => []]]],
            ]];

            return $t;
        }, 'rules[0] (start): the type "bug" may not have children, but the rule reads them'];
        yield 'negated child read beside a type without children' => [static function (array $t): array {
            $t['rules'][0]['when'] = ['all' => [['card.type' => ['types' => ['bug']]], ['not' => ['card.children.finished' => []]]]];

            return $t;
        }, 'rules[0] (start): the type "bug" may not have children, but the rule reads them'];
        yield 'pause until reading the children of a type without children' => [static function (array $t): array {
            $t['rules'][3]['when'] = ['card.type' => ['types' => ['bug']]];
            $t['rules'][3]['then']['pause']['until'] = ['card.children.finished' => []];

            return $t;
        }, 'rules[3] (wait): the type "bug" may not have children, but the rule reads them'];
        yield 'when reading the children of a type without children beside an until for another type' => [static function (array $t): array {
            $t['rules'][3]['when'] = ['all' => [['card.type' => ['types' => ['bug']]], ['card.children.finished' => []]]];
            $t['rules'][3]['then']['pause']['until'] = ['card.type' => ['types' => ['epic']]];

            return $t;
        }, 'rules[3] (wait): the type "bug" may not have children, but the rule reads them'];
        yield 'evaluate of the children of a type without children' => [static function (array $t): array {
            $t['rules'][4]['when'] = ['card.type' => ['types' => ['bug']]];
            $t['rules'][4]['then'] = ['evaluate' => ['cards' => 'children']];

            return $t;
        }, 'rules[4] (done): the type "bug" may not have children, but the rule reads them'];

        yield 'duplicate slot key' => [static function (array $t): array {
            $t['slots'][] = ['key' => 'build', 'label' => 'workflow.slot.build'];

            return $t;
        }, 'slots[2]: duplicate slot key "build"'];
        yield 'slot key with an at sign' => [static function (array $t): array {
            $t['slots'][] = ['key' => '@done', 'label' => 'workflow.slot.done'];

            return $t;
        }, 'slots[2]: slot key "@done" must not start with "@"'];
        yield 'wildcard slot key' => [static function (array $t): array {
            $t['slots'][] = ['key' => '*', 'label' => 'workflow.slot.any'];

            return $t;
        }, 'slots[2]: slot key "*" is reserved'];
        yield 'action with two keys' => [static function (array $t): array {
            $t['rules'][1]['then'] = ['move' => ['to' => 'review'], 'release' => ['reason' => 'busy']];

            return $t;
        }, 'rules[1] (to-review) then: an action must have exactly one key'];
        yield 'tone that is not a string' => [static function (array $t): array {
            $t['slots'][0]['tone'] = 12;

            return $t;
        }, 'slots[0] tone: must be one of '.self::TONES];
        yield 'tone that is unknown' => [static function (array $t): array {
            $t['slots'][0]['tone'] = 'mauve';

            return $t;
        }, 'slots[0] tone: must be one of '.self::TONES];
        yield 'stage work that is not a string' => [static function (array $t): array {
            $t['slots'][0]['stageWork'] = 12;

            return $t;
        }, 'slots[0] stageWork: must be a work request kind'];
        yield 'stage work that is not a kind' => [static function (array $t): array {
            $t['slots'][0]['stageWork'] = 'Not A Kind';

            return $t;
        }, 'slots[0] stageWork: must be a work request kind'];
        yield 'stage work that no rule of the slot requests' => [static function (array $t): array {
            $t['slots'][0]['stageWork'] = 'deploy';

            return $t;
        }, 'slots: stageWork "deploy" of slot "build" is the kind of no rule of that slot'];
        yield 'stage work that only a rule of another slot requests' => [static function (array $t): array {
            $t['slots'][1]['stageWork'] = 'implement';

            return $t;
        }, 'slots: stageWork "implement" of slot "review" is the kind of no rule of that slot'];
        yield 'slot with no label' => [static function (array $t): array {
            $t['slots'][] = ['key' => 'ship'];

            return $t;
        }, 'slots[2]: must be a map with a string "key" and a string "label"'];

        yield 'duplicate rule id' => [static function (array $t): array {
            $t['rules'][4]['id'] = 'start';

            return $t;
        }, 'rules[4] (start): duplicate rule id "start"'];

        yield 'unknown rule key' => [static function (array $t): array {
            $t['rules'][0]['solt'] = 'build';

            return $t;
        }, 'rules[0] (start): unknown key "solt"'];
        yield 'rule with no when' => [static function (array $t): array {
            unset($t['rules'][0]['when']);

            return $t;
        }, 'rules[0] (start) when: is missing'];

        yield 'unknown rule slot' => [static function (array $t): array {
            $t['rules'][0]['slot'] = 'shipping';

            return $t;
        }, 'rules[0] (start) slot: unknown slot "shipping"'];
        yield 'unknown move target' => [static function (array $t): array {
            $t['rules'][1]['then']['move']['to'] = 'shipping';

            return $t;
        }, 'rules[1] (to-review) then.move.to: unknown slot "shipping"'];
        yield 'unknown move source' => [static function (array $t): array {
            $t['rules'][1]['then']['move']['from'] = 'shipping';

            return $t;
        }, 'rules[1] (to-review) then.move.from: unknown slot "shipping"'];
        yield 'unknown manual move end' => [static function (array $t): array {
            $t['manualMoves'][0]['from'] = 'shipping';

            return $t;
        }, 'manualMoves[0].from: unknown slot "shipping"'];
        yield 'unknown manual move actor' => [static function (array $t): array {
            $t['manualMoves'][2]['by'] = 'anyone';

            return $t;
        }, 'manualMoves[2].by: must be one of parent-run'];
        yield 'wildcard rule slot' => [static function (array $t): array {
            $t['rules'][0]['slot'] = '*';

            return $t;
        }, 'rules[0] (start) slot: unknown slot "*"'];

        yield 'node with two keys' => [static function (array $t): array {
            $t['rules'][2]['when'] = ['pr.open' => [], 'card.blocker.open' => []];

            return $t;
        }, 'rules[2] (merge) when: a node must have exactly one key'];
        yield 'node with no key' => [static function (array $t): array {
            $t['rules'][0]['when']['all'][0] = [];

            return $t;
        }, 'rules[0] (start) when.all[0]: a node must have exactly one key'];
        yield 'empty any' => [static function (array $t): array {
            $t['rules'][1]['when'] = ['any' => []];

            return $t;
        }, 'rules[1] (to-review) when.any: must be a non-empty list'];

        yield 'unknown condition' => [static function (array $t): array {
            $t['rules'][2]['when'] = ['pr.foo' => []];

            return $t;
        }, 'rules[2] (merge) when: unknown condition "pr.foo"'];
        yield 'old condition key' => [static function (array $t): array {
            $t['rules'][2]['when'] = ['card.is_child' => []];

            return $t;
        }, 'rules[2] (merge) when: unknown condition "card.is_child"'];

        yield 'missing parameter' => [static function (array $t): array {
            $t['rules'][2]['when'] = ['pr.approval_covers_head' => []];

            return $t;
        }, 'rules[2] (merge) when: pr.approval_covers_head: missing parameter "min"'];
        yield 'unknown parameter' => [static function (array $t): array {
            $t['rules'][0]['when']['all'][1]['not'] = ['card.blocker.open' => ['strict' => true]];

            return $t;
        }, 'rules[0] (start) when.all[1].not: card.blocker.open: unknown parameter "strict"'];
        yield 'wrongly typed parameter' => [static function (array $t): array {
            $t['rules'][2]['when'] = ['pr.approval_covers_head' => ['min' => '1']];

            return $t;
        }, 'rules[2] (merge) when: pr.approval_covers_head: parameter "min" must be a positive integer'];
        yield 'usage above over its maximum' => [static function (array $t): array {
            $t['rules'][2]['when'] = ['bridge.account.usage' => ['account' => 'mac/work', 'window' => 'week', 'above' => 101]];

            return $t;
        }, 'rules[2] (merge) when: bridge.account.usage: parameter "above" must be an integer from 1 to 100'];
        yield 'usage above under its minimum' => [static function (array $t): array {
            $t['rules'][2]['when'] = ['bridge.account.usage' => ['account' => 'mac/work', 'window' => 'week', 'above' => 0]];

            return $t;
        }, 'rules[2] (merge) when: bridge.account.usage: parameter "above" must be an integer from 1 to 100'];
        yield 'usage account without a slash' => [static function (array $t): array {
            $t['rules'][2]['when'] = ['bridge.account.usage' => ['account' => 'work', 'window' => 'week', 'above' => 80]];

            return $t;
        }, 'rules[2] (merge) when: bridge.account.usage: parameter "account" must match <bridge name>/<account>'];
        yield 'usage window with a bad key' => [static function (array $t): array {
            $t['rules'][2]['when'] = ['bridge.account.usage' => ['account' => 'mac/work', 'window' => 'Week', 'above' => 80]];

            return $t;
        }, 'rules[2] (merge) when: bridge.account.usage: parameter "window" must match a window key such as session, week or 3d'];
        yield 'work active with an origin that is not rule' => [static function (array $t): array {
            $t['rules'][2]['when'] = ['card.run.work_active' => ['origin' => 'person']];

            return $t;
        }, 'rules[2] (merge) when: card.run.work_active: parameter "origin" must be one of: rule'];
        yield 'work active with an origin that is not a string' => [static function (array $t): array {
            $t['rules'][2]['when'] = ['card.run.work_active' => ['origin' => 3]];

            return $t;
        }, 'rules[2] (merge) when: card.run.work_active: parameter "origin" must be a non-empty string'];
        yield 'wrongly typed boolean parameter' => [static function (array $t): array {
            $t['rules'][2]['when'] = ['card.inbox.open_question' => ['blocking' => 'yes']];

            return $t;
        }, 'rules[2] (merge) when: card.inbox.open_question: parameter "blocking" must be true or false'];
        yield 'zero integer parameter' => [static function (array $t): array {
            $t['rules'][2]['when'] = ['pr.approval_covers_head' => ['min' => 0]];

            return $t;
        }, 'rules[2] (merge) when: pr.approval_covers_head: parameter "min" must be a positive integer'];
        yield 'negative integer parameter' => [static function (array $t): array {
            $t['rules'][2]['when'] = ['pr.approval_covers_head' => ['min' => -5]];

            return $t;
        }, 'rules[2] (merge) when: pr.approval_covers_head: parameter "min" must be a positive integer'];
        yield 'slot parameter naming no slot' => [static function (array $t): array {
            $t['rules'][1]['when']['any'][1] = ['card.in_slot' => ['slot' => 'shipping']];

            return $t;
        }, 'rules[1] (to-review) when.any[1]: card.in_slot: unknown slot "shipping"'];

        yield 'unknown action type' => [static function (array $t): array {
            $t['rules'][1]['then'] = ['jump' => ['to' => 'review']];

            return $t;
        }, 'rules[1] (to-review) then: unknown action "jump"'];
        yield 'missing action parameter' => [static function (array $t): array {
            $t['rules'][0]['then'] = ['request' => ['capability' => 'interactive']];

            return $t;
        }, 'rules[0] (start) then.request: missing parameter "kind"'];
        yield 'wrongly typed action parameter' => [static function (array $t): array {
            $t['rules'][0]['then']['request']['limit'] = 'three';

            return $t;
        }, 'rules[0] (start) then.request: parameter "limit" must be a positive integer'];
        yield 'refill without a limit' => [static function (array $t): array {
            unset($t['rules'][0]['then']['request']['limit']);
            $t['rules'][0]['then']['request']['refill'] = ['pr.checks_passed' => []];

            return $t;
        }, 'rules[0] (start) then.request: parameter "refill" needs a "limit"'];
        yield 'cancel only without a cancel when' => [static function (array $t): array {
            $t['rules'][0]['then']['request']['cancelOnly'] = 'open';

            return $t;
        }, 'rules[0] (start) then.request: parameter "cancelOnly" needs a "cancelWhen"'];
        yield 'cancel only with another value' => [static function (array $t): array {
            $t['rules'][0]['then']['request']['cancelWhen'] = ['pr.open' => []];
            $t['rules'][0]['then']['request']['cancelOnly'] = 'claimed';

            return $t;
        }, 'rules[0] (start) then.request: parameter "cancelOnly" must be one of open'];
        yield 'zero request limit' => [static function (array $t): array {
            $t['rules'][0]['then']['request']['limit'] = 0;

            return $t;
        }, 'rules[0] (start) then.request: parameter "limit" must be a positive integer'];
        yield 'unknown forge write' => [static function (array $t): array {
            $t['rules'][2]['then']['forge-write']['write'] = 'squash';

            return $t;
        }, 'rules[2] (merge) then.forge-write: parameter "write" must be one of merge, update-branch, change-base, comment, draft, ready, close, open-epic, post-review, site-review-check, agent-review-check, review-ready, merge-main-into-epic, approval-check'];
        yield 'merge with no fallback' => [static function (array $t): array {
            unset($t['rules'][2]['then']['forge-write']['fallback']);

            return $t;
        }, 'rules[2] (merge) then.forge-write: missing parameter "fallback"'];
        yield 'merge with no methods' => [static function (array $t): array {
            unset($t['rules'][2]['then']['forge-write']['methods']);

            return $t;
        }, 'rules[2] (merge) then.forge-write: missing parameter "methods"'];
        yield 'merge with an unknown method' => [static function (array $t): array {
            $t['rules'][2]['then']['forge-write']['methods'] = ['squash', 'fast-forward'];

            return $t;
        }, 'rules[2] (merge) then.forge-write: parameter "methods" does not take "fast-forward", only merge, squash, rebase'];
        yield 'merge with an empty methods list' => [static function (array $t): array {
            $t['rules'][2]['then']['forge-write']['methods'] = [];

            return $t;
        }, 'rules[2] (merge) then.forge-write: parameter "methods" must be a non-empty list of non-empty strings'];
        yield 'the removed work timeout behaviour' => [static function (array $t): array {
            $t['rules'][0]['then']['request']['onTimeout'] = 'expire';

            return $t;
        }, 'rules[0] (start) then.request: unknown parameter "onTimeout"'];
        yield 'a document that is not a map' => [static function (array $t): array {
            $t['rules'][0]['then']['request']['document'] = 'design';

            return $t;
        }, 'rules[0] (start) then.request: parameter "document" must be a map with the key tag, and optionally status'];
        yield 'a document with no tag' => [static function (array $t): array {
            $t['rules'][0]['then']['request']['document'] = [];

            return $t;
        }, 'rules[0] (start) then.request: parameter "document" must be a map with the key tag, and optionally status'];
        yield 'a document with an unknown key' => [static function (array $t): array {
            $t['rules'][0]['then']['request']['document'] = ['tag' => 'design', 'name' => 'Design'];

            return $t;
        }, 'rules[0] (start) then.request: parameter "document" must be a map with the key tag, and optionally status'];
        yield 'a document status that is not a status' => [static function (array $t): array {
            $t['rules'][0]['then']['request']['document'] = ['tag' => 'design', 'status' => 'rejected'];

            return $t;
        }, 'rules[0] (start) then.request.document: parameter "status" must be one of in-review, approved, changes-requested, draft'];
        yield 'a document tag that is not a string' => [static function (array $t): array {
            $t['rules'][0]['then']['request']['document'] = ['tag' => 5];

            return $t;
        }, 'rules[0] (start) then.request.document: parameter "tag" must be a non-empty string'];
        yield 'a document on another action' => [static function (array $t): array {
            $t['rules'][2]['then']['forge-write']['document'] = ['tag' => 'design'];

            return $t;
        }, 'rules[2] (merge) then.forge-write: unknown parameter "document"'];
        yield 'an empty checks list' => [static function (array $t): array {
            $t['rules'][0]['then']['request']['checks'] = [];

            return $t;
        }, 'rules[0] (start) then.request: parameter "checks" must be a non-empty list of non-empty strings'];
        yield 'checks as a string' => [static function (array $t): array {
            $t['rules'][0]['then']['request']['checks'] = 'A worktree';

            return $t;
        }, 'rules[0] (start) then.request: parameter "checks" must be a non-empty list of non-empty strings'];
        yield 'checks as a map' => [static function (array $t): array {
            $t['rules'][0]['then']['request']['checks'] = ['worktree' => 'A worktree'];

            return $t;
        }, 'rules[0] (start) then.request: parameter "checks" must be a non-empty list of non-empty strings'];
        yield 'a checks entry that is empty' => [static function (array $t): array {
            $t['rules'][0]['then']['request']['checks'] = ['A worktree', ''];

            return $t;
        }, 'rules[0] (start) then.request: parameter "checks" must be a non-empty list of non-empty strings'];
        yield 'a checks entry that is not a string' => [static function (array $t): array {
            $t['rules'][0]['then']['request']['checks'] = ['A worktree', 3];

            return $t;
        }, 'rules[0] (start) then.request: parameter "checks" must be a non-empty list of non-empty strings'];
        yield 'checks on another action' => [static function (array $t): array {
            $t['rules'][1]['then']['move']['checks'] = ['A worktree'];

            return $t;
        }, 'rules[1] (to-review) then.move: unknown parameter "checks"'];
        yield 'until outside a pause' => [static function (array $t): array {
            $t['rules'][4]['then']['release']['until'] = ['pr.open' => []];

            return $t;
        }, 'rules[4] (done) then.release: unknown parameter "until"'];

        yield 'document status outside the statuses' => [static function (array $t): array {
            $t['rules'][2]['when'] = ['card.document.linked' => ['tag' => 'design', 'status' => 'rejected']];

            return $t;
        }, 'rules[2] (merge) when: card.document.linked: parameter "status" must be one of: in-review, approved, changes-requested, draft'];
        yield 'evaluate of other cards' => [static function (array $t): array {
            $t['rules'][4]['then'] = ['evaluate' => ['cards' => 'siblings']];

            return $t;
        }, 'rules[4] (done) then.evaluate: parameter "cards" must be one of children'];
        yield 'evaluate with no cards' => [static function (array $t): array {
            $t['rules'][4]['then'] = ['evaluate' => []];

            return $t;
        }, 'rules[4] (done) then.evaluate: missing parameter "cards"'];
        yield 'pause with no until' => [static function (array $t): array {
            unset($t['rules'][3]['then']['pause']['until']);

            return $t;
        }, 'rules[3] (wait) then.pause: a pause must carry an "until" expression'];

        $ask = static fn (callable $mutate): \Closure => static function (array $t) use ($mutate): array {
            $t['rules'][] = self::askRule();
            $mutate($t['rules'][5]['then']['ask']);

            return $t;
        };
        yield 'ask nesting an ask' => [$ask(static function (array &$ask): void {
            $ask['options'][0]['then'] = [['ask' => ['question' => 'q', 'options' => [['label' => 'l', 'then' => [['detach' => []]]]]]]];
        }), 'rules[5] (unplanned) then.ask.options[0].then[0]: the action "ask" is not allowed inside an ask option'];
        yield 'ask nesting a request' => [$ask(static function (array &$ask): void {
            $ask['options'][1]['then'] = [['request' => ['kind' => 'implement']]];
        }), 'rules[5] (unplanned) then.ask.options[1].then[0]: the action "request" is not allowed inside an ask option'];
        yield 'ask nesting an unknown action' => [$ask(static function (array &$ask): void {
            $ask['options'][1]['then'] = [['jump' => []]];
        }), 'rules[5] (unplanned) then.ask.options[1].then[0]: unknown action "jump"'];
        yield 'ask nesting a move to an unknown slot' => [$ask(static function (array &$ask): void {
            $ask['options'][1]['then'] = [['move' => ['to' => 'nowhere']]];
        }), 'rules[5] (unplanned) then.ask.options[1].then[0].move.to: unknown slot "nowhere"'];
        yield 'ask with no question' => [$ask(static function (array &$ask): void {
            unset($ask['question']);
        }), 'rules[5] (unplanned) then.ask: missing parameter "question"'];
        yield 'ask with no options' => [$ask(static function (array &$ask): void {
            unset($ask['options']);
        }), 'rules[5] (unplanned) then.ask: missing parameter "options"'];
        yield 'ask with an empty options list' => [$ask(static function (array &$ask): void {
            $ask['options'] = [];
        }), 'rules[5] (unplanned) then.ask: parameter "options" must be a non-empty list of maps with a "label" and a "then" list'];
        yield 'ask option with no label' => [$ask(static function (array &$ask): void {
            unset($ask['options'][0]['label']);
        }), 'rules[5] (unplanned) then.ask.options[0]: must be a map with a non-empty string "label" and a non-empty "then" list'];
        yield 'ask option with an empty then' => [$ask(static function (array &$ask): void {
            $ask['options'][0]['then'] = [];
        }), 'rules[5] (unplanned) then.ask.options[0]: must be a map with a non-empty string "label" and a non-empty "then" list'];
        yield 'ask option with a then that is a map' => [$ask(static function (array &$ask): void {
            $ask['options'][0]['then'] = ['detach' => []];
        }), 'rules[5] (unplanned) then.ask.options[0]: must be a map with a non-empty string "label" and a non-empty "then" list'];
        yield 'ask option with an unknown key' => [$ask(static function (array &$ask): void {
            $ask['options'][0]['when'] = [];
        }), 'rules[5] (unplanned) then.ask.options[0]: unknown key "when"'];
        yield 'ask with an unknown parameter' => [$ask(static function (array &$ask): void {
            $ask['title'] = 'x';
        }), 'rules[5] (unplanned) then.ask: unknown parameter "title"'];
        yield 'link-document from the card' => [static function (array $t): array {
            $t['rules'][] = ['id' => 'link', 'when' => ['pr.open' => []], 'then' => ['link-document' => ['from' => 'card', 'tag' => 'tech-design']]];

            return $t;
        }, 'rules[5] (link) then.link-document: parameter "from" must be parent'];
        yield 'link-document with no tag' => [static function (array $t): array {
            $t['rules'][] = ['id' => 'link', 'when' => ['pr.open' => []], 'then' => ['link-document' => ['from' => 'parent']]];

            return $t;
        }, 'rules[5] (link) then.link-document: missing parameter "tag"'];
        yield 'detach with a parameter' => [static function (array $t): array {
            $t['rules'][] = ['id' => 'detach', 'when' => ['pr.open' => []], 'then' => ['detach' => ['from' => 'parent']]];

            return $t;
        }, 'rules[5] (detach) then.detach: unknown parameter "from"'];
    }

    /** @return array<string, mixed> */
    private static function askRule(): array
    {
        return [
            'id' => 'unplanned',
            'slot' => '@backlog',
            'when' => ['pr.open' => []],
            'then' => ['ask' => [
                'question' => 'workflow.ask.unplanned_child',
                'options' => [
                    ['label' => 'workflow.ask.unplanned_child.link', 'then' => [['link-document' => ['from' => 'parent', 'tag' => 'tech-design']]]],
                    ['label' => 'workflow.ask.unplanned_child.design', 'then' => [['move' => ['to' => 'build']]]],
                    ['label' => 'workflow.ask.unplanned_child.detach', 'then' => [['detach' => []], ['move' => ['to' => '@backlog']]]],
                ],
            ]],
        ];
    }

    public function test_a_single_action_parses_as_a_list_of_one_and_a_list_of_one_parses_the_same(): void
    {
        $single = self::valid();
        $listed = self::valid();
        $listed['rules'][1]['then'] = [$listed['rules'][1]['then']];

        $rule = $this->parser->parse($single)->rules[1];
        $listedRule = $this->parser->parse($listed)->rules[1];

        self::assertCount(1, $rule->then);
        self::assertEquals($rule->then, $listedRule->then);
        self::assertSame($rule->lead, $rule->then[0]);
    }

    public function test_a_rule_runs_a_list_of_actions_in_order(): void
    {
        $template = self::valid();
        $template['rules'][1]['then'] = [
            ['stop-work' => []],
            ['request-changes' => ['tags' => ['design'], 'note' => 'workflow.note']],
            ['move' => ['to' => 'review']],
        ];

        $rule = $this->parser->parse($template)->rules[1];

        self::assertSame(['stop-work', 'request-changes', 'move'], array_map(static fn ($call): string => $call->key, $rule->then));
        self::assertSame('stop-work', $rule->lead->key);
        self::assertTrue($rule->endsPass());
    }

    /** @param list<array<string, mixed>> $then */
    #[DataProvider('invalidActionLists')]
    public function test_a_list_of_actions_is_refused_when_an_action_needs_bookkeeping_or_the_list_is_empty(array $then, string $expected): void
    {
        $template = self::valid();
        $template['rules'][1]['then'] = $then;

        try {
            $this->parser->parse($template);
            self::fail('The template should be refused.');
        } catch (InvalidTemplate $e) {
            self::assertContains($expected, $e->errors);
        }
    }

    /** @return iterable<string, array{list<array<string, mixed>>, string}> */
    public static function invalidActionLists(): iterable
    {
        yield 'a request beside a move' => [
            [['request' => ['kind' => 'implement']], ['move' => ['to' => 'review']]],
            'rules[1] (to-review) then[0]: the action "request" is not allowed in a rule with several actions',
        ];
        yield 'a pause beside a move' => [
            [['move' => ['to' => 'review']], ['pause' => ['reason' => 'busy', 'until' => ['pr.open' => []]]]],
            'rules[1] (to-review) then[1]: the action "pause" is not allowed in a rule with several actions',
        ];
        yield 'an action after a move' => [
            [['move' => ['to' => 'review']], ['stop-work' => []]],
            'rules[1] (to-review) then[0]: the action "move" ends the pass, so the action "stop-work" after it would never run',
        ];
        yield 'an empty list' => [[], 'rules[1] (to-review) then: a list of actions must not be empty'];
        yield 'an error in the second action' => [
            [['move' => ['to' => 'review']], ['move' => ['to' => 'nowhere']]],
            'rules[1] (to-review) then[1].move.to: unknown slot "nowhere"',
        ];
    }

    public function test_an_ask_reads_its_question_and_its_options_with_their_nested_actions(): void
    {
        $template = self::valid();
        $template['rules'][] = self::askRule();

        $then = $this->parser->parse($template)->rules[5]->lead;

        self::assertSame('ask', $then->key);
        self::assertSame(['question' => 'workflow.ask.unplanned_child'], $then->params);
        self::assertEquals([
            new AskOption('workflow.ask.unplanned_child.link', [$this->actions->call('link-document', ['from' => 'parent', 'tag' => 'tech-design'])]),
            new AskOption('workflow.ask.unplanned_child.design', [$this->actions->call('move', ['to' => 'build'])]),
            new AskOption('workflow.ask.unplanned_child.detach', [$this->actions->call('detach', []), $this->actions->call('move', ['to' => '@backlog'])]),
        ], $then->options);
    }

    public function test_an_ask_template_round_trips_through_json(): void
    {
        $template = self::valid();
        $template['rules'][] = self::askRule();
        $decoded = json_decode(json_encode($template, \JSON_THROW_ON_ERROR), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        self::assertEquals($this->parser->parse($template), $this->parser->parseStored($decoded));
    }

    public function test_an_action_other_than_an_ask_has_no_options(): void
    {
        self::assertSame([], $this->parser->parse(self::valid())->rules[0]->lead->options);
    }

    public function test_a_template_declares_the_actions_of_each_child_choice(): void
    {
        $template = self::valid();
        $template['childChoices'] = [
            'inherit' => [['link-document' => ['from' => 'parent', 'tag' => 'tech-design']]],
            'own' => [['move' => ['to' => 'build']]],
        ];

        $parsed = $this->parser->parse($template);

        self::assertEquals([
            'inherit' => [$this->actions->call('link-document', ['from' => 'parent', 'tag' => 'tech-design'])],
            'own' => [$this->actions->call('move', ['to' => 'build'])],
        ], $parsed->childChoices);
        $decoded = json_decode(json_encode($template, \JSON_THROW_ON_ERROR), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertEquals($parsed, $this->parser->parseStored($decoded));
    }

    public function test_a_template_without_child_choices_declares_none(): void
    {
        self::assertSame([], $this->parser->parse(self::valid())->childChoices);
    }

    /** @param array<mixed> $choices */
    #[DataProvider('childChoiceRefusals')]
    public function test_it_refuses_an_invalid_child_choice(array $choices, string $message): void
    {
        $template = self::valid();
        $template['childChoices'] = $choices;

        try {
            $this->parser->parse($template);
            self::fail('The parser must refuse the template.');
        } catch (InvalidTemplate $e) {
            self::assertSame([$message], $e->errors);
        }
    }

    /** @return iterable<string, array{array<mixed>, string}> */
    public static function childChoiceRefusals(): iterable
    {
        yield 'an unknown choice' => [['both' => [['move' => ['to' => 'build']]]], 'childChoices: unknown choice "both", expected one of inherit, own'];
        yield 'an empty list' => [['own' => []], 'childChoices.own: must be a non-empty list of actions'];
        yield 'an action a choice may not run' => [['own' => [['detach' => []]]], 'childChoices.own[0]: the action "detach" is not allowed inside a child choice'];
        yield 'a choice that is not a map' => [['inherit'], 'childChoices: must be a map from a choice to a list of actions'];
    }

    /** @param \Closure(array<string, mixed>): array<string, mixed> $mutate */
    #[DataProvider('refusals')]
    public function test_it_refuses_an_invalid_template(\Closure $mutate, string $message): void
    {
        try {
            $this->parser->parse($mutate(self::valid()));
            self::fail('The parser must refuse the template.');
        } catch (InvalidTemplate $e) {
            self::assertSame([$message], $e->errors);
            self::assertStringContainsString($message, $e->getMessage());
        }
    }

    /** @param \Closure(array<string, mixed>): array<string, mixed> $mutate */
    #[DataProvider('refusals')]
    public function test_a_stored_copy_refuses_every_error_but_an_unknown_condition(\Closure $mutate, string $message): void
    {
        if (str_contains($message, 'unknown condition') || str_contains($message, 'unknown action')) {
            self::assertCount(\count($mutate(self::valid())['rules']), $this->parser->parseStored($mutate(self::valid()))->rules);

            return;
        }
        try {
            $this->parser->parseStored($mutate(self::valid()));
            self::fail('The parser must refuse the template.');
        } catch (InvalidTemplate $e) {
            self::assertSame([$message], $e->errors);
        }
    }

    public function test_a_stored_copy_keeps_an_unknown_condition_as_a_missing_condition_leaf(): void
    {
        $template = self::valid();
        $template['rules'][2]['when'] = ['not' => ['pr.foo' => ['min' => 1]]];
        $template['rules'][1]['when']['any'][0] = ['pr.bar' => 'not a map'];

        $rules = $this->parser->parseStored($template)->rules;

        $not = $rules[2]->when;
        self::assertInstanceOf(Not::class, $not);
        self::assertEquals(new MissingConditionLeaf('pr.foo', ['min' => 1]), $not->inner);
        $any = $rules[1]->when;
        self::assertInstanceOf(AnyOf::class, $any);
        self::assertEquals(new MissingConditionLeaf('pr.bar', 'not a map'), $any->children[0]);
    }

    public function test_a_stored_copy_keeps_a_rule_with_an_unknown_action_as_a_rule_that_never_fires(): void
    {
        $template = self::valid();
        $template['rules'][1]['then'] = ['jump' => ['to' => 'nowhere']];

        $rule = $this->parser->parseStored($template)->rules[1];

        self::assertSame(MissingAction::KEY, $rule->lead->key);
        self::assertSame('jump', $rule->lead->params['name']);
        self::assertInstanceOf(AllOf::class, $rule->when);
        self::assertEquals(new MissingActionLeaf('jump'), $rule->when->children[0]);
        self::assertFalse($rule->when->evaluate(FactsMother::facts()));
        self::assertEquals(new Unreadable(UnreadableKind::MissingAction, 'jump'), $rule->when->unreadable(FactsMother::facts()));
    }

    public function test_a_stored_copy_keeps_the_from_slot_of_an_unknown_action_when_the_template_has_it(): void
    {
        $template = self::valid();
        $template['rules'][1]['then'] = ['jump' => ['from' => 'review']];
        $template['rules'][2]['then'] = ['jump' => ['from' => 'nowhere']];
        $template['rules'][3]['then'] = ['jump' => ['from' => '@backlog']];

        $rules = $this->parser->parseStored($template)->rules;

        self::assertSame('review', $rules[1]->lead->from);
        self::assertNull($rules[2]->lead->from);
        self::assertSame('@backlog', $rules[3]->lead->from);
    }

    public function test_a_stored_copy_makes_a_rule_unknown_when_an_ask_option_names_an_unknown_action(): void
    {
        $template = self::valid();
        $template['rules'][] = self::askRule();
        $template['rules'][5]['then']['ask']['options'][1]['then'] = [['jump' => []]];

        $rule = $this->parser->parseStored($template)->rules[5];

        self::assertSame(MissingAction::KEY, $rule->lead->key);
        self::assertSame('jump', $rule->lead->params['name']);
        self::assertSame([], $rule->lead->options);
    }

    public function test_strict_mode_refuses_an_unknown_action_and_the_placeholder_value(): void
    {
        foreach (['jump', MissingAction::KEY] as $name) {
            $template = self::valid();
            $template['rules'][1]['then'] = [$name => []];
            try {
                $this->parser->parse($template);
                self::fail('The parser must refuse the template.');
            } catch (InvalidTemplate $e) {
                self::assertSame([\sprintf('rules[1] (to-review) then: unknown action "%s"', $name)], $e->errors);
            }
        }
    }

    public function test_it_reports_every_error_at_once(): void
    {
        $template = self::valid();
        $template['version'] = 'one';
        $template['rules'][2]['when'] = ['pr.foo' => []];

        try {
            $this->parser->parse($template);
            self::fail('The parser must refuse the template.');
        } catch (InvalidTemplate $e) {
            self::assertSame([
                'version: must be an integer',
                'rules[2] (merge) when: unknown condition "pr.foo"',
            ], $e->errors);
        }
    }

    public function test_app_rules_accept_a_request_with_a_prompt_in_the_backlog(): void
    {
        $rules = $this->parser->parseAppRules(['rules' => [
            ['id' => 'app-groom', 'slot' => '@backlog', 'when' => ['card.blocker.open' => []], 'then' => ['request' => ['kind' => 'groom', 'prompt' => 'groom-card']]],
            ['id' => 'app-done', 'slot' => '@terminal', 'when' => ['all' => []], 'then' => ['request' => ['kind' => 'teardown']]],
        ]]);

        self::assertSame(['app-groom', 'app-done'], array_map(static fn ($rule) => $rule->id, $rules));
        self::assertSame([RuleOrigin::App, RuleOrigin::App], array_map(static fn ($rule) => $rule->origin, $rules));
        self::assertSame('@backlog', $rules[0]->slot);
        self::assertSame(['kind' => 'groom', TemplateParser::PROMPT => 'groom-card'], $rules[0]->lead->params);
    }

    public function test_template_rules_have_the_template_origin(): void
    {
        foreach ($this->parser->parse(self::valid())->rules as $rule) {
            self::assertSame(RuleOrigin::Template, $rule->origin);
        }
    }

    /** @return iterable<string, array{array<mixed>, list<string>}> */
    public static function appRuleRefusals(): iterable
    {
        $rule = static fn (array $then, ?string $slot = null): array => ['rules' => [array_filter(['id' => 'app-rule', 'slot' => $slot, 'when' => ['all' => []], 'then' => $then], static fn ($v) => null !== $v)]];

        yield 'a slot key' => [$rule(['request' => ['kind' => 'groom']], 'next'), ['rules[0] (app-rule) slot: unknown slot "next"']];
        yield 'a prompt name with a capital' => [$rule(['request' => ['kind' => 'groom', 'prompt' => 'Groom']]), ['rules[0] (app-rule) then.request: parameter "prompt" must match [a-z][a-z0-9-], at most 40 characters']];
        yield 'a prompt name with a path' => [$rule(['request' => ['kind' => 'groom', 'prompt' => '../secret']]), ['rules[0] (app-rule) then.request: parameter "prompt" must match [a-z][a-z0-9-], at most 40 characters']];
        yield 'a prompt name too long' => [$rule(['request' => ['kind' => 'groom', 'prompt' => str_repeat('a', 41)]]), ['rules[0] (app-rule) then.request: parameter "prompt" must match [a-z][a-z0-9-], at most 40 characters']];
        yield 'a prompt on another action' => [$rule(['release' => ['reason' => 'x', 'prompt' => 'groom']]), ['rules[0] (app-rule) then.release: unknown parameter "prompt"']];
        yield 'a move to a slot' => [$rule(['move' => ['to' => 'next']]), ['rules[0] (app-rule) then.move.to: unknown slot "next"']];
        yield 'no rules key' => [[], ['rules: is missing']];
        yield 'rules not a list' => [['rules' => ['a' => 1]], ['rules: must be a list']];
        yield 'another top-level key' => [['rules' => [], 'slots' => []], ['slots: unknown key, app rules hold only "rules", "requests" and "proposalSlot"']];
        yield 'a proposal slot that is not a string' => [['rules' => [], 'proposalSlot' => 12], ['proposalSlot: must be a non-empty string']];
        yield 'an empty proposal slot' => [['rules' => [], 'proposalSlot' => ''], ['proposalSlot: must be a non-empty string']];
    }

    /**
     * @param array<mixed> $source
     * @param list<string> $errors
     */
    #[DataProvider('appRuleRefusals')]
    public function test_app_rules_refuse_an_invalid_source(array $source, array $errors): void
    {
        try {
            $this->parser->parseAppRules($source);
            self::fail('The parser must refuse the app rules.');
        } catch (InvalidTemplate $e) {
            self::assertSame($errors, $e->errors);
        }
    }

    public function test_a_template_refuses_a_prompt_with_no_file(): void
    {
        $template = self::valid();
        $template['rules'][0]['then']['request']['prompt'] = 'groom';

        try {
            $this->parser->parse($template);
            self::fail('A template rule must not name a prompt with no file.');
        } catch (InvalidTemplate $e) {
            self::assertSame(['rules (start): prompt "groom" has no file test/prompts/groom.md'], $e->errors);
        }
    }

    public function test_a_stored_copy_keeps_a_prompt_whose_file_is_gone(): void
    {
        $template = self::valid();
        $template['rules'][0]['then']['request']['prompt'] = 'groom';

        self::assertSame('groom', $this->parser->parseStored($template)->rules[0]->lead->params['prompt']);
    }

    public const string PROMPTS_FIXTURE = __DIR__.'/Fixtures/template-prompts';

    public const string MARKERS_FIXTURE = __DIR__.'/Fixtures/markers.yaml';

    public function test_it_reads_the_markers_of_a_template_in_order(): void
    {
        $template = $this->parser->parse(Yaml::parseFile(self::MARKERS_FIXTURE));

        self::assertSame(['has-pull-request', 'blocked', 'quiet', 'held'], array_map(static fn ($marker) => $marker->key, $template->markers));
        self::assertSame([MarkerKind::Waiting, MarkerKind::NeedsYou, MarkerKind::Stuck, MarkerKind::Chip], array_map(static fn ($marker) => $marker->kind, $template->markers));
        [$pullRequest, $blocked, $quiet] = $template->markers;
        self::assertSame('marker.test.has_pull_request', $pullRequest->label);
        self::assertSame('marker.test.has_pull_request.remedy', $pullRequest->remedy);
        self::assertSame(['build', 'review'], $pullRequest->slots);
        self::assertFalse($pullRequest->fallback);
        self::assertSame(MarkerLink::Agents, $pullRequest->link);
        self::assertNull($blocked->link);
        self::assertInstanceOf(ConditionLeaf::class, $pullRequest->when);
        self::assertNull($blocked->remedy);
        self::assertSame([], $blocked->slots);
        self::assertTrue($quiet->fallback);
        self::assertInstanceOf(Not::class, $quiet->when);
    }

    public function test_a_template_without_markers_has_none_and_the_kinds_keep_their_precedence(): void
    {
        self::assertSame([], $this->parser->parse(self::valid())->markers);
        self::assertSame(['stuck', 'needs-you', 'working', 'waiting', 'chip'], array_column(MarkerKind::cases(), 'value'));
    }

    public function test_a_template_lists_the_markers_that_reach_the_inbox_in_the_order_it_gives(): void
    {
        $markers = [
            ['key' => 'a', 'kind' => 'stuck', 'label' => 'marker.a', 'when' => ['pr.open' => []]],
            ['key' => 'b', 'kind' => 'chip', 'label' => 'marker.b', 'when' => ['card.paused' => []]],
        ];

        self::assertSame([], $this->parser->parse(self::valid() + ['markers' => $markers])->inbox);
        self::assertSame(['b', 'a'], $this->parser->parse(self::valid() + ['markers' => $markers, 'inbox' => ['b', 'a']])->inbox);
        self::assertSame(['b'], $this->parser->parseStored(self::valid() + ['markers' => $markers, 'inbox' => ['b']])->inbox);
    }

    /** @param array<string, mixed> $when */
    #[DataProvider('cardSubjectConditions')]
    public function test_a_listed_marker_with_the_card_subject_is_accepted(array $when): void
    {
        $markers = [
            ['key' => 'a', 'kind' => 'stuck', 'label' => 'marker.a', 'when' => ['pr.open' => []]],
            ['key' => 'b', 'kind' => 'stuck', 'label' => 'marker.b', 'when' => $when],
        ];
        $source = self::valid() + ['markers' => $markers, 'inbox' => ['b', 'a']];

        self::assertSame(['b', 'a'], $this->parser->parse($source)->inbox);
        self::assertSame(['b', 'a'], $this->parser->parseStored($source)->inbox);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function cardSubjectConditions(): iterable
    {
        yield 'no leaf names a thing' => [['card.type' => ['types' => ['bug']]]];
        yield 'no leaf at all' => [['all' => []]];
        yield 'two subject types' => [['any' => [['pr.open' => []], ['card.paused' => []]]]];
    }

    public function test_a_listed_marker_whose_named_fact_names_a_pull_request_is_accepted(): void
    {
        $source = self::valid() + [
            'facts' => ['open-and-bug' => ['all' => [['pr.open' => []], ['card.type' => ['types' => ['bug']]]]]],
            'markers' => [['key' => 'a', 'kind' => 'stuck', 'label' => 'marker.a', 'when' => ['fact.open-and-bug' => []]]],
            'inbox' => ['a'],
        ];

        self::assertSame(['a'], $this->parser->parse($source)->inbox);
    }

    public function test_a_listed_marker_whose_named_fact_names_no_thing_is_accepted(): void
    {
        $source = self::valid() + [
            'facts' => ['bug' => ['card.type' => ['types' => ['bug']]]],
            'markers' => [['key' => 'a', 'kind' => 'stuck', 'label' => 'marker.a', 'when' => ['fact.bug' => []]]],
            'inbox' => ['a'],
        ];

        self::assertSame(['a'], $this->parser->parse($source)->inbox);
    }

    #[DataProvider('invalidInboxLists')]
    public function test_an_inbox_list_that_names_no_marker_or_repeats_one_is_refused(mixed $inbox, string $error): void
    {
        $markers = [['key' => 'a', 'kind' => 'stuck', 'label' => 'marker.a', 'when' => ['pr.open' => []]]];

        try {
            $this->parser->parse(self::valid() + ['markers' => $markers, 'inbox' => $inbox]);
            self::fail('The template should be refused.');
        } catch (InvalidTemplate $e) {
            self::assertContains($error, $e->errors);
        }
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function invalidInboxLists(): iterable
    {
        yield 'a map' => [['a' => true], 'inbox: must be a list of marker keys'];
        yield 'a string' => ['a', 'inbox: must be a list of marker keys'];
        yield 'an unknown key' => [['nope'], 'inbox: unknown marker "nope"'];
        yield 'a number' => [[3], 'inbox: unknown marker "int"'];
        yield 'a repeated key' => [['a', 'a'], 'inbox: duplicate marker "a"'];
    }

    /** @param array<string, mixed> $marker */
    #[DataProvider('invalidMarkers')]
    public function test_a_marker_that_breaks_a_rule_is_refused(array $marker, string $error): void
    {
        $base = ['key' => 'm', 'kind' => 'stuck', 'label' => 'marker.m', 'when' => ['pr.open' => []]];

        try {
            $this->parser->parse(self::valid() + ['markers' => [array_replace($base, $marker), ['key' => 'm', 'kind' => 'chip', 'label' => 'marker.n', 'when' => ['all' => []]]]]);
            self::fail('The template should be refused.');
        } catch (InvalidTemplate $e) {
            self::assertContains($error, $e->errors);
        }
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidMarkers(): iterable
    {
        yield 'unknown kind' => [['kind' => 'loud'], 'markers[0] (m) kind: must be one of stuck, needs-you, working, waiting, chip'];
        yield 'unknown condition' => [['when' => ['card.nope' => []]], 'markers[0] (m) when: unknown condition "card.nope"'];
        yield 'unknown slot' => [['slots' => ['nowhere']], 'markers[0] (m) slots: unknown slot "nowhere"'];
        yield 'a column flag is no slot' => [['slots' => ['@terminal']], 'markers[0] (m) slots: unknown slot "@terminal"'];
        yield 'slots not a list' => [['slots' => 'build'], 'markers[0] (m) slots: must be a list of slot keys'];
        yield 'blank label' => [['label' => ''], 'markers[0] (m) label: must be a non-empty string'];
        yield 'blank remedy' => [['remedy' => ''], 'markers[0] (m) remedy: must be a non-empty string'];
        yield 'fallback not a boolean' => [['fallback' => 'yes'], 'markers[0] (m) fallback: must be a boolean'];
        yield 'unknown key' => [['colour' => 'red'], 'markers[0] (m): unknown key "colour"'];
        yield 'unknown link' => [['link' => 'settings'], 'markers[0] (m) link: must be one of agents, repositories'];
        yield 'link not a string' => [['link' => ['agents']], 'markers[0] (m) link: must be one of agents, repositories'];
    }

    public function test_a_duplicate_marker_key_is_refused(): void
    {
        $marker = ['key' => 'm', 'kind' => 'chip', 'label' => 'marker.m', 'when' => ['all' => []]];

        try {
            $this->parser->parse(self::valid() + ['markers' => [$marker, $marker]]);
            self::fail('The template should be refused.');
        } catch (InvalidTemplate $e) {
            self::assertSame(['markers[1] (m): duplicate marker key "m"'], $e->errors);
        }
    }

    public function test_markers_must_be_a_list_of_maps_with_a_condition(): void
    {
        foreach ([['markers' => 'all'], ['markers' => ['x']], ['markers' => [['key' => 'm', 'kind' => 'chip', 'label' => 'l']]]] as $extra) {
            try {
                $this->parser->parse(self::valid() + $extra);
                self::fail('The template should be refused.');
            } catch (InvalidTemplate $e) {
                self::assertNotSame([], $e->errors);
            }
        }
    }

    public function test_a_stored_copy_keeps_a_marker_whose_condition_no_longer_exists(): void
    {
        $source = self::valid() + ['markers' => [['key' => 'm', 'kind' => 'chip', 'label' => 'marker.m', 'when' => ['card.gone' => []]]]];

        $leaf = $this->parser->parseStored($source)->markers[0]->when;

        self::assertInstanceOf(MissingConditionLeaf::class, $leaf);
    }

    public function test_a_rule_refers_to_a_named_fact_and_a_fact_to_another_fact(): void
    {
        $template = self::valid();
        $template['facts'] = [
            'open-pr' => ['pr.open' => []],
            'ready' => ['all' => [['fact.open-pr' => []], ['not' => ['card.blocker.open' => []]]]],
        ];
        $template['rules'][1]['when'] = ['fact.ready' => []];

        $when = $this->parser->parse($template)->rules[1]->when;

        self::assertInstanceOf(NamedFact::class, $when);
        self::assertSame('ready', $when->name);
        self::assertInstanceOf(AllOf::class, $when->expression);
        $inner = $when->expression->children[0];
        self::assertInstanceOf(NamedFact::class, $inner);
        self::assertSame('open-pr', $inner->name);
        self::assertSame(['pr.open', 'card.blocker.open'], array_map(static fn (ConditionLeaf $leaf): string => $leaf->condition::key(), $when->leaves()));
    }

    public function test_a_named_fact_gives_the_rule_the_same_canonical_text_as_its_expression(): void
    {
        $inline = self::valid();
        $inline['rules'][1]['when'] = ['all' => [['pr.open' => []], ['not' => ['card.blocker.open' => []]]]];
        $named = self::valid();
        $named['facts'] = ['ready' => ['all' => [['pr.open' => []], ['not' => ['card.blocker.open' => []]]]]];
        $named['rules'][1]['when'] = ['fact.ready' => []];

        self::assertSame($this->parser->parse($inline)->rules[1]->when->canonical(), $this->parser->parse($named)->rules[1]->when->canonical());
    }

    public function test_a_marker_and_an_until_expression_refer_to_a_named_fact(): void
    {
        $template = self::valid();
        $template['facts'] = ['busy' => ['card.run.work_active' => []]];
        $template['rules'][3]['then'] = ['pause' => ['reason' => 'busy', 'until' => ['not' => ['fact.busy' => []]]]];
        $template['markers'] = [['key' => 'm', 'kind' => 'chip', 'label' => 'marker.m', 'when' => ['fact.busy' => []]]];

        $parsed = $this->parser->parse($template);

        self::assertInstanceOf(NamedFact::class, $parsed->markers[0]->when);
        $until = $parsed->rules[3]->lead->until;
        self::assertInstanceOf(Not::class, $until);
        self::assertInstanceOf(NamedFact::class, $until->inner);
    }

    public function test_a_named_fact_that_names_a_card_type_still_checks_the_children_rule(): void
    {
        $template = self::valid();
        $template['facts'] = ['bug' => ['card.type' => ['types' => ['bug']]]];
        $template['rules'][0]['when'] = ['all' => [['fact.bug' => []], ['card.children.finished' => []]]];

        try {
            $this->parser->parse($template);
            self::fail('The template should be refused.');
        } catch (InvalidTemplate $e) {
            self::assertSame(['rules[0] (start): the type "bug" may not have children, but the rule reads them'], $e->errors);
        }
    }

    /**
     * @param array<string, mixed> $facts
     * @param array<mixed>         $when
     * @param list<string>         $expected
     */
    #[DataProvider('invalidFacts')]
    public function test_a_fact_with_an_error_is_refused_with_its_path(array $facts, array $when, array $expected): void
    {
        $template = self::valid();
        $template['facts'] = $facts;
        $template['rules'][1]['when'] = $when;

        foreach ([true, false] as $stored) {
            try {
                $stored ? $this->parser->parseStored($template) : $this->parser->parse($template);
                self::fail('The template should be refused.');
            } catch (InvalidTemplate $e) {
                self::assertSame($expected, $e->errors);
            }
        }
    }

    /** @return iterable<string, array{array<mixed>, array<mixed>, list<string>}> */
    public static function invalidFacts(): iterable
    {
        yield 'a loop of two facts' => [
            ['a' => ['fact.b' => []], 'b' => ['fact.a' => []]],
            ['pr.open' => []],
            ['facts.a: loop a -> b -> a'],
        ];
        yield 'a fact that refers to itself' => [
            ['a' => ['all' => [['pr.open' => []], ['fact.a' => []]]]],
            ['fact.a' => []],
            ['facts.a: loop a -> a'],
        ];
        yield 'a loop below another fact' => [
            ['top' => ['fact.a' => []], 'a' => ['fact.b' => []], 'b' => ['fact.a' => []]],
            ['fact.top' => []],
            ['facts.a: loop a -> b -> a'],
        ];
        yield 'an unknown fact in a rule' => [
            ['a' => ['pr.open' => []]],
            ['fact.b' => []],
            ['rules[1] (to-review) when: unknown fact "b"'],
        ];
        yield 'an unknown fact in a fact' => [
            ['a' => ['not' => ['fact.b' => []]]],
            ['pr.open' => []],
            ['facts.a.not: unknown fact "b"'],
        ];
        yield 'parameters on a reference' => [
            ['a' => ['pr.open' => []]],
            ['fact.a' => ['min' => 1]],
            ['rules[1] (to-review) when: the fact "a" takes no parameters'],
        ];
        yield 'a missing parameter inside a fact' => [
            ['a' => ['pr.approval_covers_head' => []]],
            ['fact.a' => []],
            ['facts.a: pr.approval_covers_head: missing parameter "min"'],
        ];
        yield 'an unused fact with an error' => [
            ['unused' => ['pr.approval_covers_head' => []]],
            ['pr.open' => []],
            ['facts.unused: pr.approval_covers_head: missing parameter "min"'],
        ];
        yield 'a bad name' => [
            ['Bad_Name' => ['pr.open' => []]],
            ['pr.open' => []],
            ['facts.Bad_Name: the name must match [a-z][a-z0-9-]'],
        ];
    }

    public function test_facts_must_be_a_map(): void
    {
        $template = self::valid();
        $template['facts'] = [['pr.open' => []]];

        try {
            $this->parser->parse($template);
            self::fail('The template should be refused.');
        } catch (InvalidTemplate $e) {
            self::assertSame(['facts: must be a map from a name to an expression'], $e->errors);
        }
    }

    public function test_a_stored_copy_keeps_a_condition_of_a_fact_that_no_longer_exists_as_a_missing_condition(): void
    {
        $template = self::valid();
        $template['facts'] = ['gone' => ['all' => [['pr.open' => []], ['card.gone' => []]]]];
        $template['rules'][1]['when'] = ['fact.gone' => []];

        $when = $this->parser->parseStored($template)->rules[1]->when;

        self::assertInstanceOf(NamedFact::class, $when);
        self::assertSame(['card.gone'], $when->missingKeys());
    }

    public function test_the_app_rules_hold_no_named_facts(): void
    {
        try {
            $this->parser->parseAppRules(['rules' => [['id' => 'r', 'when' => ['fact.a' => []], 'then' => ['move' => ['to' => '@terminal']]]]]);
            self::fail('The rules should be refused.');
        } catch (InvalidTemplate $e) {
            self::assertSame(['rules[0] (r) when: unknown fact "a"'], $e->errors);
        }
    }
}
