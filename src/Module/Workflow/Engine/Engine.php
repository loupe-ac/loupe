<?php

declare(strict_types=1);

namespace App\Module\Workflow\Engine;

use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use App\Module\Workflow\Action\ActionParams;
use App\Module\Workflow\Action\Actions;
use App\Module\Workflow\Action\Ask;
use App\Module\Workflow\Action\MissingAction;
use App\Module\Workflow\Action\ReleasePause;
use App\Module\Workflow\Command\ReleaseWorkflowPauseCommand;
use App\Module\Workflow\Contract\ActionOutcome;
use App\Module\Workflow\Contract\ActionOutcomeKind;
use App\Module\Workflow\Contract\Actor;
use App\Module\Workflow\Contract\BlockerHoldChanged;
use App\Module\Workflow\Contract\CardDirectory;
use App\Module\Workflow\Contract\CardPauses;
use App\Module\Workflow\Contract\CardSnapshot;
use App\Module\Workflow\Contract\Facts;
use App\Module\Workflow\Contract\PauseKind;
use App\Module\Workflow\Contract\PauseView;
use App\Module\Workflow\Contract\PersonWork;
use App\Module\Workflow\Contract\PullRequestFacts;
use App\Module\Workflow\Contract\PullRequestState;
use App\Module\Workflow\Contract\RefiresOnFactChange;
use App\Module\Workflow\Contract\RetriesWhileTrue;
use App\Module\Workflow\Contract\RuleAsks;
use App\Module\Workflow\Contract\RunView;
use App\Module\Workflow\Contract\Unreadable;
use App\Module\Workflow\Contract\UnreadableKind;
use App\Module\Workflow\Contract\WithdrawKind;
use App\Module\Workflow\Contract\WorkHolds;
use App\Module\Workflow\Contract\WorkLedger;
use App\Module\Workflow\Contract\WorkOpener;
use App\Module\Workflow\Contract\WorkState;
use App\Module\Workflow\Contract\WorkView;
use App\Module\Workflow\Entity\WorkflowRuleState;
use App\Module\Workflow\Event\CardPaused;
use App\Module\Workflow\Repository\WorkflowPendingBaselineRepository;
use App\Module\Workflow\Repository\WorkflowRuleStateRepository;
use App\Module\Workflow\Service\ActionContexts;
use App\Module\Workflow\Service\FactFingerprint;
use App\Module\Workflow\Service\FactsBuilder;
use App\Module\Workflow\Service\WorkflowAutomation;
use App\Module\Workflow\Template\Rule;
use App\Module\Workflow\Template\TemplateMissing;
use App\Module\Workflow\Template\TemplateSource;
use App\Module\Workflow\Template\WorkFailurePolicy;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Evaluates the rules of the workflow template on one card. A rule fires on the rising edge
 * of its condition, and again when a refused attempt is due or the facts it reads change.
 */
final readonly class Engine
{
    public const string NO_BRIDGE_TOOK_WORK = 'no-bridge-took-work';

    public const string REPAIR_FAILED = 'repair-failed';

    public const string STILL_TRUE = 'still-true';

    /** The code of a worker that waits for a person. The engine may not import the Bridge enum that spells it. */
    public const string NEEDS_PERSON = 'needs-person';

    private const string NOT_STARTED = 'not-started';
    private const string RUN_RESUMED = 'run-resumed';
    private const string SUBJECT_CHANGED = 'subject-changed';
    private const string REFILLED = 'refilled';
    private const string NO_LONGER_HOLDS = 'no-longer-holds';
    private const string LEFT_SLOT = 'left-slot';
    private const string PULL_REQUEST_CLOSED = 'pull-request-closed';
    private const string RULE_REMOVED = 'rule-removed';

    /** The key of the Board condition that reads an open blocker, which the engine may not import. */
    private const string OPEN_BLOCKER = 'card.blocker.open';

    /** @var \ArrayObject<string, bool> the cards whose evaluation runs now, and whether a nested call asked for another pass */
    private \ArrayObject $running;

    public function __construct(
        private EntityManagerInterface $em,
        private CardDirectory $cards,
        private ProjectRepository $projects,
        private TemplateSource $templates,
        private FactsBuilder $factsBuilder,
        private FactFingerprint $fingerprint,
        private WorkflowRuleStateRepository $workflowRuleStates,
        private WorkLedger $ledger,
        private WorkflowAutomation $automation,
        private WorkflowPendingBaselineRepository $workflowPendingBaselines,
        private CardPauses $cardPauses,
        private Actions $actions,
        private WorkOpener $opener,
        private ActionContexts $contexts,
        private RuleSubject $ruleSubject,
        private MarkerPass $markers,
        private RuleAsks $ruleAsks,
        private WorkHolds $workHolds,
        private EventDispatcherInterface $events,
        private LoggerInterface $logger,
    ) {
        /** @var \ArrayObject<string, bool> $running */
        $running = new \ArrayObject();
        $this->running = $running;
    }

    /**
     * A sync transport runs an evaluation that an action starts inside the pass of the same card, where it reads none
     * of the rows the pass has not flushed. That call only asks for one more pass, which runs after this one commits.
     */
    public function evaluate(Uuid $cardId, \DateTimeImmutable $now): void
    {
        $key = $cardId->toRfc4122();
        if ($this->running->offsetExists($key)) {
            $this->running[$key] = true;

            return;
        }

        $this->running[$key] = false;
        try {
            $this->evaluateOnce($cardId, $now);
            $again = $this->running[$key];
        } finally {
            unset($this->running[$key]);
        }
        if ($again) {
            $this->evaluate($cardId, $now);
        }
    }

    private function evaluateOnce(Uuid $cardId, \DateTimeImmutable $now): void
    {
        $run = $this->em->wrapInTransaction(fn (): ?Evaluation => $this->evaluateLocked($cardId, $now));
        if (null === $run) {
            return;
        }
        if ($run->holdChanged) {
            $this->events->dispatch(new BlockerHoldChanged($run->card->projectId, $cardId));
        }
        if ($run->baselined) {
            $this->logger->info('workflow.card_baselined', ['cardId' => $cardId->toRfc4122()]);

            return;
        }

        foreach ($run->pauses as $pause) {
            $this->events->dispatch(new CardPaused(
                $pause->projectId,
                $cardId,
                $pause->reason,
                $pause->kind,
            ));
        }
        if ([] !== $run->fired) {
            $this->logger->info('workflow.card_evaluated', ['cardId' => $cardId->toRfc4122(), 'fired' => $run->fired]);
        }
    }

    private function evaluateLocked(Uuid $cardId, \DateTimeImmutable $now): ?Evaluation
    {
        // Advisory, because an outbox insert takes a key-share lock on the project row,
        // and a FOR UPDATE on that row would deadlock against a claim or a settle.
        $this->workflowRuleStates->lockCard($cardId);
        $card = $this->cards->find($cardId);
        $project = null === $card ? null : $this->projects->find($card->projectId);
        if (null === $card || null === $project) {
            return null;
        }
        // A held card is unmanaged: settling its requests would cancel or expire them. Its markers still show.
        $held = $this->ledger->isHeld($card->projectId, $cardId);
        $automated = !$held && $this->automation->runsFor($project);
        // The mark makes the first pass after the automation is on again quiet.
        if (!$held && !$automated) {
            $this->workflowPendingBaselines->markCards($card->projectId, [$cardId]);
        }
        $baseline = $automated && $this->workflowPendingBaselines->consume($cardId);
        $card = $this->cards->refresh($cardId) ?? throw new \LogicException('A card stays stored for the pass.');

        try {
            $template = $this->templates->forProject($card->projectId);
        } catch (TemplateMissing) {
            $this->markers->clear($card);

            return null;
        }

        $run = new Evaluation($card, $project, $template, $this->facts($card, $now), $automated ? $this->workflowRuleStates->findForCard($cardId) : [], $now);
        if (!$automated) {
            $this->markers->run($run);

            return $run;
        }
        $this->logMissingProviders($run);
        $this->logMissingActions($run);
        if ($baseline) {
            $this->settleWorkRequests($run, $cardId, expire: false);
            // Before the baseline, which replaces the fingerprint a retries pause compares against.
            $this->stillPaused($run);
            $this->baseline($run);
            $this->withdrawRemovedAsks($run);
            $this->em->flush();
            $this->runMarkers($run);

            return $run;
        }
        $this->settleWorkRequests($run, $cardId);
        // A pause ends the pass, so it is never released in the pass that made it.
        if (!$run->ended && (!$this->stillPaused($run) || $this->releasedByRule($run))) {
            // After the release, so a refusal that settled during a pause counts in the pass that ends it.
            $this->readNewCauses($run);
            $this->readSettledRequests($run);
            $this->readAnsweringRuns($run);
            if (!$run->ended) {
                $this->runRules($run, $template->rules);
            }
        }
        $this->withdrawRemovedAsks($run);
        $this->em->flush();
        $this->runMarkers($run);

        return $run;
    }

    /** The marker pass reads the facts of the rule pass, unless the pass changed what they read. Then it builds them again from the flushed rows. */
    private function runMarkers(Evaluation $run): void
    {
        if ($run->factsStale) {
            $run->facts = $this->facts($run->card, $run->now, $run->facts);
            $run->factsStale = false;
        }
        $this->markers->run($run);
    }

    /** Doctrine compares a Uuid by identity, so assigning an equal id would write the row again. */
    private static function adoptSubject(WorkflowRuleState $state, ?Uuid $subject): void
    {
        $stored = $state->subjectPullRequestId;
        if (null !== $stored && null !== $subject ? $stored->equals($subject) : $stored === $subject) {
            return;
        }
        $state->subjectPullRequestId = $subject;
    }

    /** Records the truth of each rule the card reads now, clears its retries, and resets the rules of other slots. Fires none. */
    private function baseline(Evaluation $run): void
    {
        $run->baselined = true;
        foreach ($run->template->rules as $rule) {
            if (!$run->applies($rule)) {
                $state = $run->states[$rule->id] ?? null;
                if (null !== $state) {
                    $this->write($run, $state, $this->forget(...));
                }
                continue;
            }
            if ($this->waits($run, $rule)) {
                continue;
            }
            $this->write($run, $this->state($run, $rule), function (WorkflowRuleState $state) use ($run, $rule): void {
                $bound = $this->ruleSubject->bind($rule, $run->facts);
                if (!$bound->truth) {
                    $this->withdrawAsk($state, self::NO_LONGER_HOLDS);
                }
                if ($this->refills($run, $rule, $state->subjectPullRequestId)) {
                    $state->fires = 0;
                }
                $state->truth = $bound->truth;
                $state->heldByWorkHoldSince = null;
                $this->markBlockerHold($run, $rule, $state, $bound);
                $state->fingerprint = $this->fingerprint->of($bound->facts, $rule->when->reads());
                if ($bound->truth) {
                    // Another pull request gets its own request budget.
                    if ($bound->binds && null !== $state->subjectPullRequestId && null !== $bound->subject && !$state->subjectPullRequestId->equals($bound->subject)) {
                        $state->fires = 0;
                    }
                    self::adoptSubject($state, $bound->subject);
                }
                $state->attempts = 0;
                $state->dueAt = null;
                $state->lastRefusal = null;
                $state->lastRefusalAt = null;
                $state->workRequestId = null;
                $state->answeringRunId = null;
                $state->repaired = false;
            });
        }
    }

    /**
     * A request that ended done while its rule still holds asks again when a part of the condition holds that did not hold at the open.
     * The false truth makes runRules() fire the rule in this pass. A fix that ends with the same parts asks for nothing.
     */
    private function readNewCauses(Evaluation $run): void
    {
        foreach ($run->template->rules as $rule) {
            $state = $run->states[$rule->id] ?? null;
            if ($run->ended || null === $state || null === $state->workRequestId || null === $state->causesAtOpen || !$run->applies($rule) || $this->waits($run, $rule) || $this->retriesWhileTrue($rule)) {
                continue;
            }
            $request = $this->ledger->find($state->workRequestId);
            $bound = $this->ruleSubject->bind($rule, $run->facts);
            if (null === $request || WorkState::Done !== $request->state || !$bound->truth || $request->kind === $run->template->onWorkFailed?->repairKind) {
                continue;
            }
            $stored = $state->subjectPullRequestId;
            if ($bound->binds && null !== $stored && null !== $bound->subject && !$stored->equals($bound->subject)) {
                continue;
            }
            $this->write($run, $state, static function (WorkflowRuleState $state) use ($rule, $bound): void {
                $new = array_diff($rule->when->holdingParts($bound->facts), $state->causesAtOpen ?? []);
                if ([] !== $new) {
                    $state->truth = false;
                }
                $state->causesAtOpen = null;
            });
        }
    }

    private function ownLiveRequest(Evaluation $run, Rule $rule): ?WorkView
    {
        foreach ($this->ledger->live($run->card->id) as $request) {
            if ($request->ruleId === $rule->id) {
                return $request;
            }
        }

        return null;
    }

    /**
     * Reads how the last request of each rule settled. A done request clears the attempts. A refused one earns a retry after
     * the next delay of the onWorkFailed block, or pauses the card when its code earns none or the retries or delays are used up.
     * When they are used up, a template with a repair kind first opens one repair request. A template with no onWorkFailed block reads nothing.
     */
    private function readSettledRequests(Evaluation $run): void
    {
        $policy = $run->template->onWorkFailed;
        // The pass reaches this read only while no pause holds the card, so a refusal never waits on one here.
        if (null === $policy) {
            return;
        }
        foreach ($run->template->rules as $rule) {
            $state = $run->states[$rule->id] ?? null;
            if ($run->ended || null === $state || null === $state->workRequestId || !$run->applies($rule) || $this->waits($run, $rule)) {
                continue;
            }
            $request = $this->ledger->find($state->workRequestId);
            $bound = $this->ruleSubject->bind($rule, $run->facts);
            if (null === $request || !$bound->truth) {
                continue;
            }
            // The repair carries the rule id of the failed rule, so its kind tells it apart.
            $repair = $request->kind === $policy->repairKind;
            if ($repair && \in_array($request->state, [WorkState::Open, WorkState::Claimed], true)) {
                $run->repairing[$rule->id] = true;
                continue;
            }
            // The rule now acts on another pull request, so runRule() starts that work and the old refusal counts for nothing.
            $stored = $state->subjectPullRequestId;
            if ($bound->binds && null !== $stored && null !== $bound->subject && !$stored->equals($bound->subject)) {
                continue;
            }
            if ($repair && WorkState::Done === $request->state) {
                // The rule fires its last try in this pass. The cleared id keeps the next pass from reading the repair again.
                $this->write($run, $state, static function (WorkflowRuleState $state) use ($run): void {
                    $state->dueAt = $run->now;
                    $state->workRequestId = null;
                });
                continue;
            }
            if (WorkState::Done === $request->state) {
                if ($this->retriesWhileTrue($rule)) {
                    if (null === $state->lastRefusalAt || null === $request->settledAt || $state->lastRefusalAt < $request->settledAt) {
                        $this->write($run, $state, function (WorkflowRuleState $state) use ($run, $rule, $policy, $bound): void {
                            $this->refuse($run, $rule, $state, $policy, self::STILL_TRUE, $bound);
                            // A fact that changed while the request ran must not skip the wait in this pass.
                            $state->fingerprint = $this->fingerprint->of($bound->facts, $rule->when->reads());
                        });
                    }
                    continue;
                }
                $this->write($run, $state, static function (WorkflowRuleState $state): void {
                    $state->attempts = 0;
                    $state->dueAt = null;
                    $state->lastRefusal = null;
                    $state->lastRefusalAt = null;
                    $state->workRequestId = null;
                    $state->repaired = false;
                });
                continue;
            }
            // A refusal the engine counted already leaves lastRefusalAt at or after the settle.
            if (WorkState::Refused !== $request->state || (null !== $state->lastRefusalAt && null !== $request->settledAt && $state->lastRefusalAt >= $request->settledAt)) {
                continue;
            }

            if ($repair) {
                $this->write($run, $state, function (WorkflowRuleState $state) use ($run, $rule, $bound): void {
                    $state->lastRefusal = self::REPAIR_FAILED;
                    $state->lastRefusalAt = $run->now;
                    $state->fingerprint = $this->fingerprint->of($bound->facts, $rule->when->reads());
                    $this->pause($run, PauseKind::Retries, self::REPAIR_FAILED, $rule->id);
                });
                continue;
            }

            $code = $request->reason ?? 'failed';
            // A resumed worker that reported since the stop settled already serves the answer to it.
            $answering = self::NEEDS_PERSON === $code ? $this->answeringRun($run, $rule, $request->settledAt) : null;
            if (null !== $answering) {
                $this->keepQuiet($run, $rule, $answering->id);
                continue;
            }

            $this->write($run, $state, fn (WorkflowRuleState $state) => $this->refuse($run, $rule, $state, $policy, $code, $bound));
        }
    }

    /**
     * Counts the stop of a resumed run that answered a stop of the rule, because a resumed run holds no work request that could settle as refused.
     * The answered stop cleared the attempts, so the run earns the retries of a fresh budget.
     * A run that is still open waits. Any other end, a run that is gone, or a change of subject clears the stored run and counts nothing.
     * A run that never started counts as a stop.
     */
    private function readAnsweringRuns(Evaluation $run): void
    {
        $policy = $run->template->onWorkFailed;
        if (null === $policy) {
            return;
        }
        foreach ($run->template->rules as $rule) {
            $state = $run->states[$rule->id] ?? null;
            if ($run->ended || null === $state || null === $state->answeringRunId || !$run->applies($rule) || $this->waits($run, $rule)) {
                continue;
            }
            $bound = $this->ruleSubject->bind($rule, $run->facts);
            if (!$bound->truth) {
                continue;
            }
            // The rule now acts on another pull request, so the run answered a stop of the old one and counts for nothing.
            $stored = $state->subjectPullRequestId;
            if ($bound->binds && null !== $stored && null !== $bound->subject && !$stored->equals($bound->subject)) {
                $this->write($run, $state, static function (WorkflowRuleState $state): void {
                    $state->answeringRunId = null;
                });
                continue;
            }
            $answering = $this->ledger->run($state->answeringRunId);
            if (null !== $answering && $answering->open) {
                continue;
            }
            $code = null !== $answering && ($answering->stop || self::NOT_STARTED === $answering->state) ? $answering->state : null;
            $this->write($run, $state, function (WorkflowRuleState $state) use ($run, $rule, $policy, $bound, $code): void {
                $state->answeringRunId = null;
                if (null !== $code) {
                    $this->refuse($run, $rule, $state, $policy, $code, $bound);
                }
            });
        }
    }

    /** Earns a retry after the next delay, or opens the repair, or pauses the card, as the failure block says. */
    private function refuse(Evaluation $run, Rule $rule, WorkflowRuleState $state, WorkFailurePolicy $policy, string $code, BoundRule $bound): void
    {
        $state->lastRefusal = $code;
        $state->lastRefusalAt = $run->now;
        // The facts at the refusal, so a request that was open at the last pass does not count as a change.
        $state->fingerprint = $this->fingerprint->of($bound->facts, $rule->when->reads());
        if (!$policy->retries($code)) {
            $this->pause($run, PauseKind::WorkStopped, $code, $rule->id);

            return;
        }
        ++$state->attempts;
        // The failed run did no work of its own, so a retry never uses a request of the work limit.
        $state->fires = max(0, $state->fires - 1);
        $backoff = $policy->backoffMinutes[$state->attempts - 1] ?? null;
        if ($state->attempts > $policy->retries || null === $backoff) {
            $state->dueAt = null;
            if (null !== $policy->repairKind && !$state->repaired) {
                // The prompt of an app rule asks for the work of that rule, so the repair carries the prompt of its own kind.
                $context = $rule->context($run->card, $bound->facts, $state->fires, $this->contexts->columns($rule, $run->card->projectId));
                // The hold read shares this transaction, so the mark below is flushed before a hold end can read it.
                if (null !== $this->workHolds->heldProject($run->card->projectId)) {
                    $this->markWorkHold($run, $state);

                    return;
                }
                $outcome = $this->opener->open($context, $policy->repairKind, null, $code);
                if (null !== $outcome->requestId) {
                    $state->workRequestId = $outcome->requestId;
                    $state->repaired = true;
                    $run->repairing[$rule->id] = true;
                    $this->logger->info('workflow.repair_requested', ['cardId' => $run->card->id->toRfc4122(), 'ruleId' => $rule->id, 'code' => $code]);
                    // The rules after this one read the new request.
                    $run->facts = $this->facts($run->card, $run->now, $run->facts);

                    return;
                }
            }
            $this->pause($run, PauseKind::Retries, $code, $rule->id);

            return;
        }
        $state->dueAt = $run->now->add(new \DateInterval(\sprintf('PT%dM', $backoff)));
    }

    /**
     * Cancels the live requests of rules that no longer apply or whose `cancelWhen` holds (only the open ones with `cancelOnly: open`), and expires the open ones no bridge took in time.
     * An expiry pauses the card, unless the rule expires its work with no pause.
     * A rule with `onNoTaker: fail` expires an open request at once when no online bridge could take it, with no pause.
     * A baseline pauses nothing, so it leaves an overdue request to the next pass.
     */
    private function settleWorkRequests(Evaluation $run, Uuid $cardId, bool $expire = true): void
    {
        $withdrawn = false;
        $expired = [];
        foreach ($this->ledger->live($cardId) as $request) {
            $requestId = $request->id;
            if (PersonWork::owns($request->ruleId)) {
                continue;
            }
            $rule = $run->rule($request->ruleId);
            if (null === $rule || !$run->applies($rule)) {
                $withdrawn = $this->ledger->withdraw($requestId, WithdrawKind::Cancelled) || $withdrawn;
                continue;
            }
            if (null !== $rule->lead->cancelWhen && $this->ruleSubject->bindWhen($rule->lead->cancelWhen, $run->facts)->truth) {
                // `cancelOnly: open` leaves a request that a bridge claimed alone, because that claim serves a live launch.
                $openOnly = 'open' === ActionParams::optionalString($rule, 'cancelOnly');
                if (!$openOnly || WorkState::Open === $request->state) {
                    $withdrawn = $this->ledger->withdraw($requestId, WithdrawKind::Cancelled, onlyIfOpen: $openOnly) || $withdrawn;
                    continue;
                }
            }

            // The template decides that a request no bridge could take fails at once, with no pause.
            if ($expire && WorkState::Open === $request->state && 'fail' === ActionParams::optionalString($rule, 'onNoTaker')) {
                $reason = $this->ledger->noTakerReason($requestId);
                if (null !== $reason && $this->ledger->withdraw($requestId, WithdrawKind::Expired, $reason, onlyIfOpen: true)) {
                    $withdrawn = true;
                    continue;
                }
            }

            $deadline = ($request->reopenedAt ?? $request->createdAt)->add(new \DateInterval(\sprintf('PT%dM', $run->template->workTimeoutMinutes)));
            if ($expire && WorkState::Open === $request->state && $deadline <= $run->now
                && $this->ledger->withdraw($requestId, WithdrawKind::Expired, onlyIfOpen: true)) {
                $withdrawn = true;
                // A repair always pauses, because nothing else ends its escalation.
                $state = $run->states[$rule->id] ?? null;
                $repair = null !== $state && $state->repaired && null !== $state->workRequestId && $state->workRequestId->equals($requestId);
                if ($repair || 'expire' !== ActionParams::optionalString($rule, 'onTimeout')) {
                    $this->pause($run, PauseKind::WorkTimeout, self::NO_BRIDGE_TOOK_WORK, $rule->id);
                    $expired[$rule->id] = $rule;
                }
            }
        }

        if ($withdrawn) {
            // The first build of the pass logged its failed sources already.
            $run->facts = $this->facts($run->card, $run->now, $run->facts);
        }
        // The facts after the expiry, so the closed request does not end the pause it made.
        foreach ($expired as $rule) {
            $this->write($run, $this->state($run, $rule), function (WorkflowRuleState $state) use ($run, $rule): void {
                $state->fingerprint = $this->fingerprint->of($this->ruleSubject->bind($rule, $run->facts)->facts, $rule->when->reads());
            });
        }
    }

    /** Releases the active pause when its condition is met. Answers whether the card stays paused. */
    private function stillPaused(Evaluation $run): bool
    {
        $pause = $this->cardPauses->findActive($run->card->id);
        if (null === $pause) {
            return false;
        }
        $resumed = $this->resumed($run, $pause);
        $code = null !== $resumed ? self::RUN_RESUMED : $this->releaseCode($run, $pause);
        if (null === $code) {
            $run->holdingPause = $pause;

            return true;
        }

        // A null release means another writer ended the pause and recorded it.
        $released = $this->cardPauses->release($pause, $code);
        $run->factsStale = true;
        if (null !== $released) {
            $this->cardPauses->recordReleased($released, Actor::System, null);
        }
        $rule = $run->rule($pause->ruleId);
        if (null !== $resumed && null !== $rule) {
            $this->keepQuiet($run, $rule, $resumed->id);
        }
        if (self::REFILLED === $code && null !== $rule) {
            $this->write($run, $this->state($run, $rule), static function (WorkflowRuleState $state): void {
                $state->fires = 0;
            });
        }
        if (\in_array($code, ['facts-changed', self::SUBJECT_CHANGED], true) && null !== $rule) {
            // A false truth makes the rule fire again at once, with a fresh backoff.
            // The subject and its count stay, so runRule() resets the count only when the new subject gets its request.
            $this->write($run, $this->state($run, $rule), static function (WorkflowRuleState $state): void {
                $state->truth = false;
                $state->attempts = 0;
                $state->dueAt = null;
                $state->workRequestId = null;
                $state->repaired = false;
            });
        }

        return false;
    }

    /**
     * The worker run that ran or runs again on an earlier run since the pause began, which a person or a closed ask started.
     * A short run can end before the evaluation, so the run counts open or ended. A run that a person stopped does not count.
     * A pause for a stop that waits for a person also ends for a resumed run of another rule, when it does the same kind of work.
     */
    private function resumed(Evaluation $run, PauseView $pause): ?RunView
    {
        $rule = $run->rule($pause->ruleId);
        if (null === $rule || !$run->applies($rule) || !\in_array($pause->kind, ReleaseWorkflowPauseCommand::RELEASABLE_KINDS, true)) {
            return null;
        }
        if (PauseKind::WorkStopped === $pause->kind && self::NEEDS_PERSON === $pause->reason) {
            return $this->answeringRun($run, $rule, $pause->createdAt);
        }

        return $this->ledger->latestContinuation($run->card->id, $pause->createdAt, $pause->ruleId);
    }

    /** The newest resumed run of the card that reported since the time and does the work kind of the rule, from any rule. */
    private function answeringRun(Evaluation $run, Rule $rule, ?\DateTimeImmutable $since): ?RunView
    {
        $workKind = $this->actions->get($rule->lead->key)->workKind($rule->lead->params);

        return null === $workKind ? null : $this->ledger->latestContinuation($run->card->id, $since, null, $workKind);
    }

    /**
     * Leaves the rule true with a clean budget, so it fires no request next to the resumed worker, and stores that worker.
     * A person's release makes the rule false instead, which fires it again at once.
     * The kept request and the refusal time with no refusal keep the old refusal from counting twice.
     */
    private function keepQuiet(Evaluation $run, Rule $rule, Uuid $answeringRunId): void
    {
        $this->write($run, $this->state($run, $rule), function (WorkflowRuleState $state) use ($run, $rule, $answeringRunId): void {
            $state->answeringRunId = $answeringRunId;
            $bound = $this->ruleSubject->bind($rule, $run->facts);
            $state->truth = $bound->truth;
            $state->fingerprint = $this->fingerprint->of($bound->facts, $rule->when->reads());
            $state->attempts = 0;
            $state->dueAt = null;
            $state->lastRefusal = null;
            $state->lastRefusalAt = $run->now;
            $state->repaired = false;
        });
    }

    /** Runs the release rules of a paused card. Answers whether they lifted the pause. */
    private function releasedByRule(Evaluation $run): bool
    {
        $this->runRules($run, array_filter($run->template->rules, static fn (Rule $rule): bool => ReleasePause::KEY === $rule->lead->key));

        $run->holdingPause = $this->cardPauses->findActive($run->card->id);

        return null === $run->holdingPause;
    }

    private function releaseCode(Evaluation $run, PauseView $pause): ?string
    {
        $rule = $run->rule($pause->ruleId);
        if (null === $rule) {
            return 'rule-removed';
        }
        $applies = $run->applies($rule);
        $bound = $this->ruleSubject->bind($rule, $run->facts);
        $stored = ($run->states[$rule->id] ?? null)?->subjectPullRequestId;
        if (PauseKind::Rule !== $pause->kind && $applies && $bound->binds && $bound->truth
            && null !== $stored && null !== $bound->subject && !$stored->equals($bound->subject)) {
            return self::SUBJECT_CHANGED;
        }
        if (PauseKind::WorkStopped === $pause->kind && self::NEEDS_PERSON === $pause->reason) {
            return $this->needsPersonRelease($run, $applies, $stored);
        }

        return match ($pause->kind) {
            PauseKind::Rule => match (true) {
                Ask::KEY === $rule->lead->key => match (true) {
                    !$applies => 'facts-changed',
                    null !== $rule->when->unreadable($run->facts) => null,
                    !$bound->truth => 'facts-changed',
                    $this->ruleAsks->isOn($run->card->projectId) => 'inbox-on',
                    default => null,
                },
                null === $rule->lead->until => 'rule-removed',
                null !== $rule->lead->until->unreadable($run->facts) => null,
                $rule->lead->until->evaluate($this->ruleSubject->paused($rule, $run->facts, $stored)) => 'until-met',
                default => null,
            },
            PauseKind::WorkLimit => match (true) {
                !$applies => 'left-slot',
                $this->refills($run, $rule, $stored) => self::REFILLED,
                default => null,
            },
            PauseKind::Retries, PauseKind::WorkTimeout, PauseKind::WorkStopped => match (true) {
                !$applies => 'facts-changed',
                null !== $rule->when->unreadable($run->facts) => null,
                !$bound->truth,
                !$this->fingerprint->sameAs(($run->states[$rule->id] ?? null)?->fingerprint, $bound->facts, $rule->when->reads()) => 'facts-changed',
                default => null,
            },
        };
    }

    /**
     * New facts, such as a new head or a new failed review, do not answer a worker that waits for a person, so they leave the pause.
     * The pause ends by itself only when its work is gone: the card left the slot of the rule, or the pull request of the rule closed.
     */
    private function needsPersonRelease(Evaluation $run, bool $applies, ?Uuid $stored): ?string
    {
        if (!$applies) {
            return self::LEFT_SLOT;
        }
        $subject = null === $stored ? null : array_find($run->facts->pullRequests(), static fn (PullRequestFacts $pullRequest): bool => $stored->equals($pullRequest->id));

        return null !== $subject && PullRequestState::Open !== $subject->state ? self::PULL_REQUEST_CLOSED : null;
    }

    /** @param array<Rule> $rules */
    private function runRules(Evaluation $run, array $rules): void
    {
        foreach ($rules as $rule) {
            if (!$run->applies($rule)) {
                $state = $run->states[$rule->id] ?? null;
                if (null !== $state) {
                    $this->write($run, $state, $this->forget(...));
                }
                continue;
            }
            // Before state(), which persists a new state: a rule that waits writes none.
            if ($this->waits($run, $rule)) {
                continue;
            }

            $continue = true;
            $this->write($run, $this->state($run, $rule), function (WorkflowRuleState $state) use ($run, $rule, &$continue): void {
                $continue = $this->runRule($run, $rule, $state);
            });
            if (!$continue) {
                return;
            }
        }
    }

    /** Answers whether the rules after this one still run. */
    private function runRule(Evaluation $run, Rule $rule, WorkflowRuleState $state): bool
    {
        // The bound facts stay local to this rule: the rules after it read the facts as built.
        $bound = $this->ruleSubject->bind($rule, $run->facts);
        $fingerprint = $this->fingerprint->of($bound->facts, $rule->when->reads());
        if ($this->refills($run, $rule, $state->subjectPullRequestId)) {
            $state->fires = 0;
        }
        if (!$bound->truth) {
            // The reset forgets the repair, so its live request ends with it.
            $tracked = $state->repaired && null !== $state->workRequestId ? $this->ledger->find($state->workRequestId) : null;
            if (null !== $tracked && $tracked->kind === $run->template->onWorkFailed?->repairKind
                && $this->ledger->withdraw($state->workRequestId, WithdrawKind::Cancelled)) {
                // The rules after this one read the cancelled request.
                $run->facts = $this->facts($run->card, $run->now, $run->facts);
            }
            $this->withdrawAsk($state, self::NO_LONGER_HOLDS);
            $state->truth = false;
            $state->heldByWorkHoldSince = null;
            $this->markBlockerHold($run, $rule, $state, $bound);
            $state->attempts = 0;
            $state->dueAt = null;
            $state->lastRefusal = null;
            $state->lastRefusalAt = null;
            $state->workRequestId = null;
            $state->answeringRunId = null;
            $state->repaired = false;
            // The subject stays, so a later true pass on another pull request starts a fresh budget.
            $state->fingerprint = $fingerprint;

            return true;
        }

        // A state with no subject adopts the bound one with no edge.
        $stored = $state->subjectPullRequestId;
        $newSubject = $bound->binds && null !== $stored && null !== $bound->subject && !$stored->equals($bound->subject);
        $fire = !isset($run->repairing[$rule->id])
            && ($newSubject || !$state->truth || ($state->attempts > 0 && ((null !== $state->dueAt && $state->dueAt <= $run->now) || !$this->fingerprint->sameAs($state->fingerprint, $bound->facts, $rule->when->reads()))));
        // A release rule that turned true before its pause existed would otherwise wait for a new edge.
        if (!$fire && ReleasePause::KEY === $rule->lead->key) {
            $fire = null !== $run->holdingPause && $run->holdingPause->reason === ActionOutcome::code(ActionParams::string($rule, 'reason'));
        }
        // A true rule whose action asks for it fires again when its facts change, so no new item waits for a false pass.
        if (!$fire && !isset($run->repairing[$rule->id]) && $this->refiresOnFactChange($rule)
            && !$this->fingerprint->sameAs($state->fingerprint, $bound->facts, $rule->when->reads())) {
            $fire = true;
        }
        $state->truth = true;
        $this->markBlockerHold($run, $rule, $state, $bound);
        $state->fingerprint = $fingerprint;
        // A live repair still serves the old subject.
        if (!isset($run->repairing[$rule->id])) {
            self::adoptSubject($state, $bound->subject);
        }
        if (!$fire) {
            return true;
        }
        // Another pull request gets its own request budget.
        $firesBefore = $state->fires;
        $repairedBefore = $state->repaired;
        if ($newSubject) {
            $state->fires = 0;
            $state->repaired = false;
        }

        // A retry keeps its attempts while its request runs, so the count reaches the limit of the template.
        // The last try after a repair keeps them too, so its refusal pauses the card.
        $retrying = !$newSubject && (null !== $state->workRequestId || $state->repaired) && $state->attempts > 0;
        // The list stops at the first refusal and after a move, which queues the next evaluation.
        // An action that changes the facts gives the next action of the list the facts as they are then.
        $facts = $bound->facts;
        $refreshesFacts = false;
        $lastKey = array_key_last($rule->then);
        foreach ($rule->then as $key => $call) {
            $outcome = $this->actions->get($call->key)->run($this->contexts->for($rule->forCall($call), $run->card, $facts, $state->fires));
            $refreshesFacts = $refreshesFacts || $call->traits->refreshesFacts;
            if (ActionOutcomeKind::Done !== $outcome->kind || $call->traits->endsPass) {
                break;
            }
            if ($call->traits->refreshesFacts && $key !== $lastKey) {
                $run->facts = $this->facts($run->card, $run->now, $run->facts);
                $facts = $this->ruleSubject->bind($rule, $run->facts)->facts;
            }
        }
        // The live request still serves the old subject, so the change waits until it settles.
        if ($newSubject && $outcome->alreadyLive) {
            self::adoptSubject($state, $stored);
            $state->fires = $firesBefore;
            $state->repaired = $repairedBefore;
        }
        $run->fired[] = ['rule' => $rule->id, 'outcome' => $outcome->kind->value, 'code' => $outcome->code];
        if (ActionOutcomeKind::Held !== $outcome->kind) {
            $state->heldByWorkHoldSince = null;
        }
        $run->factsStale = true;
        // An action can release the pause that holds the card, and the view of it is a copy.
        if (null !== $run->holdingPause && null === $this->cardPauses->findActive($run->card->id)) {
            $run->holdingPause = null;
        }

        switch ($outcome->kind) {
            case ActionOutcomeKind::Done:
                // Another rule's request does other work, so the rule tries again on each pass until it opens its own.
                // The opener names the rule of the live request under its lock, so a request that settles afterwards changes nothing.
                // The join counts nothing, and keeps the refusal that a retry read, so that refusal is not counted twice.
                if ($outcome->alreadyLive && $outcome->liveRuleId !== $rule->id) {
                    $state->truth = false;

                    return true;
                }
                // New work of its own replaces the work the stored run answered.
                if (null !== $outcome->requestId || ($newSubject && !$outcome->alreadyLive)) {
                    $state->answeringRunId = null;
                }
                if (!$retrying || (null === $outcome->requestId && !$outcome->alreadyLive)) {
                    $state->attempts = 0;
                }
                $state->dueAt = null;
                $state->lastRefusal = null;
                $state->lastRefusalAt = null;
                if (null !== $outcome->requestId) {
                    $state->workRequestId = $outcome->requestId;
                    $state->causesAtOpen = $rule->when->holdingParts($bound->facts);
                } elseif (!$outcome->alreadyLive) {
                    $state->workRequestId = null;
                } elseif (null === $state->workRequestId && $outcome->liveRuleId === $rule->id) {
                    // The rule turned false and true again while its own request ran, so it takes that request back.
                    $state->workRequestId = $this->ownLiveRequest($run, $rule)?->id;
                }
                if (null !== $outcome->askItemId) {
                    $state->askItemId = $outcome->askItemId;
                }
                if ($call->traits->countsTowardLimit && !$outcome->alreadyLive) {
                    ++$state->fires;
                }
                // The rules after this one read the new request.
                if ($refreshesFacts && !$outcome->alreadyLive) {
                    $run->facts = $this->facts($run->card, $run->now, $run->facts);
                }

                // The move queues the next evaluation, and the rules after it would read the old slot.
                return !$call->traits->endsPass;
            case ActionOutcomeKind::Held:
                $this->markWorkHold($run, $state);

                return true;
            case ActionOutcomeKind::Refused:
                $code = $outcome->code ?? throw new \LogicException('A refusal carries a code.');
                ++$state->attempts;
                $state->lastRefusal = $code;
                $state->lastRefusalAt = $run->now;
                $backoff = $run->template->backoffMinutes[$state->attempts - 1] ?? null;
                // The last try after a repair gets no further try.
                if (null !== $backoff && !$state->repaired) {
                    $state->dueAt = $run->now->add(new \DateInterval(\sprintf('PT%dM', $backoff)));

                    return true;
                }
                $state->dueAt = null;
                $this->pause($run, PauseKind::Retries, $code, $rule->id);

                return false;
            case ActionOutcomeKind::Pause:
                // The pause ends when its cause goes, and the rule must then fire again.
                if (Ask::KEY === $call->key) {
                    $state->truth = false;
                }
                $this->pause(
                    $run,
                    $outcome->pauseKind ?? throw new \LogicException('A pause outcome carries a kind.'),
                    $outcome->code ?? throw new \LogicException('A pause outcome carries a code.'),
                    $rule->id,
                );

                return false;
        }
    }

    /** Logs each source that failed, unless it had already failed in the previous build of this evaluation. A source that is off is not an error. */
    private function facts(CardSnapshot $card, \DateTimeImmutable $now, ?Facts $previous = null): Facts
    {
        $facts = $this->factsBuilder->build($card, $now);
        foreach ($facts->provided as $class => $provided) {
            if ($provided instanceof Unreadable && UnreadableKind::Failed === $provided->kind
                && UnreadableKind::Failed !== $previous?->unreadable($class)?->kind) {
                $this->logger->error('workflow.fact_source_failed', [
                    'cardId' => $card->id->toRfc4122(),
                    'source' => $provided->source,
                    'exception' => $provided->cause,
                ]);
            }
        }

        return $facts;
    }

    /** Answers whether the refill of the rule holds on its stored subject. A rule with no refill, or no readable subject, never refills. */
    private function refills(Evaluation $run, Rule $rule, ?Uuid $stored): bool
    {
        $refill = $rule->lead->refill;
        if (null === $refill || null !== $refill->unreadable($run->facts)) {
            return false;
        }
        $subject = $this->ruleSubject->stored($run->facts, $stored);

        return null !== $subject && $refill->evaluate($subject);
    }

    /**
     * The project holds its new work, so the rule opened nothing and counts no attempt and no backoff. It turns false,
     * so it fires again when the hold ends. The stamp keeps the due-card search from returning it until then.
     */
    private function markWorkHold(Evaluation $run, WorkflowRuleState $state): void
    {
        $state->truth = false;
        $state->dueAt = null;
        $state->heldByWorkHoldSince ??= $run->now;
    }

    /** Stamps the first pass in which only an open blocker keeps a move rule false, and clears the stamp on the pass in which it does not. */
    private function markBlockerHold(Evaluation $run, Rule $rule, WorkflowRuleState $state, BoundRule $bound): void
    {
        $held = !$bound->truth && $rule->endsPass() && 1 === $rule->when->countAgainst($bound->facts, true);
        if ($held) {
            $blocking = $rule->when->firstFalseLeaf($bound->facts);
            $held = null !== $blocking && $blocking->negated && self::OPEN_BLOCKER === $blocking->leaf->condition::key();
        }
        $state->heldByBlockerSince = $held ? ($state->heldByBlockerSince ?? $run->now) : null;
    }

    /** Logs once per evaluation each action of a rule of the card that this version does not know. */
    private function logMissingActions(Evaluation $run): void
    {
        foreach ($run->template->rules as $rule) {
            if ($run->applies($rule) && MissingAction::KEY === $rule->lead->key) {
                $this->logger->warning('workflow.action_missing', ['cardId' => $run->card->id->toRfc4122(), 'ruleId' => $rule->id, 'action' => $rule->lead->params['name'] ?? null]);
            }
        }
    }

    /** Logs once per evaluation each facts class that a rule of the card reads and no provider gives. */
    private function logMissingProviders(Evaluation $run): void
    {
        $missing = [];
        foreach ($run->template->rules as $rule) {
            if (!$run->applies($rule)) {
                continue;
            }
            foreach ([...$rule->when->reads(), ...($rule->lead->until?->reads() ?? []), ...($rule->lead->refill?->reads() ?? []), ...($rule->lead->cancelWhen?->reads() ?? [])] as $key) {
                if (\is_string($key) && null === $run->facts->keyFor($key)) {
                    $missing[$key] = true;
                }
            }
        }
        foreach (array_keys($missing) as $class) {
            $this->logger->error('workflow.fact_provider_missing', ['cardId' => $run->card->id->toRfc4122(), 'factsClass' => $class]);
        }
    }

    /**
     * A rule waits when it cannot read its facts. A pause whose until cannot be read could never release,
     * so its rule waits while it is true. A false one still records its edge.
     * A refill that cannot be read makes the rule wait too, like a when that cannot be read.
     */
    private function waits(Evaluation $run, Rule $rule): bool
    {
        return null !== $rule->when->unreadable($run->facts)
            || null !== $rule->lead->refill?->unreadable($run->facts)
            || (null !== $rule->lead->until?->unreadable($run->facts) && $this->ruleSubject->bind($rule, $run->facts)->truth);
    }

    /** Withdraws the ask of a state and clears its item id. An item that was answered is closed already, so the withdrawal changes nothing on it. */
    private function withdrawAsk(WorkflowRuleState $state, string $reason): void
    {
        if (null === $state->askItemId) {
            return;
        }
        $this->ruleAsks->withdraw($state->askItemId, $reason);
        $state->askItemId = null;
    }

    private function forget(WorkflowRuleState $state): void
    {
        $this->withdrawAsk($state, self::LEFT_SLOT);
        $state->reset();
    }

    /** Withdraws the ask and ends the holds of a rule that the template no longer has, because no pass reaches its state. */
    private function withdrawRemovedAsks(Evaluation $run): void
    {
        foreach ($run->states as $ruleId => $state) {
            if ((null !== $state->askItemId || null !== $state->heldByBlockerSince || null !== $state->heldByWorkHoldSince) && null === $run->rule($ruleId)) {
                $this->write($run, $state, function (WorkflowRuleState $state): void {
                    $this->withdrawAsk($state, self::RULE_REMOVED);
                    $state->heldByBlockerSince = null;
                    $state->heldByWorkHoldSince = null;
                });
            }
        }
    }

    private function state(Evaluation $run, Rule $rule): WorkflowRuleState
    {
        if (!isset($run->states[$rule->id])) {
            $state = new WorkflowRuleState($run->card->id, $run->project, $rule->id, $run->now);
            $this->em->persist($state);
            $run->states[$rule->id] = $state;
        }

        return $run->states[$rule->id];
    }

    /** @param \Closure(WorkflowRuleState): void $change */
    private function write(Evaluation $run, WorkflowRuleState $state, \Closure $change): void
    {
        $before = self::snapshot($state);
        $heldBefore = null !== $state->heldByBlockerSince;
        $change($state);
        if (self::snapshot($state) !== $before) {
            $state->updatedAt = $run->now;
            $run->factsStale = true;
        }
        if ($heldBefore !== (null !== $state->heldByBlockerSince)) {
            $run->holdChanged = true;
        }
    }

    /** @return list<mixed> */
    private static function snapshot(WorkflowRuleState $state): array
    {
        return [$state->truth, $state->attempts, $state->fires, $state->fingerprint, $state->dueAt?->format('U.u'), $state->lastRefusal, $state->lastRefusalAt?->format('U.u'), $state->subjectPullRequestId?->toRfc4122(), $state->workRequestId?->toRfc4122(), $state->causesAtOpen, $state->repaired, $state->askItemId?->toRfc4122(), $state->heldByBlockerSince?->format('U.u'), $state->heldByWorkHoldSince?->format('U.u'), $state->answeringRunId?->toRfc4122()];
    }

    private function pause(Evaluation $run, PauseKind $kind, string $code, string $ruleId): void
    {
        $run->ended = true;
        $run->factsStale = true;
        $pause = $this->cardPauses->pause($run->card, $code, $ruleId, $kind);
        if (null !== $pause) {
            $run->pauses[] = $pause;
        }
    }

    private function refiresOnFactChange(Rule $rule): bool
    {
        $action = $this->actions->get($rule->lead->key);

        return $action instanceof RefiresOnFactChange && $action::refiresOnFactChange($rule->lead->params);
    }

    private function retriesWhileTrue(Rule $rule): bool
    {
        $action = $this->actions->get($rule->lead->key);

        return $action instanceof RetriesWhileTrue && $action::retriesWhileTrue($rule->lead->params);
    }
}
