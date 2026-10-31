<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Service;

use App\Module\Board\Entity\Card;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Workflow\WorkRequestContexts;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkerRunOutputChunk;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\Repository\BridgeRepository;
use App\Module\Bridge\Repository\CardHoldRepository;
use App\Module\Bridge\Repository\WorkerRunOutputChunkRepository;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Bridge\Service\BridgeLiveness;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Bridge\View\WorkerRunControls;
use App\Module\Inbox\Entity\InboxAction;
use App\Module\Inbox\Entity\InboxActionKind;
use App\Module\Inbox\Entity\InboxAsk;
use App\Module\Inbox\Entity\InboxAskItem;
use App\Module\Inbox\Entity\InboxAskOrigin;
use App\Module\Inbox\Entity\InboxCardWait;
use App\Module\Inbox\Entity\InboxCardWaitType;
use App\Module\Inbox\Entity\InboxCardWatch;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemCard;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Repository\InboxActionRepository;
use App\Module\Inbox\Repository\InboxAskRepository;
use App\Module\Inbox\Repository\InboxCardWatchRepository;
use App\Module\Inbox\Service\AskingBridge;
use App\Module\Inbox\Service\CardWaitText;
use App\Module\Inbox\Service\InboxReviewLookup;
use App\Module\Inbox\Service\ItemActions;
use App\Module\Inbox\Service\RunCommandResolver;
use App\Module\Inbox\Service\RunCommandRuns;
use App\Module\Inbox\Service\SessionBridges;
use App\Module\Inbox\Service\SessionPromptWriter;
use App\Module\Inbox\View\ItemAction;
use App\Module\Inbox\View\RunCommandOutcome;
use App\Module\Inbox\View\RunCommandRun;
use App\Module\Inbox\View\RunCommandRunState;
use App\Module\Inbox\View\RunCommandView;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\CardPauses;
use App\Module\Workflow\Service\TemplateStageWorks;
use App\Tests\Module\Inbox\InboxFixtures;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class ItemActionsRunCommandTest extends KernelTestCase
{
    use InboxFixtures;

    private EntityManagerInterface $em;
    private Project $project;
    private Card $card;
    private Bridge $bridge;
    private int $number = 0;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->project = $this->project($em, $this->owner($em, 'run-command-actions'), 'run-command-actions');
        $this->card = $this->card($em, $this->project, 7);
        $this->bridge = new Bridge($this->project->owner, Uuid::v4(), [(string) $this->project->id], 'b4e39aa7', new \DateTimeImmutable());
        $this->bridge->capabilities = [Bridge::CAPABILITY_COMMANDS, Bridge::CAPABILITY_WORK_REQUESTS, Bridge::CAPABILITY_RUN_COMMAND];
        $this->bridge->commandEntries = [self::entryRow()];
        $this->bridge->worktrees = [
            ['projectId' => (string) $this->project->id, 'branch' => 'main', 'path' => '/work/loupe'],
            ['projectId' => (string) $this->project->id, 'branch' => 'epic/42', 'path' => '/work/loupe/.worktrees/card-42'],
            ['projectId' => (string) $this->project->id, 'branch' => 'a-branch', 'path' => '/work/loupe/.worktrees/a'],
            ['projectId' => (string) Uuid::v7(), 'branch' => 'other-project', 'path' => '/work/elsewhere'],
        ];
        $em->persist($this->bridge);
        $em->flush();
    }

    public function test_a_todo_offers_run_a_command_with_everything_the_dialog_shows(): void
    {
        $todo = $this->todo();

        $action = $this->runCommandAction($todo);
        $view = $action->runCommand ?? throw new \LogicException('A run-command action carries its view.');

        self::assertNull($action->disabledReasonKey);
        self::assertTrue($action->default);
        self::assertSame('Export it.', $action->reason);
        self::assertSame('inbox.action.run-command.label', $action->labelKey);
        self::assertSame('design-export', $view->entry);
        self::assertSame('epic/42', $view->branch);
        self::assertSame((string) $this->bridge->id, $view->bridgeId);
        self::assertSame($this->bridge->label, $view->bridgeName);
        self::assertFalse($view->entryMissing);
        self::assertNull($view->entryYaml);
        self::assertFalse($view->canAddEntry());
        self::assertSame('/work/loupe/.worktrees/card-42', $view->folder);
        self::assertSame(['bin/console', 'app:export', 'var/out', '7'], $view->argv);
        self::assertSame(['bin/console', 'app:export', '{out}', '7'], $view->argvTemplate);
        self::assertFalse($view->bridgeQuiet);
        self::assertSame(RunCommandOutcome::ClosesTodo, $view->onSuccess);
        self::assertNull($view->run);
        self::assertSame(['out' => 'var/out'], $view->values());
        self::assertSame('The folder', $view->params[0]->description);
        self::assertSame('[a-z/]+', $view->params[0]->pattern);
        self::assertTrue($view->params[0]->valid);
    }

    public function test_the_branches_hold_only_this_project_with_the_agents_pick_first(): void
    {
        $view = $this->viewOf($this->todo());

        self::assertSame(
            [['epic/42', '/work/loupe/.worktrees/card-42'], ['a-branch', '/work/loupe/.worktrees/a'], ['main', '/work/loupe']],
            array_map(static fn ($branch): array => [$branch->branch, $branch->path], $view->branches),
        );
    }

    public function test_the_agents_pick_with_no_worktree_shows_first_with_no_folder(): void
    {
        $view = $this->viewOf($this->todo(branch: 'epic/99'));

        self::assertSame(
            [['epic/99', null], ['a-branch', '/work/loupe/.worktrees/a'], ['epic/42', '/work/loupe/.worktrees/card-42'], ['main', '/work/loupe']],
            array_map(static fn ($branch): array => [$branch->branch, $branch->path], $view->branches),
        );
        self::assertNull($view->folder);
        self::assertSame('inbox.action.run-command.disabled.no_worktree', $view->disabledReasonKey);
    }

    public function test_a_quiet_bridge_leaves_the_action_on_and_marks_the_view(): void
    {
        $this->bridge->lastSeenAt = new \DateTimeImmutable('-1 day');
        $this->em->flush();

        self::assertTrue($this->viewOf($this->todo())->bridgeQuiet);
    }

    public function test_the_argv_fills_every_built_in_placeholder_the_bridge_fills(): void
    {
        $this->bridge->commandEntries = [['run' => ['run', '{cardId}', '{cardNumber}', '{subjectType}', '{subjectId}', '{projectId}', '{kind}', '{ruleId}', '{headSha}', '{out}']] + self::entryRow()];
        $this->em->flush();

        $view = $this->viewOf($this->todo());

        self::assertSame(['run', (string) $this->card->id, '7', 'card', (string) $this->card->id, (string) $this->project->id, 'design-export', 'inbox:run-command', '', 'var/out'], $view->argv);
        self::assertNull($view->disabledReasonKey);
    }

    public function test_a_todo_with_no_card_fills_its_own_subject_and_turns_off_a_card_placeholder(): void
    {
        $this->bridge->commandEntries = [['run' => ['run', '{subjectType}', '{subjectId}', '{out}']] + self::entryRow()];
        $this->em->flush();
        $todo = $this->todo(card: false);

        self::assertSame(['run', 'inbox-item', (string) $todo->id, 'var/out'], $this->viewOf($todo)->argv);

        $this->bridge->commandEntries = [self::entryRow()];
        $this->em->flush();

        self::assertSame('inbox.action.run-command.disabled.placeholder_missing', $this->runCommandAction($todo)->disabledReasonKey);
    }

    public function test_the_worktree_of_main_is_the_folder_of_a_main_run(): void
    {
        $todo = $this->todo(branch: 'main');

        self::assertSame('/work/loupe', $this->viewOf($todo)->folder);
    }

    /** @return iterable<string, array{?string, callable(self): void}> */
    public static function offStates(): iterable
    {
        yield 'a bridge that follows the project and is live' => [null, static function (self $test): void {}];
        yield 'a bridge without the run-command capability' => ['inbox.action.run-command.disabled.unsupported', static function (self $test): void {
            $test->bridge->capabilities = [Bridge::CAPABILITY_COMMANDS, Bridge::CAPABILITY_WORK_REQUESTS];
        }];
        yield 'a bridge that takes no work requests' => ['inbox.action.run-command.disabled.unsupported', static function (self $test): void {
            $test->bridge->capabilities = [Bridge::CAPABILITY_RUN_COMMAND];
        }];
        yield 'a bridge with no recent heartbeat, whose run waits for it' => [null, static function (self $test): void {
            $test->bridge->lastSeenAt = new \DateTimeImmutable('-1 day');
        }];
        yield 'a command that names the work request, which only the bridge knows' => ['inbox.action.run-command.disabled.placeholder_unknown', static function (self $test): void {
            $test->bridge->commandEntries = [['run' => ['bin/console', '{out}', '{workRequestId}']] + self::entryRow()];
        }];
        yield 'a command that names the project slug of the bridge' => ['inbox.action.run-command.disabled.placeholder_unknown', static function (self $test): void {
            $test->bridge->commandEntries = [['run' => ['bin/console', '{out}', '--project={project}']] + self::entryRow()];
        }];
        yield 'an entry the bridge does not list' => ['inbox.action.run-command.disabled.entry_missing', static function (self $test): void {
            $test->bridge->commandEntries = [];
        }];
        yield 'a bridge that never reported its entries' => ['inbox.action.run-command.disabled.entry_missing', static function (self $test): void {
            $test->bridge->commandEntries = null;
        }];
        yield 'a branch with no worktree' => ['inbox.action.run-command.disabled.no_worktree', static function (self $test): void {
            $test->bridge->worktrees = [['projectId' => (string) $test->project->id, 'branch' => 'main', 'path' => '/work/loupe']];
        }];
        yield 'a branch that only another project has' => ['inbox.action.run-command.disabled.no_worktree', static function (self $test): void {
            $test->bridge->worktrees = [['projectId' => (string) Uuid::v7(), 'branch' => 'epic/42', 'path' => '/work/elsewhere']];
        }];
        yield 'a bridge that never reported its worktrees' => ['inbox.action.run-command.disabled.no_worktree', static function (self $test): void {
            $test->bridge->worktrees = null;
        }];
        yield 'a bridge that stopped following the project' => ['inbox.action.run-command.disabled.no_bridge', static function (self $test): void {
            $test->bridge->projects = [];
        }];
    }

    /** @param callable(self): void $change */
    #[DataProvider('offStates')]
    public function test_the_action_is_off_with_the_reason_of_the_first_check_that_fails(?string $reason, callable $change): void
    {
        $change($this);
        $this->em->flush();

        $action = $this->runCommandAction($this->todo());

        self::assertSame($reason, $action->disabledReasonKey);
    }

    public function test_a_todo_with_no_ask_has_no_bridge(): void
    {
        $action = $this->runCommandAction($this->todo(asked: false));

        self::assertSame('inbox.action.run-command.disabled.no_bridge', $action->disabledReasonKey);
        $view = $action->runCommand ?? throw new \LogicException('A run-command action carries its view.');
        self::assertNull($view->bridgeId);
        self::assertFalse($view->entryMissing);
        self::assertNull($view->folder);
    }

    public function test_a_missing_entry_shows_the_proposed_entry_and_says_it_is_missing(): void
    {
        $this->bridge->commandEntries = [];
        $this->em->flush();
        $todo = $this->todo(proposedEntry: "action: command\nrun: [make, export]");

        $view = $this->viewOf($todo);

        self::assertTrue($view->entryMissing);
        self::assertSame("design-export:\n  action: command\n  run:\n    - make\n    - export\n", $view->entryYaml);
        self::assertSame([], $view->argv);
        self::assertSame(['out'], array_map(static fn ($param): string => $param->name, $view->params));
    }

    public function test_the_named_entry_dumps_params_one_level_down_as_the_bridge_writes_them(): void
    {
        $this->bridge->commandEntries = [];
        $this->em->flush();
        $todo = $this->todo(proposedEntry: "action: command\ndescription: Export it\nparams:\n  out: { pattern: '^var/[a-z-]+\$' }\nrun: [bin/console, '{out}']");

        self::assertSame(
            "design-export:\n  action: command\n  description: 'Export it'\n  params:\n    out: { pattern: '^var/[a-z-]+\$' }\n  run:\n    - bin/console\n    - '{out}'\n",
            $this->viewOf($todo)->entryYaml,
        );
    }

    #[DataProvider('unusableProposals')]
    public function test_a_proposal_that_is_no_mapping_gives_no_entry_yaml(string $proposed): void
    {
        $this->bridge->commandEntries = [];
        $this->bridge->capabilities = [...($this->bridge->capabilities ?? []), Bridge::CAPABILITY_ADD_ENTRY];
        $this->em->flush();

        $view = $this->viewOf($this->todo(proposedEntry: $proposed));

        self::assertNull($view->entryYaml);
        self::assertFalse($view->canAddEntry());
    }

    /** @return iterable<string, array{string}> */
    public static function unusableProposals(): iterable
    {
        yield 'text that is no YAML' => ["a: [unclosed\n"];
        yield 'a scalar' => ['just text'];
        yield 'a list' => ["- one\n- two"];
        yield 'an empty document' => [''];
    }

    public function test_a_bridge_that_opted_in_can_add_the_missing_entry(): void
    {
        $this->bridge->commandEntries = [];
        $this->bridge->capabilities = [...($this->bridge->capabilities ?? []), Bridge::CAPABILITY_ADD_ENTRY];
        $this->em->flush();

        $view = $this->viewOf($this->todo(proposedEntry: 'action: command'));

        self::assertTrue($view->acceptsEntries);
        self::assertTrue($view->canAddEntry());
        self::assertSame('inbox.action.run-command.disabled.entry_missing', $view->disabledReasonKey);
    }

    public function test_a_bridge_that_did_not_opt_in_cannot_add_the_missing_entry(): void
    {
        $this->bridge->commandEntries = [];
        $this->em->flush();

        $view = $this->viewOf($this->todo(proposedEntry: 'action: command'));

        self::assertFalse($view->acceptsEntries);
        self::assertSame("design-export:\n  action: command\n", $view->entryYaml);
        self::assertFalse($view->canAddEntry());
    }

    public function test_an_entry_the_bridge_has_cannot_be_added(): void
    {
        $this->bridge->capabilities = [...($this->bridge->capabilities ?? []), Bridge::CAPABILITY_ADD_ENTRY];
        $this->em->flush();

        self::assertFalse($this->viewOf($this->todo(proposedEntry: 'action: command'))->canAddEntry());
    }

    public function test_a_proposal_of_no_entry_cannot_be_added(): void
    {
        $this->bridge->commandEntries = [];
        $this->bridge->capabilities = [...($this->bridge->capabilities ?? []), Bridge::CAPABILITY_ADD_ENTRY];
        $this->em->flush();

        self::assertFalse($this->viewOf($this->todo())->canAddEntry());
    }

    #[DataProvider('addStates')]
    public function test_a_live_add_hides_the_add_button_and_a_settled_one_does_not(WorkRequestState $state, bool $canAdd): void
    {
        $this->bridge->commandEntries = [];
        $this->bridge->capabilities = [...($this->bridge->capabilities ?? []), Bridge::CAPABILITY_ADD_ENTRY];
        $this->em->flush();
        $todo = $this->todo(proposedEntry: 'action: command');
        $this->addEntryRequest($todo, $state);

        self::assertSame($canAdd, $this->viewOf($todo)->canAddEntry());
    }

    /** @return iterable<string, array{WorkRequestState, bool}> */
    public static function addStates(): iterable
    {
        yield 'queued' => [WorkRequestState::Open, false];
        yield 'claimed' => [WorkRequestState::Claimed, false];
        yield 'refused' => [WorkRequestState::Refused, true];
        yield 'expired' => [WorkRequestState::Expired, true];
        yield 'cancelled' => [WorkRequestState::Cancelled, true];
    }

    public function test_an_add_that_was_cancelled_after_it_was_live_does_not_hide_the_button(): void
    {
        $this->bridge->commandEntries = [];
        $this->bridge->capabilities = [...($this->bridge->capabilities ?? []), Bridge::CAPABILITY_ADD_ENTRY];
        $this->em->flush();
        $todo = $this->todo(proposedEntry: 'action: command');
        $this->addEntryRequest($todo, WorkRequestState::Open, cancelled: true);

        self::assertTrue($this->viewOf($todo)->canAddEntry());
    }

    public function test_a_value_that_fails_its_pattern_turns_the_action_off_and_marks_the_param(): void
    {
        $action = $this->runCommandAction($this->todo(params: ['out' => 'Var/Out']));

        self::assertSame('inbox.action.run-command.disabled.param_invalid', $action->disabledReasonKey);
        self::assertFalse($action->runCommand?->params[0]->valid);
    }

    public function test_the_pattern_must_match_the_whole_value(): void
    {
        $action = $this->runCommandAction($this->todo(params: ['out' => 'abc1']));

        self::assertSame('inbox.action.run-command.disabled.param_invalid', $action->disabledReasonKey);
    }

    public function test_a_value_for_a_param_the_entry_lacks_and_a_missing_value_are_both_invalid(): void
    {
        self::assertSame('inbox.action.run-command.disabled.param_invalid', $this->runCommandAction($this->todo(params: ['out' => 'abc', 'extra' => 'x']))->disabledReasonKey);
        self::assertSame('inbox.action.run-command.disabled.param_invalid', $this->runCommandAction($this->todo(params: []))->disabledReasonKey);
    }

    public function test_the_values_the_owner_confirmed_replace_those_of_the_suggestion(): void
    {
        $todo = $this->todo();

        $view = $this->actions()->runCommandFor($todo, 'main', ['out' => 'other']);

        self::assertInstanceOf(RunCommandView::class, $view);
        self::assertSame(['main', '/work/loupe', ['out' => 'other'], ['bin/console', 'app:export', 'other', '7']], [$view->branch, $view->folder, $view->values(), $view->argv]);
        self::assertNull($view->disabledReasonKey);
        $bad = $this->actions()->runCommandFor($todo, 'no-such-branch', ['out' => 'other']);
        self::assertSame('inbox.action.run-command.disabled.no_worktree', $bad?->disabledReasonKey);
        $invalid = $this->actions()->runCommandFor($todo, null, ['out' => '--exec=1']);
        self::assertSame('inbox.action.run-command.disabled.param_invalid', $invalid?->disabledReasonKey);
    }

    public function test_an_item_with_no_run_command_suggestion_has_no_view(): void
    {
        $item = new InboxItem(project: $this->project, number: ++$this->number, kind: InboxItemKind::Todo, title: 'Plain', blocking: false, origin: InboxAskOrigin::Agent);
        $item->suggestedActions = [['action' => 'open', 'reason' => 'Read it.', 'recommended' => true, 'cardId' => null]];
        $this->em->persist($item);
        $this->em->flush();

        self::assertNull($this->actions()->runCommandFor($item));
        self::assertNull(array_find($this->actions()->forItem($item), static fn (ItemAction $action): bool => InboxActionKind::RunCommand === $action->kind));
    }

    public function test_a_wait_item_takes_the_bridge_of_the_run_it_waits_on_and_resumes_it_on_success(): void
    {
        $run = $this->workerRun([
            ['action' => 'run-command', 'reason' => 'Export first.', 'recommended' => true, 'command' => ['entry' => 'design-export', 'branch' => 'main', 'params' => [['name' => 'out', 'value' => 'var/out']]]],
        ]);
        $item = $this->waitItem($run);

        $action = $this->runCommandAction($item);

        self::assertNull($action->disabledReasonKey);
        self::assertSame((string) $this->bridge->id, $action->runCommand?->bridgeId);
        self::assertSame(RunCommandOutcome::ResumesRun, $action->runCommand->onSuccess);
        self::assertTrue($action->default);
        self::assertSame('/work/loupe', $action->runCommand->folder);
    }

    public function test_a_wait_item_whose_run_has_no_bridge_has_the_no_bridge_reason(): void
    {
        $run = $this->workerRun([['action' => 'run-command', 'reason' => 'Export first.', 'recommended' => false, 'command' => ['entry' => 'design-export', 'branch' => 'main', 'params' => ['out' => 'var/out']]]]);
        $item = $this->waitItem($run);
        $this->em->getConnection()->executeStatement('UPDATE bridge_worker_runs SET bridge_id = NULL WHERE id = ?', [(string) $run->id]);
        $this->em->clear();
        $item = $this->em->find(InboxItem::class, $item->id) ?? throw new \LogicException('Stored.');

        self::assertSame('inbox.action.run-command.disabled.no_bridge', $this->runCommandAction($item)->disabledReasonKey);
    }

    public function test_a_queued_run_turns_the_action_off_and_shows_queued(): void
    {
        $todo = $this->todo();
        $this->commandRun($todo, WorkRequestState::Open);

        $action = $this->runCommandAction($todo);

        self::assertSame('inbox.action.run-command.disabled.running', $action->disabledReasonKey);
        self::assertSame(RunCommandRunState::Queued, $action->runCommand?->run?->state);
    }

    public function test_a_claimed_request_with_no_run_yet_reads_as_running(): void
    {
        $todo = $this->todo();
        $this->commandRun($todo, WorkRequestState::Claimed);

        $run = $this->runCommandAction($todo)->runCommand?->run;

        self::assertSame(RunCommandRunState::Running, $run?->state);
        self::assertTrue($run->state->isLive());
    }

    public function test_a_running_command_turns_the_action_off(): void
    {
        $todo = $this->todo();
        $this->commandRun($todo, WorkRequestState::Claimed, WorkerRunState::Running);

        $action = $this->runCommandAction($todo);

        self::assertSame('inbox.action.run-command.disabled.running', $action->disabledReasonKey);
        self::assertSame(RunCommandRunState::Running, $action->runCommand?->run?->state);
    }

    public function test_a_failed_command_shows_the_exit_code_and_the_last_lines_and_leaves_the_action_on(): void
    {
        $todo = $this->todo();
        $output = implode("\n", array_map(static fn (int $line): string => 'line '.$line, range(1, 15)))."\n";
        $this->commandRun($todo, WorkRequestState::Done, WorkerRunState::Failed, exitCode: 2, output: $output);

        $action = $this->runCommandAction($todo);
        $run = $action->runCommand?->run;

        self::assertNull($action->disabledReasonKey);
        self::assertSame(RunCommandRunState::Failed, $run?->state);
        self::assertTrue($run->state->canRunAgain());
        self::assertSame(2, $run->exitCode);
        self::assertSame(['line 6', 'line 7', 'line 8', 'line 9', 'line 10', 'line 11', 'line 12', 'line 13', 'line 14', 'line 15'], $run->outputLines);
    }

    public function test_a_succeeded_command_shows_succeeded(): void
    {
        $todo = $this->todo();
        $this->commandRun($todo, WorkRequestState::Done, WorkerRunState::Succeeded, exitCode: 0, output: "done\n");

        $run = $this->runCommandAction($todo)->runCommand?->run;

        self::assertSame(RunCommandRunState::Succeeded, $run?->state);
        self::assertFalse($run->state->canRunAgain());
        self::assertSame(['done'], $run->outputLines);
    }

    public function test_a_running_command_carries_the_end_of_its_stored_log_and_where_it_stops(): void
    {
        $todo = $this->todo();
        $run = $this->commandRun($todo, WorkRequestState::Claimed, WorkerRunState::Running);
        self::assertInstanceOf(WorkerRun::class, $run);
        $lines = array_map(static fn (int $line): string => 'line '.$line."\n", range(1, 250));
        $this->chunk($run, implode('', \array_slice($lines, 0, 100)));
        $this->chunk($run, implode('', \array_slice($lines, 100)).'partial');

        $view = $this->runCommandAction($todo)->runCommand?->run;

        self::assertTrue($view?->isStreaming());
        self::assertSame((string) $run->id, $view->runId);
        self::assertSame(250, $view->lineCount);
        self::assertSame(array_sum(array_map(strlen(...), $lines)) + \strlen('partial'), $view->logLength);
        self::assertSame(implode('', \array_slice($lines, 51)).'partial', $view->tailText);
    }

    public function test_an_ended_command_shows_the_last_lines_of_its_stored_log_and_its_duration(): void
    {
        $todo = $this->todo();
        $run = $this->commandRun(
            $todo,
            WorkRequestState::Done,
            WorkerRunState::Succeeded,
            exitCode: 0,
            output: 'the capped report',
            startedAt: new \DateTimeImmutable('2026-10-16 10:00:00'),
            endedAt: new \DateTimeImmutable('2026-10-16 10:03:12'),
        );
        self::assertInstanceOf(WorkerRun::class, $run);
        $this->chunk($run, implode('', array_map(static fn (int $line): string => 'line '.$line."\n", range(1, 12))));

        $view = $this->runCommandAction($todo)->runCommand?->run;

        self::assertTrue($view?->hasLog());
        self::assertFalse($view->isStreaming());
        self::assertSame(12, $view->lineCount);
        self::assertSame(['line 3', 'line 4', 'line 5', 'line 6', 'line 7', 'line 8', 'line 9', 'line 10', 'line 11', 'line 12'], $view->outputLines);
        self::assertSame('', $view->tailText);
        self::assertSame(192, $view->durationSeconds);
        self::assertSame('3 min 12 s', $view->durationLabel());
    }

    public function test_an_ended_command_whose_log_hit_the_cap_shows_the_last_lines_of_its_report(): void
    {
        $todo = $this->todo();
        $run = $this->commandRun($todo, WorkRequestState::Done, WorkerRunState::Failed, exitCode: 1, output: "late line\nfatal: boom\n");
        self::assertInstanceOf(WorkerRun::class, $run);
        $this->chunk($run, "early line\n", cut: true);

        $view = $this->runCommandAction($todo)->runCommand?->run;

        self::assertTrue($view?->hasLog());
        self::assertSame(['late line', 'fatal: boom'], $view->outputLines);
    }

    public function test_a_running_command_whose_log_hit_the_cap_shows_its_stored_tail(): void
    {
        $todo = $this->todo();
        $run = $this->commandRun($todo, WorkRequestState::Claimed, WorkerRunState::Running, output: '');
        self::assertInstanceOf(WorkerRun::class, $run);
        $this->chunk($run, "early line\n", cut: true);

        $view = $this->runCommandAction($todo)->runCommand?->run;

        self::assertSame(['early line'], $view?->outputLines);
        self::assertSame("early line\n", $view->tailText);
    }

    public function test_a_run_with_no_stored_log_has_no_log_link_and_keeps_its_reported_output(): void
    {
        $todo = $this->todo();
        $this->commandRun($todo, WorkRequestState::Done, WorkerRunState::Failed, exitCode: 1, output: "boom\n");

        $view = $this->runCommandAction($todo)->runCommand?->run;

        self::assertFalse($view?->hasLog());
        self::assertSame(['boom'], $view->outputLines);
        self::assertNull($view->durationLabel());
    }

    public function test_a_short_duration_reads_in_seconds(): void
    {
        $view = new RunCommandRun(new InboxAction($this->todo(), InboxActionKind::RunCommand, $this->project->owner, '', new \DateTimeImmutable()), RunCommandRunState::Succeeded, durationSeconds: 14);

        self::assertSame('14 s', $view->durationLabel());
    }

    public function test_a_command_with_no_output_has_no_lines(): void
    {
        $todo = $this->todo();
        $this->commandRun($todo, WorkRequestState::Done, WorkerRunState::Failed, exitCode: 1, output: '');

        self::assertSame([], $this->runCommandAction($todo)->runCommand?->run?->outputLines);
    }

    public function test_a_refused_request_shows_the_reason_code_with_its_translation_key_and_leaves_the_action_on(): void
    {
        $todo = $this->todo();
        $this->commandRun($todo, WorkRequestState::Refused, reason: 'worktree-missing');

        $action = $this->runCommandAction($todo);
        $run = $action->runCommand?->run;

        self::assertNull($action->disabledReasonKey);
        self::assertSame(RunCommandRunState::Refused, $run?->state);
        self::assertSame('worktree-missing', $run->reason);
        self::assertSame('inbox.run_command.refusal.worktree_missing', $run->reasonKey());
        self::assertTrue($run->state->canRunAgain());
    }

    public function test_an_unknown_refusal_code_has_no_translation_key(): void
    {
        $todo = $this->todo();
        $this->commandRun($todo, WorkRequestState::Refused, reason: 'something-new');

        $run = $this->runCommandAction($todo)->runCommand?->run;

        self::assertSame('something-new', $run?->reason);
        self::assertNull($run->reasonKey());
    }

    public function test_expired_and_cancelled_requests_leave_the_action_on(): void
    {
        $expired = $this->todo();
        $this->commandRun($expired, WorkRequestState::Expired);
        $cancelled = $this->todo();
        $this->commandRun($cancelled, WorkRequestState::Cancelled);

        self::assertSame(RunCommandRunState::Expired, $this->runCommandAction($expired)->runCommand?->run?->state);
        self::assertNull($this->runCommandAction($expired)->disabledReasonKey);
        self::assertSame(RunCommandRunState::Cancelled, $this->runCommandAction($cancelled)->runCommand?->run?->state);
        self::assertNull($this->runCommandAction($cancelled)->disabledReasonKey);
    }

    public function test_the_newest_run_command_action_sets_the_state(): void
    {
        $todo = $this->todo();
        $this->commandRun($todo, WorkRequestState::Refused, reason: 'entry-missing', requestedAt: new \DateTimeImmutable('2026-10-01 10:00:00'));
        $this->commandRun($todo, WorkRequestState::Open, requestedAt: new \DateTimeImmutable('2026-10-01 11:00:00'), kind: 'other-entry');

        self::assertSame(RunCommandRunState::Queued, $this->runCommandAction($todo)->runCommand?->run?->state);
    }

    private function runCommandAction(InboxItem $item): ItemAction
    {
        return array_find($this->actions()->forItem($item), static fn (ItemAction $action): bool => InboxActionKind::RunCommand === $action->kind)
            ?? throw new \LogicException('The item offers run a command.');
    }

    private function viewOf(InboxItem $item): RunCommandView
    {
        return $this->runCommandAction($item)->runCommand ?? throw new \LogicException('A run-command action carries its view.');
    }

    /**
     * @param array<string, string> $params
     */
    private function todo(string $branch = 'epic/42', array $params = ['out' => 'var/out'], bool $asked = true, bool $recommended = true, ?string $proposedEntry = null, bool $card = true): InboxItem
    {
        $item = new InboxItem(project: $this->project, number: ++$this->number, kind: InboxItemKind::Todo, title: 'Export the design system', blocking: false, origin: InboxAskOrigin::Agent);
        $item->suggestedActions = [[
            'action' => 'run-command',
            'reason' => 'Export it.',
            'recommended' => $recommended,
            'cardId' => null,
            'command' => ['entry' => 'design-export', 'branch' => $branch, 'params' => $params],
            'proposedEntry' => $proposedEntry,
        ]];
        if ($card) {
            $item->cards->add(new InboxItemCard($item, $this->card));
        }
        $this->em->persist($item);
        if ($asked) {
            $ask = new InboxAsk(project: $this->project, sessionId: Uuid::v4(), bridgeId: $this->bridge->id);
            $this->em->persist($ask);
            $this->em->persist(new InboxAskItem($ask, $item));
        }
        $this->em->flush();

        return $item;
    }

    /** @param list<array<string, mixed>> $suggestions */
    private function workerRun(array $suggestions): WorkerRun
    {
        $run = new WorkerRun(
            project: $this->project,
            bridgeId: $this->bridge->id,
            subjectType: WorkSubject::CARD,
            subjectId: $this->card->id ?? throw new \LogicException('The card is stored.'),
            cardNumber: $this->card->number,
            workKind: 'implement',
            state: WorkerRunState::Blocked,
            runKey: Uuid::v7(),
            sessionId: Uuid::v4(),
        );
        $run->resultFields = ['suggestedActions' => $suggestions];
        $this->em->persist($run);
        $this->em->flush();

        return $run;
    }

    private function waitItem(WorkerRun $run): InboxItem
    {
        $item = new InboxItem(project: $this->project, number: ++$this->number, kind: InboxItemKind::Wait, title: '#7 Ship it', blocking: true, origin: InboxAskOrigin::Loupe);
        $this->em->persist($item);
        $watch = new InboxCardWatch($item, $this->card->id ?? throw new \LogicException('The card is stored.'), $this->card->number);
        $this->em->persist($watch);
        $watch->waits->add(new InboxCardWait($watch, 'run-needs-you', InboxCardWaitType::WorkerRun, 'marker.run_needs_you', runId: $run->id, startedAt: new \DateTimeImmutable('2026-10-13 10:00:00')));
        $this->em->flush();

        return $item;
    }

    /** Records a run-command action with its request, and optionally the run the request started. */
    private function commandRun(
        InboxItem $item,
        WorkRequestState $state,
        ?WorkerRunState $runState = null,
        ?int $exitCode = null,
        string $output = '',
        ?string $reason = null,
        ?\DateTimeImmutable $requestedAt = null,
        string $kind = 'design-export',
        ?\DateTimeImmutable $startedAt = null,
        ?\DateTimeImmutable $endedAt = null,
    ): ?WorkerRun {
        $request = new WorkRequest(
            project: $this->project,
            subjectType: WorkSubject::CARD,
            subjectId: $this->card->id ?? throw new \LogicException('The card is stored.'),
            cardNumber: $this->card->number,
            kind: $kind,
            capability: Bridge::CAPABILITY_RUN_COMMAND,
            ruleId: 'inbox:run-command',
            createdAt: new \DateTimeImmutable(),
        );
        $request->state = $state;
        $request->reason = $reason;
        $request->settledAt = WorkRequestState::Open === $state || WorkRequestState::Claimed === $state ? null : new \DateTimeImmutable();
        $this->em->persist($request);
        $this->em->flush();
        $action = new InboxAction($item, InboxActionKind::RunCommand, $this->project->owner, '', $requestedAt ?? new \DateTimeImmutable());
        $action->workRequestId = $request->id;
        if (WorkRequestState::Cancelled === $state) {
            $action->cancelledAt = new \DateTimeImmutable();
        }
        $this->em->persist($action);
        $run = null;
        if (null !== $runState) {
            $run = new WorkerRun(
                project: $this->project,
                bridgeId: $this->bridge->id,
                subjectType: WorkSubject::CARD,
                subjectId: $this->card->id,
                cardNumber: $this->card->number,
                workKind: $kind,
                state: $runState,
                runKey: Uuid::v7(),
                startedAt: $startedAt,
                endedAt: $endedAt,
                exitCode: $exitCode,
                output: $output,
                workRequestId: $request->id,
            );
            $this->em->persist($run);
        }
        $this->em->flush();

        return $run;
    }

    private function chunk(WorkerRun $run, string $text, bool $cut = false): void
    {
        $start = (int) $this->em->getConnection()->fetchOne('SELECT COALESCE(MAX(start_offset + length), 0) FROM bridge_worker_run_output_chunks WHERE run_id = :run', ['run' => (string) $run->id]);
        $this->em->persist(new WorkerRunOutputChunk($run, $start, \strlen($text), $text, $cut));
        $this->em->flush();
    }

    private function addEntryRequest(InboxItem $item, WorkRequestState $state, bool $cancelled = false): void
    {
        $request = new WorkRequest(
            project: $this->project,
            subjectType: InboxItem::SUBJECT_TYPE,
            subjectId: $item->id ?? throw new \LogicException('The item is stored.'),
            cardNumber: null,
            kind: 'add-entry',
            capability: Bridge::CAPABILITY_ADD_ENTRY,
            ruleId: 'inbox:add-entry',
            createdAt: new \DateTimeImmutable(),
        );
        $request->state = $state;
        $this->em->persist($request);
        $this->em->flush();
        $action = new InboxAction($item, InboxActionKind::AddEntry, $this->project->owner, '', new \DateTimeImmutable());
        $action->workRequestId = $request->id;
        $action->cancelledAt = $cancelled ? new \DateTimeImmutable() : null;
        $this->em->persist($action);
        $this->em->flush();
    }

    /** @return array{name: string, description: ?string, params: list<array{name: string, pattern: string, description: ?string}>, run: list<string>, timeout: string} */
    private static function entryRow(): array
    {
        return [
            'name' => 'design-export',
            'description' => null,
            'params' => [['name' => 'out', 'pattern' => '[a-z/]+', 'description' => 'The folder']],
            'run' => ['bin/console', 'app:export', '{out}', '{cardNumber}'],
            'timeout' => '10m',
        ];
    }

    private function actions(): ItemActions
    {
        $container = self::getContainer();
        $watches = $container->get(InboxCardWatchRepository::class);
        $runs = $container->get(WorkerRunRepository::class);
        $controls = $container->get(WorkerRunControls::class);
        $pauses = $container->get(CardPauses::class);
        $stageWorks = $container->get(TemplateStageWorks::class);
        $bridges = $container->get(BridgeRepository::class);
        $liveness = $container->get(BridgeLiveness::class);
        $workRequests = $container->get(WorkRequestRepository::class);
        $inboxActions = $container->get(InboxActionRepository::class);
        $inboxAsks = $container->get(InboxAskRepository::class);
        self::assertInstanceOf(InboxCardWatchRepository::class, $watches);
        self::assertInstanceOf(WorkerRunRepository::class, $runs);
        self::assertInstanceOf(WorkerRunControls::class, $controls);
        self::assertInstanceOf(CardPauses::class, $pauses);
        self::assertInstanceOf(TemplateStageWorks::class, $stageWorks);
        self::assertInstanceOf(BridgeRepository::class, $bridges);
        self::assertInstanceOf(BridgeLiveness::class, $liveness);
        self::assertInstanceOf(WorkRequestRepository::class, $workRequests);
        self::assertInstanceOf(InboxActionRepository::class, $inboxActions);
        self::assertInstanceOf(InboxAskRepository::class, $inboxAsks);

        $chunks = self::getContainer()->get(WorkerRunOutputChunkRepository::class);
        self::assertInstanceOf(WorkerRunOutputChunkRepository::class, $chunks);

        $waitText = self::getContainer()->get(CardWaitText::class);
        self::assertInstanceOf(CardWaitText::class, $waitText);

        $holds = self::getContainer()->get(CardHoldRepository::class);
        self::assertInstanceOf(CardHoldRepository::class, $holds);

        $reviewLookup = self::getContainer()->get(InboxReviewLookup::class);
        self::assertInstanceOf(InboxReviewLookup::class, $reviewLookup);

        $sessionPrompts = self::getContainer()->get(SessionPromptWriter::class);
        self::assertInstanceOf(SessionPromptWriter::class, $sessionPrompts);

        return new ItemActions(
            $watches,
            $runs,
            $controls,
            $pauses,
            new SessionBridges($bridges, $liveness),
            $workRequests,
            $stageWorks,
            new AskingBridge($bridges, $inboxAsks, $runs),
            $this->resolver($liveness),
            new RunCommandRuns($inboxActions, $workRequests, $runs, $chunks),
            $waitText,
            $holds,
            $reviewLookup,
            $sessionPrompts,
        );
    }

    private function resolver(BridgeLiveness $liveness): RunCommandResolver
    {
        $cards = self::getContainer()->get(CardRepository::class);
        $contexts = self::getContainer()->get(WorkRequestContexts::class);
        self::assertInstanceOf(CardRepository::class, $cards);
        self::assertInstanceOf(WorkRequestContexts::class, $contexts);

        return new RunCommandResolver($liveness, $cards, $contexts);
    }
}
