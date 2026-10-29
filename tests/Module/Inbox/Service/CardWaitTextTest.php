<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Service;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPause;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Inbox\Entity\InboxAskOrigin;
use App\Module\Inbox\Entity\InboxCardWait;
use App\Module\Inbox\Entity\InboxCardWaitType;
use App\Module\Inbox\Entity\InboxCardWatch;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Service\CardWaitText;
use App\Module\Inbox\Service\RunOutputText;
use App\Module\Review\Entity\Document;
use App\Module\Workflow\Contract\PauseKind;
use App\Tests\Module\Inbox\InboxFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/** The item title and body of a document wait whose document no longer exists. */
final class CardWaitTextTest extends KernelTestCase
{
    use InboxFixtures;

    private CardWaitText $text;
    private InboxCardWait $wait;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $text = self::getContainer()->get(CardWaitText::class);
        self::assertInstanceOf(CardWaitText::class, $text);
        $this->text = $text;

        $project = $this->project($em, $this->owner($em, 'wait-text'), 'wait-text');
        $item = new InboxItem(project: $project, number: 1, kind: InboxItemKind::Wait, title: '#4 Ship it', blocking: true, origin: InboxAskOrigin::Loupe);
        $watch = new InboxCardWatch($item, Uuid::v7(), 4);
        $this->wait = new InboxCardWait($watch, 'document-in-review', InboxCardWaitType::Document, 'marker.document_in_review', documentId: Uuid::v7(), versionNumber: 1);
        $watch->waits->add($this->wait);
    }

    public function test_the_title_of_a_deleted_document_wait_names_its_version(): void
    {
        self::assertSame('Deleted document version 1', $this->text->title([$this->wait], 'Ship it', 200));
    }

    public function test_the_body_of_a_deleted_document_wait_names_no_empty_title(): void
    {
        $body = $this->text->body([$this->wait]);

        self::assertSame('A deleted document, version 1, of card 4 waited for your review. Dismiss this item.', $body);
        self::assertStringNotContainsString('""', $body);
    }

    public function test_the_stored_values_fill_the_label_of_the_marker(): void
    {
        $wait = new InboxCardWait($this->wait->watch, 'waits-for-approval', InboxCardWaitType::PullRequest, 'marker.waits_for_approval', pullRequestId: Uuid::v7(), headSha: 'abc123', params: ['%number%' => 7]);

        self::assertSame('Pull request #7 waits for your approval. Review pull request #7.', $this->text->body([$wait]));
        self::assertSame('Review pull request #7', $this->text->title([$wait], 'Ship it', 200));
    }

    public function test_the_title_of_a_pull_request_wait_names_the_stored_title(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $pullRequest = new ForgePullRequest($this->wait->watch->project, 'github', 'acme/widgets', 812);
        $pullRequest->title = 'Fix the board filter';
        $em->persist($pullRequest);
        $em->flush();
        $wait = new InboxCardWait($this->wait->watch, 'waits-for-approval', InboxCardWaitType::PullRequest, 'marker.waits_for_approval', pullRequestId: $pullRequest->id, headSha: 'abc123', params: ['%number%' => 812]);

        self::assertSame('Review pull request #812: Fix the board filter', $this->text->title([$wait], 'Ship it', 200));
        self::assertSame('Pull request #812 waits for your approval. Review pull request #812.', $this->text->body([$wait]));
        self::assertSame('Review pull request #812: Fix th', $this->text->title([$wait], 'Ship it', 32));
    }

    public function test_the_title_of_a_document_wait_names_its_document_and_version(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $project = $this->wait->watch->project;
        $document = new Document($project->owner, $project, 'Tech design: Inbox asks');
        $document->addVersion('# Design', '<h1>Design</h1>');
        $em->persist($document);
        $em->flush();
        $wait = new InboxCardWait($this->wait->watch, 'document-in-review', InboxCardWaitType::Document, 'marker.document_in_review', documentId: $document->id, versionNumber: 2);

        self::assertSame('Review "Tech design: Inbox asks", version 2', $this->text->title([$wait], 'Ship it', 200));
    }

    public function test_a_wait_with_no_label_reads_the_fallback_of_its_type(): void
    {
        $wait = new InboxCardWait($this->wait->watch, 'waits-for-approval', InboxCardWaitType::PullRequest, '', pullRequestId: Uuid::v7(), headSha: 'abc123', params: ['%number%' => 7]);

        self::assertSame('A pull request of card 4 waits for your review. Review pull request #7.', $this->text->body([$wait]));
    }

    public function test_a_run_wait_with_a_headline_reads_the_variant_of_its_label(): void
    {
        $params = ['%worker%' => 'implement worker', '%Worker%' => 'Implement worker', '%headline%' => 'Needs the API key'];
        $wait = new InboxCardWait($this->wait->watch, 'run-needs-you', InboxCardWaitType::WorkerRun, 'marker.run_needs_you', runId: Uuid::v7(), params: $params);

        self::assertSame('The implement worker on card 4 needs you: Needs the API key. Open card 4.', $this->text->body([$wait]));
        self::assertSame('Implement worker needs you: Needs the API key', $this->text->title([$wait], 'Ship it', 200));
    }

    public function test_a_run_wait_without_a_headline_reads_the_label_itself(): void
    {
        $wait = new InboxCardWait($this->wait->watch, 'run-needs-you', InboxCardWaitType::WorkerRun, 'marker.run_needs_you', runId: Uuid::v7());

        self::assertSame('The last worker run needs you. Open card 4.', $this->text->body([$wait]));
    }

    public function test_the_values_of_a_run_wait_come_from_the_output_of_the_run(): void
    {
        self::assertSame(
            ['%worker%' => 'implement worker', '%Worker%' => 'Implement worker', '%headline%' => 'Needs the API key'],
            RunOutputText::params('implement', 'STAGE RESULT: blocked: Needs the API key. Second line'),
        );
        self::assertSame(['%worker%' => 'worker', '%Worker%' => 'Worker', '%headline%' => ''], RunOutputText::params(null, ''));
    }

    public function test_a_document_wait_beside_a_run_wait_makes_a_review_titled_with_the_document(): void
    {
        $run = $this->runWait();

        self::assertSame(InboxItemKind::Review, $this->text->kind([$run, $this->wait]));
        self::assertSame($this->wait, $this->text->reviewWait([$run, $this->wait]));
        self::assertSame('Deleted document version 1', $this->text->title([$run, $this->wait], 'Ship it', 200));
    }

    public function test_a_run_wait_alone_makes_a_waiting_item_titled_with_the_run(): void
    {
        $run = $this->runWait();

        self::assertSame(InboxItemKind::Wait, $this->text->kind([$run]));
        self::assertNull($this->text->reviewWait([$run]));
        self::assertSame('Implement worker needs you: Needs the API key', $this->text->title([$run], 'Ship it', 200));
    }

    public function test_a_pull_request_wait_makes_a_review(): void
    {
        $wait = new InboxCardWait($this->wait->watch, 'waits-for-approval', InboxCardWaitType::PullRequest, 'marker.waits_for_approval', pullRequestId: Uuid::v7(), headSha: 'abc123', params: ['%number%' => 7]);

        self::assertSame(InboxItemKind::Review, $this->text->kind([$wait]));
        self::assertSame('Review pull request #7', $this->text->title([$this->runWait(), $wait], 'Ship it', 200));
    }

    public function test_the_item_is_waiting_again_when_the_last_review_wait_ends_beside_another_wait(): void
    {
        $run = $this->runWait();
        $this->wait->endedAt = new \DateTimeImmutable();

        self::assertSame(InboxItemKind::Wait, $this->text->kind([$run, $this->wait]));
        self::assertSame('Implement worker needs you: Needs the API key', $this->text->title([$run, $this->wait], 'Ship it', 200));
    }

    public function test_an_empty_set_of_waits_makes_a_waiting_item(): void
    {
        self::assertSame(InboxItemKind::Wait, $this->text->kind([]));
    }

    public function test_a_pause_after_failed_work_names_its_reason_and_the_run_that_failed(): void
    {
        $em = $this->em();
        $card = $this->card($em, $this->wait->watch->project, 33);
        $em->flush();
        $pausedAt = new \DateTimeImmutable('2026-10-26 18:57:20');
        $this->workerRun($card->id, 'implement', WorkerRunState::Failed, new \DateTimeImmutable('2026-10-26 18:57:07'), 'the before command exited with code 141');
        $repair = $this->workerRun($card->id, 'repair', WorkerRunState::Failed, new \DateTimeImmutable('2026-10-26 18:57:17'), 'the before command exited with code 141');
        $this->workerRun($card->id, 'implement', WorkerRunState::Running, new \DateTimeImmutable('2026-10-26 19:04:39'));
        $this->workerRun($card->id, 'implement', WorkerRunState::Failed, new \DateTimeImmutable('2026-10-26 19:05:00'), 'a later failure');
        $this->workerRun($card->id, 'review', WorkerRunState::Failed, new \DateTimeImmutable('2026-10-26 18:57:19'), 'a failure of another rule', ruleId: 'review');
        $wait = $this->pauseWait($card, 'repair-failed', PauseKind::Retries, $pausedAt);

        self::assertSame('Paused: The repair worker could not fix the failure', $this->text->title([$wait], 'Ship it', 200));
        self::assertStringStartsWith(
            'Card 33 is paused. The repair worker could not fix the failure. The last repair worker run failed: the before command exited with code 141. Open the card and resume it. [Open the failed run](',
            $this->text->body([$wait]),
        );
        $line = $this->text->line($wait, false);
        self::assertNotNull($line->detail);
        self::assertStringContainsString('search='.$repair->id?->toRfc4122(), $line->detail);
        self::assertStringContainsString('Open the failed run', $line->detail);
    }

    public function test_a_pause_after_failed_work_keeps_its_reason_beside_an_open_run_wait(): void
    {
        $em = $this->em();
        $card = $this->card($em, $this->wait->watch->project, 37);
        $em->flush();
        $repair = $this->workerRun($card->id, 'repair', WorkerRunState::Failed, new \DateTimeImmutable('2026-10-26 18:57:17'), 'the before command exited with code 141');
        $pause = $this->pauseWait($card, 'repair-failed', PauseKind::Retries, new \DateTimeImmutable('2026-10-26 18:57:20'));
        $params = ['%worker%' => 'repair worker', '%Worker%' => 'Repair worker', '%headline%' => 'Cannot check out the branch'];
        $run = new InboxCardWait($pause->watch, 'run-needs-you', InboxCardWaitType::WorkerRun, 'marker.run_needs_you', runId: $repair->id, params: $params);
        $pause->watch->waits->add($run);

        self::assertSame('Paused: The repair worker could not fix the failure', $this->text->title([$run, $pause], 'Ship it', 200));
        $body = $this->text->body([$run, $pause]);
        self::assertStringContainsString('Card 37 is paused. The repair worker could not fix the failure. The last repair worker run failed: the before command exited with code 141.', $body);
        self::assertStringContainsString('search='.$repair->id?->toRfc4122(), $body);
        self::assertStringContainsString('needs you: Cannot check out the branch', $body);
    }

    public function test_the_stored_body_of_a_pause_links_the_failed_run_with_an_absolute_url(): void
    {
        $em = $this->em();
        $card = $this->card($em, $this->wait->watch->project, 38);
        $em->flush();
        $repair = $this->workerRun($card->id, 'repair', WorkerRunState::Failed, new \DateTimeImmutable('2026-10-26 18:57:17'), 'the before command exited with code 141');
        $wait = $this->pauseWait($card, 'repair-failed', PauseKind::Retries, new \DateTimeImmutable('2026-10-26 18:57:20'));

        self::assertMatchesRegularExpression(
            '~ Open the card and resume it\. \[Open the failed run\]\(https?://[^/)]+/[^)]*search='.preg_quote($repair->id?->toRfc4122() ?? '', '~').'\)$~',
            $this->text->body([$wait]),
        );
    }

    public function test_a_pause_after_a_run_that_stopped_for_a_person_names_no_failed_run(): void
    {
        $em = $this->em();
        $card = $this->card($em, $this->wait->watch->project, 34);
        $em->flush();
        $this->workerRun($card->id, 'fix', WorkerRunState::Blocked, new \DateTimeImmutable('2026-10-26 10:00:00'), null, "1. Read the review.\n3. The next agent review then reads that head.\n");
        $wait = $this->pauseWait($card, 'needs-person', PauseKind::WorkStopped, new \DateTimeImmutable('2026-10-26 10:01:00'));

        self::assertSame('Card 34 is paused. The worker stopped because it needs a person. Open the card and resume it.', $this->text->body([$wait]));
        self::assertNull($this->text->line($wait, false)->detail);
    }

    public function test_a_pause_after_a_blocked_run_names_no_earlier_failed_run(): void
    {
        $em = $this->em();
        $card = $this->card($em, $this->wait->watch->project, 40);
        $em->flush();
        $this->workerRun($card->id, 'fix', WorkerRunState::Failed, new \DateTimeImmutable('2026-10-26 09:00:00'), 'an earlier failure');
        $this->workerRun($card->id, 'fix', WorkerRunState::Blocked, new \DateTimeImmutable('2026-10-26 10:00:00'));
        $wait = $this->pauseWait($card, 'needs-person', PauseKind::WorkStopped, new \DateTimeImmutable('2026-10-26 10:01:00'));

        self::assertSame('Card 40 is paused. The worker stopped because it needs a person. Open the card and resume it.', $this->text->body([$wait]));
        self::assertNull($this->text->line($wait, false)->detail);
    }

    public function test_a_pause_after_an_unfinished_run_names_no_failed_run(): void
    {
        $em = $this->em();
        $card = $this->card($em, $this->wait->watch->project, 41);
        $em->flush();
        $this->workerRun($card->id, 'fix', WorkerRunState::Unfinished, new \DateTimeImmutable('2026-10-26 10:00:00'), 'work remains');
        $wait = $this->pauseWait($card, 'blocked', PauseKind::WorkStopped, new \DateTimeImmutable('2026-10-26 10:01:00'));

        self::assertStringNotContainsString('work remains', $this->text->body([$wait]));
        self::assertNull($this->text->line($wait, false)->detail);
    }

    public function test_a_pause_after_a_failed_run_with_no_reason_names_its_state_and_not_its_output(): void
    {
        $em = $this->em();
        $card = $this->card($em, $this->wait->watch->project, 39);
        $em->flush();
        $this->workerRun($card->id, 'review', WorkerRunState::TimedOut, new \DateTimeImmutable('2026-10-26 10:00:00'), null, "Working.\n2. Then push.\n");
        $wait = $this->pauseWait($card, 'blocked', PauseKind::WorkStopped, new \DateTimeImmutable('2026-10-26 10:01:00'));

        $body = $this->text->body([$wait]);
        self::assertStringStartsWith('Card 39 is paused. The worker stopped as blocked. The last review worker run ended as timed out. Open the card and resume it. [Open the failed run](', $body);
        self::assertStringNotContainsString('push', $body);
    }

    public function test_the_body_leads_with_the_wait_the_title_names(): void
    {
        $em = $this->em();
        $card = $this->card($em, $this->wait->watch->project, 40);
        $em->flush();
        $pause = $this->pauseWait($card, 'needs-person', PauseKind::WorkStopped, new \DateTimeImmutable('2026-10-26 10:01:00'));
        $pullRequest = new InboxCardWait($pause->watch, 'waits-for-approval', InboxCardWaitType::PullRequest, 'marker.waits_for_approval', pullRequestId: Uuid::v7(), headSha: 'abc123');
        $pause->watch->waits->add($pullRequest);

        $lines = explode("\n", $this->text->body([$pause, $pullRequest]));

        self::assertCount(2, $lines);
        self::assertStringContainsString('waits for your approval', $lines[0]);
        self::assertStringStartsWith('Card 40 is paused.', $lines[1]);
    }

    public function test_the_page_order_puts_the_main_wait_first_and_the_ended_waits_last(): void
    {
        $em = $this->em();
        $card = $this->card($em, $this->wait->watch->project, 41);
        $em->flush();
        $pause = $this->pauseWait($card, 'needs-person', PauseKind::WorkStopped, new \DateTimeImmutable('2026-10-26 10:01:00'));
        $ended = new InboxCardWait($pause->watch, 'waits-for-approval', InboxCardWaitType::PullRequest, 'marker.waits_for_approval', pullRequestId: Uuid::v7(), headSha: 'abc123');
        $ended->endedAt = new \DateTimeImmutable('2026-10-26 10:02:00');
        $open = new InboxCardWait($pause->watch, 'waits-for-approval', InboxCardWaitType::PullRequest, 'marker.waits_for_approval', pullRequestId: Uuid::v7(), headSha: 'abc123');

        self::assertSame([$open, $pause, $ended], $this->text->inOrder([$pause, $ended, $open]));
    }

    public function test_the_page_leaves_out_a_main_pause_that_only_repeats_the_title(): void
    {
        $em = $this->em();
        $card = $this->card($em, $this->wait->watch->project, 42);
        $em->flush();
        $this->workerRun($card->id, 'implement', WorkerRunState::Blocked, new \DateTimeImmutable('2026-10-29 12:36:30'));
        $pause = $this->pauseWait($card, 'blocked', PauseKind::WorkStopped, new \DateTimeImmutable('2026-10-29 12:36:34'));
        $run = $this->runWait();
        $run->endedAt = new \DateTimeImmutable('2026-10-29 12:37:16');

        self::assertSame([$run], $this->text->pageWaits([$pause, $run]));
        self::assertSame([], $this->text->pageWaits([$pause]));
    }

    public function test_the_page_keeps_a_main_pause_that_names_the_failed_run(): void
    {
        $em = $this->em();
        $card = $this->card($em, $this->wait->watch->project, 43);
        $em->flush();
        $this->workerRun($card->id, 'repair', WorkerRunState::Failed, new \DateTimeImmutable('2026-10-26 18:57:17'), 'the before command exited with code 141');
        $pause = $this->pauseWait($card, 'repair-failed', PauseKind::Retries, new \DateTimeImmutable('2026-10-26 18:57:20'));

        self::assertSame([$pause], $this->text->pageWaits([$pause]));
    }

    public function test_the_page_keeps_a_pause_when_another_wait_leads(): void
    {
        $em = $this->em();
        $card = $this->card($em, $this->wait->watch->project, 44);
        $em->flush();
        $pause = $this->pauseWait($card, 'needs-person', PauseKind::Rule, new \DateTimeImmutable('2026-10-26 10:01:00'));
        $pullRequest = new InboxCardWait($pause->watch, 'waits-for-approval', InboxCardWaitType::PullRequest, 'marker.waits_for_approval', pullRequestId: Uuid::v7(), headSha: 'abc123');

        self::assertSame([$pullRequest, $pause], $this->text->pageWaits([$pause, $pullRequest]));
    }

    public function test_a_rule_pause_names_no_run_and_words_an_unknown_code(): void
    {
        $em = $this->em();
        $card = $this->card($em, $this->wait->watch->project, 35);
        $em->flush();
        $this->workerRun($card->id, 'implement', WorkerRunState::Failed, new \DateTimeImmutable('2026-10-26 10:00:00'), 'boom');
        $wait = $this->pauseWait($card, 'owner-hold', PauseKind::Rule, new \DateTimeImmutable('2026-10-26 10:01:00'));

        self::assertSame('Paused: The workflow paused the card with the code owner-hold', $this->text->title([$wait], 'Ship it', 200));
        self::assertSame('Card 35 is paused. The workflow paused the card with the code owner-hold. Open the card and resume it.', $this->text->body([$wait]));
        self::assertNull($this->text->line($wait, false)->detail);
    }

    public function test_a_pause_from_a_refused_action_names_no_run(): void
    {
        $em = $this->em();
        $card = $this->card($em, $this->wait->watch->project, 36);
        $em->flush();
        $this->workerRun($card->id, 'implement', WorkerRunState::Failed, new \DateTimeImmutable('2026-10-26 10:00:00'), 'boom');
        $wait = $this->pauseWait($card, 'move-refused', PauseKind::Retries, new \DateTimeImmutable('2026-10-26 10:01:00'));

        self::assertSame('Card 36 is paused. The board refused the move. Open the card and resume it.', $this->text->body([$wait]));
        self::assertNull($this->text->line($wait, false)->detail);
    }

    public function test_a_pause_that_is_gone_still_words_its_stored_code(): void
    {
        $wait = new InboxCardWait($this->wait->watch, 'paused', InboxCardWaitType::CardPause, 'marker.paused', pauseId: Uuid::v7(), params: ['%reason%' => 'repair-failed']);

        self::assertSame('Card 4 is paused. The repair worker could not fix the failure. Open the card and resume it.', $this->text->body([$wait]));
    }

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }

    private function pauseWait(Card $card, string $reason, PauseKind $kind, \DateTimeImmutable $at): InboxCardWait
    {
        $em = $this->em();
        $pause = new CardPause($card, $card->project, $reason, 'implement', $kind, $at);
        $em->persist($pause);
        $em->flush();
        $watch = new InboxCardWatch($this->wait->watch->item, $card->id ?? throw new \LogicException('The card is stored.'), $card->number);
        $wait = new InboxCardWait($watch, 'paused', InboxCardWaitType::CardPause, 'marker.paused', pauseId: $pause->id, params: ['%reason%' => $reason]);
        $watch->waits->add($wait);

        return $wait;
    }

    private function workerRun(?Uuid $cardId, string $workKind, WorkerRunState $state, \DateTimeImmutable $at, ?string $failureReason = null, string $output = '', string $ruleId = 'implement'): WorkerRun
    {
        $em = $this->em();
        $run = new WorkerRun($this->wait->watch->project, Uuid::v7(), WorkSubject::CARD, $cardId ?? throw new \LogicException('The card is stored.'), 33, $workKind, $state, failureReason: $failureReason, output: $output, receivedAt: $at, ruleId: $ruleId);
        $em->persist($run);
        $em->flush();

        return $run;
    }

    private function runWait(): InboxCardWait
    {
        $params = ['%worker%' => 'implement worker', '%Worker%' => 'Implement worker', '%headline%' => 'Needs the API key'];

        return new InboxCardWait($this->wait->watch, 'run-needs-you', InboxCardWaitType::WorkerRun, 'marker.run_needs_you', runId: Uuid::v7(), params: $params);
    }
}
