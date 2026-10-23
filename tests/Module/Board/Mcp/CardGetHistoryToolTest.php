<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Mcp;

use App\Module\Account\Entity\User;
use App\Module\Board\Command\ListCardsHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardEventKind;
use App\Module\Board\Mcp\CardCreateTool;
use App\Module\Board\Mcp\CardGetHistoryTool;
use App\Module\Board\Mcp\CardUpdateTool;
use App\Module\Board\Repository\CardEventRepository;
use App\Module\Workflow\Contract\Actor;
use App\Tests\Support\McpTokenScenario;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Exception\ToolCallException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CardGetHistoryToolTest extends KernelTestCase
{
    use BoardToolScenario;
    use McpTokenScenario;

    private EntityManagerInterface $em;
    private CardGetHistoryTool $tool;
    private CardCreateTool $createTool;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $tool = self::getContainer()->get(CardGetHistoryTool::class);
        self::assertInstanceOf(CardGetHistoryTool::class, $tool);
        $this->tool = $tool;

        $createTool = self::getContainer()->get(CardCreateTool::class);
        self::assertInstanceOf(CardCreateTool::class, $createTool);
        $this->createTool = $createTool;
    }

    public function test_a_creation_and_a_move_read_newest_first_with_translated_columns(): void
    {
        $project = $this->makeProject('card-history');
        $this->actAsMcpTokenBoundTo($project);
        $created = ($this->createTool)('Ship it', 'Body', 'feature');
        $update = self::getContainer()->get(CardUpdateTool::class);
        self::assertInstanceOf(CardUpdateTool::class, $update);
        $update($created['cardId'], status: 'next');
        $backlog = $this->column($project, 'backlog');
        $next = $this->column($project, 'next');

        $history = ($this->tool)($created['cardId']);

        self::assertSame(['page' => 1, 'perPage' => ListCardsHandler::DEFAULT_PER_PAGE, 'total' => 2, 'hasMore' => false], array_diff_key($history, ['events' => true]));
        [$moved, $creation] = $history['events'];
        self::assertSame('moved', $moved['kind']);
        self::assertSame(['kind' => 'agent', 'name' => 'Riley'], $moved['actor']);
        self::assertSame(['id' => (string) $backlog->id, 'slug' => 'backlog', 'label' => 'Backlog'], $moved['from']);
        self::assertSame(['id' => (string) $next->id, 'slug' => 'next', 'label' => 'Next'], $moved['to']);
        self::assertNull($moved['cause']);
        self::assertSame('created', $creation['kind']);
        self::assertNull($creation['from']);
        self::assertSame(['id' => (string) $backlog->id, 'slug' => 'backlog', 'label' => 'Backlog'], $creation['to']);
        self::assertSame([null, null, null, null], [$creation['cause'], $creation['run'], $creation['reason'], $creation['pullRequest']]);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $creation['occurredAt']);
    }

    public function test_an_automation_row_reads_its_reason_and_pull_request_and_names_no_one(): void
    {
        $this->actAsMcpTokenBoundTo($this->makeProject('card-history-automation'));
        $created = ($this->createTool)('Ship it', 'Body', 'feature');
        $this->events()->record($this->card($created['cardId']), CardEventKind::FixRequested, Actor::System, null, ['reason' => 'checks failed', 'pullRequest' => 42], new \DateTimeImmutable('+1 minute'));
        $this->em->flush();

        $event = ($this->tool)($created['cardId'])['events'][0];

        self::assertSame('fix-requested', $event['kind']);
        self::assertSame(['kind' => 'system', 'name' => null], $event['actor']);
        self::assertSame('checks failed', $event['reason']);
        self::assertSame(42, $event['pullRequest']);
        self::assertSame([null, null, null, null], [$event['from'], $event['to'], $event['cause'], $event['run']]);
    }

    public function test_a_pause_row_reads_its_kind_rule_and_reason(): void
    {
        $this->actAsMcpTokenBoundTo($this->makeProject('card-history-pause'));
        $created = ($this->createTool)('Ship it', 'Body', 'feature');
        $this->events()->record($this->card($created['cardId']), CardEventKind::Paused, Actor::System, null, ['kind' => 'retries', 'reason' => 'review-failed', 'ruleId' => 'fix-on-review'], new \DateTimeImmutable('+1 minute'));
        $this->em->flush();

        [$paused, $creation] = ($this->tool)($created['cardId'])['events'];

        self::assertSame('paused', $paused['kind']);
        self::assertSame('review-failed', $paused['reason']);
        self::assertSame(['kind' => 'retries', 'ruleId' => 'fix-on-review', 'releaseReason' => null], $paused['pause']);
        self::assertNull($creation['pause']);
    }

    public function test_a_release_row_reads_why_the_pause_ended(): void
    {
        $this->actAsMcpTokenBoundTo($this->makeProject('card-history-release'));
        $created = ($this->createTool)('Ship it', 'Body', 'feature');
        $card = $this->card($created['cardId']);
        $this->events()->record($card, CardEventKind::PauseReleased, Actor::System, null, ['kind' => 'retries', 'reason' => 'review-failed', 'ruleId' => 'fix-on-review', 'releaseReason' => 'facts-changed'], new \DateTimeImmutable('+1 minute'));
        $this->events()->record($card, CardEventKind::PauseReleased, Actor::Human, null, ['kind' => 'retries', 'reason' => 'review-failed', 'ruleId' => 'fix-on-review'], new \DateTimeImmutable('+2 minutes'));
        $this->em->flush();

        [$legacy, $released] = ($this->tool)($created['cardId'])['events'];

        self::assertSame('pause-released', $released['kind']);
        self::assertSame(['kind' => 'retries', 'ruleId' => 'fix-on-review', 'releaseReason' => 'facts-changed'], $released['pause']);
        self::assertSame(['kind' => 'retries', 'ruleId' => 'fix-on-review', 'releaseReason' => null], $legacy['pause'], 'An event written before the release reason was recorded reads null.');
    }

    public function test_an_override_row_reads_its_approver_and_its_head(): void
    {
        $this->actAsMcpTokenBoundTo($this->makeProject('card-history-override'));
        $created = ($this->createTool)('Ship it', 'Body', 'feature');
        $card = $this->card($created['cardId']);
        $this->events()->record($card, CardEventKind::ReviewOverridden, Actor::System, null, ['pullRequest' => 7, 'approver' => 'ubermuda', 'headSha' => str_repeat('a', 40)], new \DateTimeImmutable('+1 minute'));
        $this->events()->record($card, CardEventKind::ReviewOverrideEnded, Actor::System, null, ['pullRequest' => 7, 'headSha' => str_repeat('a', 40)], new \DateTimeImmutable('+2 minutes'));
        $this->em->flush();

        [$ended, $overridden, $creation] = ($this->tool)($created['cardId'])['events'];

        self::assertSame(['review-overridden', 7, ['approver' => 'ubermuda', 'headSha' => str_repeat('a', 40)]], [$overridden['kind'], $overridden['pullRequest'], $overridden['override']]);
        self::assertSame(['override-ended', 7, ['approver' => null, 'headSha' => str_repeat('a', 40)]], [$ended['kind'], $ended['pullRequest'], $ended['override']]);
        self::assertNull($creation['override']);
    }

    public function test_a_document_verdict_row_and_a_failed_verdict_row_read_their_rule_document_and_refusal(): void
    {
        $this->actAsMcpTokenBoundTo($this->makeProject('card-history-document'));
        $created = ($this->createTool)('Ship it', 'Body', 'feature');
        $card = $this->card($created['cardId']);
        $this->events()->record($card, CardEventKind::DocumentVerdict, Actor::System, null, ['rule' => 'tech-design-auto-approve', 'document' => 'Design', 'documentId' => 'doc-1', 'verdict' => 'approved'], new \DateTimeImmutable('+1 minute'));
        $this->events()->record($card, CardEventKind::DocumentVerdict, Actor::Human, null, ['rule' => null, 'document' => 'Design', 'documentId' => 'doc-1', 'verdict' => 'withdrawn', 'withdrawn' => 'approved'], new \DateTimeImmutable('+2 minutes'));
        $this->events()->record($card, CardEventKind::VerdictFailed, Actor::System, null, ['rule' => 'tech-design-auto-approve', 'document' => 'Design', 'documentId' => 'doc-1', 'refusal' => 'verdict_stale'], new \DateTimeImmutable('+3 minutes'));
        $this->em->flush();

        [$failed, $withdrawn, $approved, $creation] = ($this->tool)($created['cardId'])['events'];

        self::assertSame(['documentId' => 'doc-1', 'title' => 'Design', 'rule' => 'tech-design-auto-approve', 'verdict' => 'approved', 'withdrawn' => null, 'refusal' => null], $approved['document']);
        self::assertSame(['documentId' => 'doc-1', 'title' => 'Design', 'rule' => null, 'verdict' => 'withdrawn', 'withdrawn' => 'approved', 'refusal' => null], $withdrawn['document']);
        self::assertSame(['documentId' => 'doc-1', 'title' => 'Design', 'rule' => 'tech-design-auto-approve', 'verdict' => null, 'withdrawn' => null, 'refusal' => 'verdict_stale'], $failed['document']);
        self::assertNull($creation['document']);
    }

    public function test_a_finished_run_passes_its_whole_detail_through(): void
    {
        $this->actAsMcpTokenBoundTo($this->makeProject('card-history-run'));
        $created = ($this->createTool)('Ship it', 'Body', 'feature');
        $detail = ['outcome' => 'succeeded', 'stage' => 'implementation'];
        $this->events()->record($this->card($created['cardId']), CardEventKind::RunFinished, Actor::System, null, $detail, new \DateTimeImmutable('+1 minute'));
        $this->em->flush();

        self::assertSame($detail, ($this->tool)($created['cardId'])['events'][0]['run']);
    }

    public function test_a_malformed_detail_reads_null_rather_than_fail(): void
    {
        $this->actAsMcpTokenBoundTo($this->makeProject('card-history-malformed'));
        $created = ($this->createTool)('Ship it', 'Body', 'feature');
        $this->events()->record($this->card($created['cardId']), CardEventKind::Moved, Actor::System, null, ['from' => 'backlog', 'to' => ['slug' => 'next'], 'cause' => 'merged', 'reason' => 7, 'pullRequest' => '42'], new \DateTimeImmutable('+1 minute'));
        $this->em->flush();

        $event = ($this->tool)($created['cardId'])['events'][0];

        self::assertSame([null, null, null, null, null], [$event['from'], $event['to'], $event['cause'], $event['reason'], $event['pullRequest']]);
    }

    public function test_the_actor_name_is_read_when_the_history_is(): void
    {
        $project = $this->makeProject('card-history-rename');
        $this->actAsMcpTokenBoundTo($project);
        $created = ($this->createTool)('Ship it', 'Body', 'feature');
        $owner = $this->em->find(User::class, $project->owner->id) ?? throw new \LogicException('The owner exists.');
        $owner->fullName = 'Riley Chen';
        $this->em->flush();
        $this->em->clear();

        self::assertSame('Riley Chen', ($this->tool)($created['cardId'])['events'][0]['actor']['name']);
    }

    public function test_the_page_size_is_clamped_and_a_page_past_the_end_reads_empty(): void
    {
        $this->actAsMcpTokenBoundTo($this->makeProject('card-history-paging'));
        $created = ($this->createTool)('Ship it', 'Body', 'feature');

        $clamped = ($this->tool)($created['cardId'], perPage: 1000);
        $past = ($this->tool)($created['cardId'], page: 2);

        self::assertSame(ListCardsHandler::MAX_PER_PAGE, $clamped['perPage']);
        self::assertCount(1, $clamped['events']);
        self::assertSame(['events' => [], 'page' => 2, 'perPage' => ListCardsHandler::DEFAULT_PER_PAGE, 'total' => 1, 'hasMore' => false], $past);
    }

    public function test_a_card_reads_back_by_its_number(): void
    {
        $this->actAsMcpTokenBoundTo($this->makeProject('card-history-number'));
        ($this->createTool)('Ship it', 'Body', 'feature');

        self::assertSame(1, ($this->tool)(number: 1)['total']);
    }

    public function test_a_card_in_another_project_is_not_reachable(): void
    {
        $this->actAsMcpTokenBoundTo($this->makeProject('card-history-theirs'));
        $theirs = ($this->createTool)('Not yours', 'Body', 'feature');

        $this->actAsMcpTokenBoundTo($this->makeProject('card-history-mine'));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('not found or not accessible');
        ($this->tool)($theirs['cardId']);
    }

    public function test_a_card_id_and_a_number_together_are_refused(): void
    {
        $this->actAsMcpTokenBoundTo($this->makeProject('card-history-both'));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Pass cardId or number, not both.');
        ($this->tool)('01920000-0000-7000-8000-000000000000', 1);
    }

    private function card(string $id): Card
    {
        return $this->em->find(Card::class, $id) ?? throw new \LogicException('The card exists.');
    }

    private function events(): CardEventRepository
    {
        $events = self::getContainer()->get(CardEventRepository::class);
        self::assertInstanceOf(CardEventRepository::class, $events);

        return $events;
    }
}
