<?php

declare(strict_types=1);

namespace App\Module\Inbox\Service\Dev;

use App\DevSeed\DevSeederInterface;
use App\DevSeed\DevSeedId;
use App\DevSeed\SeedContext;
use App\Module\Account\Entity\User;
use App\Module\Admin\Service\Dev\DevFeatureFlagSeeder;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardDocument;
use App\Module\Board\Entity\CardPullRequest;
use App\Module\Board\Entity\CardSiteReviewComment;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Repository\CardSiteReviewCommentRepository;
use App\Module\Board\Service\Dev\DevBoardSeeder;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkerRunOutputChunk;
use App\Module\Bridge\Entity\WorkerRunStateChange;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\Repository\BridgeRepository;
use App\Module\Bridge\ValueObject\WorkerRunKind;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Inbox\Entity\InboxAction;
use App\Module\Inbox\Entity\InboxActionKind;
use App\Module\Inbox\Entity\InboxAsk;
use App\Module\Inbox\Entity\InboxAskItem;
use App\Module\Inbox\Entity\InboxAskOrigin;
use App\Module\Inbox\Entity\InboxCloser;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemCard;
use App\Module\Inbox\Entity\InboxItemDocument;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\Entity\InboxReview;
use App\Module\Inbox\Entity\InboxReviewVerdict;
use App\Module\Inbox\Repository\InboxItemRepository;
use App\Module\Inbox\Service\InboxSearchIndexer;
use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use App\Module\Project\Service\Dev\DevProjectSeeder;
use App\Module\Review\Entity\Document;
use App\Module\Review\Repository\DocumentRepository;
use App\Module\Review\Service\Dev\DevDocumentSeeder;
use App\Module\SiteReview\Entity\SiteReviewComment;
use App\Module\SiteReview\Service\Dev\DevSiteFeedbackSeeder;
use App\Module\Workflow\Contract\CardEvaluations;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\Uid\Uuid;

/**
 * Writes the open and completed requests of the dev project, and the links
 * that tie a card to its document and to its site feedback. It lives in Inbox
 * because only Board, Inbox, Readiness and AgentReview may import Board.
 *
 * It writes every request directly, so they exist whatever inbox.enabled
 * says. The wait item of the onboarding card is the one exception: the
 * workflow engine opens it, and only while the flag is on.
 */
#[When('dev')]
#[When('test')]
final readonly class DevInboxSeeder implements DevSeederInterface
{
    public const string KEY = 'inbox';

    public const string MARKER_TITLE = 'How should checkout retries work?';

    private const string CLI_VERSION = 'b4e39aa7';

    public function __construct(
        private EntityManagerInterface $em,
        private ProjectRepository $projects,
        private CardRepository $cards,
        private DocumentRepository $documents,
        private InboxItemRepository $inboxItems,
        private BridgeRepository $bridges,
        private CardSiteReviewCommentRepository $cardSiteReviewComments,
        private InboxSearchIndexer $inboxSearch,
        private CardEvaluations $evaluations,
    ) {
    }

    #[\Override]
    public function key(): string
    {
        return self::KEY;
    }

    #[\Override]
    public function dependsOn(): array
    {
        return [DevBoardSeeder::KEY, DevDocumentSeeder::KEY, DevSiteFeedbackSeeder::KEY, DevFeatureFlagSeeder::KEY];
    }

    #[\Override]
    public function seed(SeedContext $context): void
    {
        $project = $this->projects->find(DevProjectSeeder::devProjectId())
            ?? throw new \LogicException('The dev project does not exist.');
        $owner = $context->devUser();
        $reviewer = $context->adminUser();

        $checkout = $this->card('checkout');
        $history = $this->card('history');
        $columnRules = $this->card('column-rules');
        $onboarding = $this->card('onboarding');
        $basket = $this->card('empty-basket');
        $historyDocument = $this->document('worker-run-history');
        $rulesDocument = $this->document('column-rules');
        $handoffDocument = $this->document('first-handoff');

        $this->linkDocument($context, $onboarding, $handoffDocument);
        $this->linkFeedback($checkout, $basket);

        $new = [];
        array_push($new, ...$this->seedOpenRequests($context, $project, $checkout, $history, $columnRules, $onboarding, $historyDocument, $rulesDocument));
        array_push($new, ...$this->seedCompleted($context, $project, $reviewer, $rulesDocument));
        array_push($new, ...$this->seedSessionPromptItems($context, $project, $owner, $onboarding));
        array_push($new, ...$this->seedCommandRuns($context, $project, $owner, $onboarding));
        $this->em->flush();

        foreach ($new as $item) {
            $this->inboxSearch->index($item);
        }

        $this->evaluations->forCards(array_map(
            static fn (Card $card): Uuid => $card->id ?? throw new \LogicException('A stored card has an id.'),
            $this->cards->findBy(['project' => $project]),
        ));
    }

    private function card(string $slug): Card
    {
        return $this->cards->find(DevBoardSeeder::cardId($slug)) ?? throw new \LogicException(\sprintf('The card %s does not exist.', $slug));
    }

    private function document(string $slug): Document
    {
        return $this->documents->find(DevDocumentSeeder::documentId($slug)) ?? throw new \LogicException(\sprintf('The document %s does not exist.', $slug));
    }

    private function linkDocument(SeedContext $context, Card $card, Document $document): void
    {
        if ($card->documents->isEmpty()) {
            $card->documents->add(new CardDocument($card, $document, $context->at(1_800)));
            $this->em->flush();
        }
    }

    /** The site comments sit on the checkout card, and the resolved one on a finished card. */
    private function linkFeedback(Card $checkout, Card $basket): void
    {
        foreach (DevSiteFeedbackSeeder::SLUGS as $slug) {
            $comment = $this->em->find(SiteReviewComment::class, DevSiteFeedbackSeeder::commentId($slug));
            if (!$comment instanceof SiteReviewComment || $this->cardSiteReviewComments->findOneBy(['comment' => $comment]) instanceof CardSiteReviewComment) {
                continue;
            }

            // The resolved comment sits on a finished site-review card, because finishing resolves it.
            $this->em->persist('wording' === $slug ? new CardSiteReviewComment($basket, $comment, true, $basket->title) : new CardSiteReviewComment($checkout, $comment));
        }
        $this->em->flush();
    }

    /** @return list<InboxItem> the items this call wrote */
    private function seedOpenRequests(SeedContext $context, Project $project, Card $checkout, Card $history, Card $columnRules, Card $onboarding, Document $historyDocument, Document $rulesDocument): array
    {
        $new = [];

        if (!$this->em->find(InboxAsk::class, DevSeedId::of('inbox/ask/retries')) instanceof InboxAsk) {
            $retries = $this->item($context, 'retries', $project, InboxItemKind::Question, self::MARKER_TITLE, true, 360, [
                'body' => 'The new checkout can retry a failed payment automatically, or leave the next attempt to the customer. Which behavior should I implement?',
                'options' => ['Retry once, then let the customer decide', 'Always let the customer retry'],
                'freeText' => true,
            ]);
            $retries->cards->add(new InboxItemCard($retries, $checkout));
            $this->ask($context, 'retries', $project, [$retries], 360, 'Working on the **checkout** card. I need one decision before I write the recovery flow.', DevSeedId::of('bridge/bridge/ask-retries'));
            $new[] = $retries;
        }

        if (!$this->em->find(InboxAsk::class, DevSeedId::of('inbox/ask/run-history')) instanceof InboxAsk) {
            $runHistory = $this->item($context, 'run-history', $project, InboxItemKind::Review, 'Worker run history is ready to review', false, 720, [
                'body' => 'Two sections changed. One comment needs your confirmation before I carry on.',
            ]);
            $runHistory->cards->add(new InboxItemCard($runHistory, $history));
            $runHistory->documents->add(new InboxItemDocument($runHistory, $historyDocument));
            $pullRequest = $this->item($context, 'pull-request', $project, InboxItemKind::Review, 'Review pull request 438', true, 7_200, [
                'body' => 'The subscription migration is ready. Approving here records your decision; it posts no review to the code host.',
            ]);
            $pullRequest->cards->add(new InboxItemCard($pullRequest, $checkout));
            $this->ask($context, 'run-history', $project, [$runHistory, $pullRequest], 720, 'The plan and the pull request are both ready for you. Either order is fine.', null);
            $this->em->persist(new InboxReview($runHistory, $historyDocument));
            $link = $checkout->pullRequests->first();
            if ($link instanceof CardPullRequest) {
                $this->em->persist(new InboxReview($pullRequest, $link));
            }
            array_push($new, $runHistory, $pullRequest);
        }

        if (!$this->em->find(InboxAsk::class, DevSeedId::of('inbox/ask/column-rules')) instanceof InboxAsk) {
            $rules = $this->item($context, 'column-rules', $project, InboxItemKind::Review, 'Safer column changes', false, 2_280, [
                'body' => 'Tester has a design specification for you. It settles what a rename does to a rule that points at the column.',
            ]);
            $rules->cards->add(new InboxItemCard($rules, $columnRules));
            $rules->documents->add(new InboxItemDocument($rules, $rulesDocument));
            $this->ask($context, 'column-rules', $project, [$rules], 2_280, 'A specification rather than code. Read it when the board work settles.', DevSeedId::of('bridge/bridge/ask-column-rules'));
            $this->em->persist(new InboxReview($rules, $rulesDocument));
            $new[] = $rules;
        }

        // No ask holds this one, so it is the open item the list shows on its own.
        if (!$this->em->find(InboxItem::class, DevSeedId::of('inbox/item/handoff')) instanceof InboxItem) {
            $handoff = $this->item($context, 'handoff', $project, InboxItemKind::Todo, 'Give the first handoff a quick look', true, 3_600, [
                'body' => 'Read the first useful handoff document and confirm that the first five minutes feel clear. Leave a note if a step still needs work.',
            ]);
            $handoff->cards->add(new InboxItemCard($handoff, $onboarding));
            $this->em->persist($handoff);
            $new[] = $handoff;
        }
        $this->em->flush();

        return $new;
    }

    /**
     * The completed queue: an answer, a decline, a recorded review verdict, and
     * a to-do left open inside a closed ask, which is where the loose row and
     * its jump link come from.
     *
     * @return list<InboxItem>
     */
    private function seedCompleted(SeedContext $context, Project $project, User $reviewer, Document $rulesDocument): array
    {
        $new = [];

        if (!$this->em->find(InboxAsk::class, DevSeedId::of('inbox/ask/export-source')) instanceof InboxAsk) {
            $answered = $this->item($context, 'answered', $project, InboxItemKind::Question, 'Which database should the export read from?', true, 172_800, [
                'body' => 'The replica lags by a few seconds. The primary is always current and costs more.',
                'options' => ['The read replica', 'The primary'],
                'freeText' => true,
            ]);
            $answered->state = InboxItemState::Resolved;
            $answered->closedBy = InboxCloser::Owner;
            $answered->selectedOptions = [0];
            $answered->note = 'A few seconds of lag is fine for an export.';
            $answered->closedAt = $context->at(172_800 - 1_800);

            $declined = $this->item($context, 'declined', $project, InboxItemKind::Todo, 'Write the release notes for 1.4', true, 172_740, [
                'body' => 'A short summary of the export work, for the changelog.',
            ]);
            $declined->state = InboxItemState::Declined;
            $declined->closedBy = InboxCloser::Owner;
            $declined->note = 'Someone else writes the notes this cycle. Ask again at 1.5.';
            $declined->closedAt = $context->at(172_800 - 2_700);

            $this->ask($context, 'export-source', $project, [$answered, $declined], 172_800, 'Two things before I open the export pull request.', DevSeedId::of('bridge/bridge/ask-export-source'), $context->at(172_800 - 2_700));
            array_push($new, $answered, $declined);
        }

        if (!$this->em->find(InboxAsk::class, DevSeedId::of('inbox/ask/spec-ready')) instanceof InboxAsk) {
            $reviewed = $this->item($context, 'reviewed', $project, InboxItemKind::Review, 'Column rules specification is ready', true, 259_200, [
                'body' => 'It answers what a rename does to a rule that points at the column.',
            ]);
            $reviewed->state = InboxItemState::Resolved;
            $reviewed->closedBy = InboxCloser::Owner;
            $reviewed->note = 'Say what happens to a rule whose column is deleted rather than renamed.';
            $reviewed->closedAt = $context->at(259_200 - 7_200);
            $review = new InboxReview($reviewed, $rulesDocument);
            $review->verdict = InboxReviewVerdict::ChangesRequested;
            $review->reviewer = $reviewer;
            $review->submittedAt = $context->at(259_200 - 7_200);
            $review->reviewedVersionNumber = 1;
            $this->em->persist($review);

            $leftOpen = $this->item($context, 'left-open', $project, InboxItemKind::Todo, 'Check the export against a real customer file', false, 259_140, [
                'body' => 'Nobody waits on this one, and it is still worth doing.',
            ]);

            $this->ask($context, 'spec-ready', $project, [$reviewed, $leftOpen], 259_200, 'The specification is ready to read.', DevSeedId::of('bridge/bridge/ask-spec-ready'), $context->at(259_200 - 7_200));
            array_push($new, $reviewed, $leftOpen);
        }
        $this->em->flush();

        return $new;
    }

    /**
     * A to-do and a question that suggest a session with a prompt, so the preview shows the field the owner edits.
     * The bridge that opens sessions stays live for years, because the field shows only while one is live.
     *
     * @return list<InboxItem>
     */
    private function seedSessionPromptItems(SeedContext $context, Project $project, User $owner, Card $card): array
    {
        if ($this->em->find(InboxAsk::class, DevSeedId::of('inbox/ask/session-rebuild')) instanceof InboxAsk) {
            return [];
        }

        $bridgeId = DevSeedId::of('bridge/bridge/session');
        if (!$this->bridges->findOneBy(['id' => $bridgeId]) instanceof Bridge) {
            $bridge = new Bridge($owner, $bridgeId, [(string) $project->id], self::CLI_VERSION, $context->now->modify('+10 years'));
            $bridge->capabilities = [Bridge::CAPABILITY_WORK_REQUESTS, Bridge::CAPABILITY_SESSION];
            $this->em->persist($bridge);
        }

        $item = $this->item($context, 'session-rebuild', $project, InboxItemKind::Todo, 'Rebuild the Design System artifact', false, 300, []);
        $item->suggestedActions = [['action' => 'start-session', 'reason' => 'The rebuild needs a session on your machine.', 'recommended' => true, 'cardId' => null, 'command' => null, 'proposedEntry' => null, 'prompt' => 'Use the loupe-design-rebuild skill in this checkout. First check that the branch is main, and run git pull --ff-only.']];
        $item->cards->add(new InboxItemCard($item, $card));
        $this->ask($context, 'session-rebuild', $project, [$item], 300, 'The rebuild needs a session.', null);

        $question = $this->item($context, 'session-checklist', $project, InboxItemKind::Question, 'Which steps does the onboarding checklist keep?', false, 240, [
            'options' => ['All five', 'Only the first three'],
            'freeText' => true,
        ]);
        $question->suggestedActions = [['action' => 'start-session', 'reason' => 'Walk through the checklist together.', 'recommended' => true, 'cardId' => null, 'command' => null, 'proposedEntry' => null, 'prompt' => 'Read the onboarding checklist card, and go through each step with the owner.']];
        $question->cards->add(new InboxItemCard($question, $card));
        $this->ask($context, 'session-checklist', $project, [$question], 240, 'The checklist needs a choice.', null);
        $this->em->flush();

        return [$item, $question];
    }

    /**
     * Two to-dos with a command run: one still running, one that succeeded. Each holds a stored output log.
     *
     * @return list<InboxItem>
     */
    private function seedCommandRuns(SeedContext $context, Project $project, User $owner, Card $card): array
    {
        $bridgeId = DevSeedId::of('bridge/bridge/design-export');
        $bridge = $this->bridges->findOneBy(['id' => $bridgeId]);
        if (!$bridge instanceof Bridge) {
            $bridge = new Bridge($owner, $bridgeId, [(string) $project->id], self::CLI_VERSION, $context->now);
            $bridge->capabilities = [Bridge::CAPABILITY_COMMANDS, Bridge::CAPABILITY_WORK_REQUESTS, Bridge::CAPABILITY_RUN_COMMAND];
            $bridge->lastSeenAt = $context->now;
            $bridge->commandEntries = [['name' => 'design-export', 'description' => null, 'params' => [['name' => 'out', 'pattern' => '[a-z/-]+', 'description' => null]], 'run' => ['bin/console', 'app:design-system:export', '{out}'], 'timeout' => '10m']];
            $bridge->worktrees = [['projectId' => (string) $project->id, 'branch' => 'main', 'path' => '/work/loupe']];
            $this->em->persist($bridge);
        }

        $new = [];
        foreach ([
            [WorkerRunState::Running, 'Export the design system for the rebuild', 12, 20],
            [WorkerRunState::Succeeded, 'Export the design system again', 40, 14],
        ] as $index => [$state, $title, $lines, $seconds]) {
            $slug = 'command-run-'.$index;
            $requestId = DevSeedId::of('bridge/work-request/'.$slug);

            if (!$this->em->find(WorkRequest::class, $requestId) instanceof WorkRequest) {
                $request = new WorkRequest(
                    project: $project,
                    subjectType: WorkSubject::CARD,
                    subjectId: $card->id ?? throw new \LogicException('A stored card has an id.'),
                    cardNumber: $card->number,
                    kind: 'design-export',
                    capability: Bridge::CAPABILITY_RUN_COMMAND,
                    ruleId: 'inbox:run-command',
                    createdAt: $context->at(60 - $index * 10),
                );
                $request->state = WorkerRunState::Running === $state ? WorkRequestState::Claimed : WorkRequestState::Done;
                $request->settledAt = WorkerRunState::Running === $state ? null : $context->now;
                $context->ids->assign($request, $requestId);
                $this->em->persist($request);
                $this->em->flush();
            }

            if (!$this->em->find(InboxAsk::class, DevSeedId::of('inbox/ask/'.$slug)) instanceof InboxAsk) {
                $item = $this->item($context, $slug, $project, InboxItemKind::Todo, $title, false, 600 - $index * 20, []);
                $item->suggestedActions = [['action' => 'run-command', 'reason' => 'The canvas needs the new tokens.', 'recommended' => true, 'cardId' => null, 'command' => ['entry' => 'design-export', 'branch' => 'main', 'params' => ['out' => 'var/design-system']], 'proposedEntry' => null]];
                $item->cards->add(new InboxItemCard($item, $card));
                $this->ask($context, $slug, $project, [$item], 600 - $index * 20, 'The export needs a run on your machine.', $bridge->id);

                $action = new InboxAction($item, InboxActionKind::RunCommand, $owner, '', $context->at(60 - $index * 10));
                $action->workRequestId = $requestId;
                $this->em->persist($action);
                $new[] = $item;
            }

            $runId = DevSeedId::of('bridge/run/'.$slug);
            if (!$this->em->find(WorkerRun::class, $runId) instanceof WorkerRun) {
                $startedAt = $context->at($seconds);
                $run = new WorkerRun(
                    project: $project,
                    bridgeId: $bridge->id,
                    subjectType: WorkSubject::CARD,
                    subjectId: $card->id ?? throw new \LogicException('A stored card has an id.'),
                    cardNumber: $card->number,
                    workKind: 'design-export',
                    state: $state,
                    runKey: DevSeedId::of('bridge/run-key/'.$slug),
                    startedAt: $startedAt,
                    endedAt: WorkerRunState::Running === $state ? null : $context->now,
                    exitCode: WorkerRunState::Running === $state ? null : 0,
                    kind: WorkerRunKind::Command,
                    workRequestId: $requestId,
                );
                $context->ids->assign($run, $runId);
                $this->em->persist($run);
                $this->em->persist(new WorkerRunStateChange($run, $state, $startedAt, $startedAt));

                $log = implode('', array_map(static fn (int $line): string => \sprintf("Exporting components %d/%d\n", $line, $lines), range(1, $lines)));
                $this->em->persist(new WorkerRunOutputChunk($run, 0, \strlen($log), $log));
            }
        }
        $this->em->flush();

        return $new;
    }

    /** @param array{body?: string, options?: list<string>, freeText?: bool} $fields */
    private function item(SeedContext $context, string $slug, Project $project, InboxItemKind $kind, string $title, bool $blocking, int $secondsAgo, array $fields): InboxItem
    {
        $item = new InboxItem(
            project: $project,
            number: $this->inboxItems->nextNumber($project),
            kind: $kind,
            title: $title,
            blocking: $blocking,
            origin: InboxAskOrigin::Agent,
            body: $fields['body'] ?? null,
            options: $fields['options'] ?? [],
            freeText: $fields['freeText'] ?? false,
            createdAt: $context->at($secondsAgo),
        );
        $context->ids->assign($item, DevSeedId::of('inbox/item/'.$slug));
        $this->em->persist($item);
        // The next number reads the stored items, so each item is stored before the next one asks.
        $this->em->flush();

        return $item;
    }

    /** @param list<InboxItem> $items */
    private function ask(SeedContext $context, string $slug, Project $project, array $items, int $secondsAgo, string $text, ?Uuid $bridgeId, ?\DateTimeImmutable $closedAt = null): void
    {
        $ask = new InboxAsk(
            project: $project,
            sessionId: DevSeedId::of('inbox/session/'.$slug),
            bridgeId: $bridgeId,
            context: $text,
            createdAt: $context->at($secondsAgo),
        );
        $ask->closedAt = $closedAt;
        foreach ($items as $item) {
            $ask->items->add(new InboxAskItem($ask, $item));
        }
        $context->ids->assign($ask, DevSeedId::of('inbox/ask/'.$slug));
        $this->em->persist($ask);
    }
}
