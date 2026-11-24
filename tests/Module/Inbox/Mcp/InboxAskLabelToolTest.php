<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Mcp;

use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Mcp\InboxAskTool;
use App\Module\Inbox\Repository\InboxItemRepository;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Workflow\WorkflowProjects;
use App\Tests\Support\McpTokenScenario;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class InboxAskLabelToolTest extends KernelTestCase
{
    use InboxToolScenario;
    use McpTokenScenario;
    use WorkflowProjects;

    private EntityManagerInterface $em;
    private InboxAskTool $tool;
    private Project $project;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->tool = $this->askTool();
        $this->enableInbox();
        $this->project = $this->workflowProject('inbox-ask-label');
        $this->bindLifecycle($this->project);
        $this->actAsMcpTokenBoundTo($this->project);
    }

    public function test_a_log_label_opens_no_ask_and_tells_the_agent_to_go_on(): void
    {
        $result = $this->ask('Which name?', 'naming');

        self::assertNull($result['askId']);
        self::assertTrue($result['closed']);
        self::assertSame([], $result['items']);
        self::assertSame([['label' => 'naming', 'title' => 'Which name?', 'option' => 'beta']], $result['logged']);
        self::assertSame('Logged "Which name?" as naming. Go on with "beta".', $result['message']);
        self::assertSame(0, $this->openItems());
    }

    public function test_a_default_label_asks_with_a_deadline_and_says_so(): void
    {
        $before = new \DateTimeImmutable();

        $result = $this->ask('Which order?', 'ordering');

        self::assertFalse($result['closed']);
        self::assertSame([], $result['logged']);
        self::assertStringStartsWith('Asked, stop your turn.', $result['message']);
        self::assertStringContainsString('deadline passes', $result['message']);
        $item = $this->stored($result['items'][0]['itemId']);
        self::assertSame('ordering', $item->label);
        self::assertNotNull($item->defaultsAt);
        self::assertEqualsWithDelta($before->getTimestamp() + 24 * 3600, $item->defaultsAt->getTimestamp(), 60);
    }

    public function test_an_ask_label_asks_with_no_deadline(): void
    {
        $result = $this->ask('Which store?', 'data');

        self::assertSame('Asked, stop your turn.', $result['message']);
        $item = $this->stored($result['items'][0]['itemId']);
        self::assertSame('data', $item->label);
        self::assertNull($item->defaultsAt);
    }

    public function test_an_unlisted_label_asks(): void
    {
        $result = $this->ask('Which thing?', 'something-new');

        self::assertSame('Asked, stop your turn.', $result['message']);
        self::assertNull($this->stored($result['items'][0]['itemId'])->defaultsAt);
    }

    public function test_one_call_can_log_one_item_and_ask_another(): void
    {
        $result = ($this->tool)(sessionId: (string) Uuid::v4(), items: [
            $this->item('Which name?', 'naming'),
            $this->item('Which store?', 'data'),
        ]);

        self::assertCount(1, $result['items']);
        self::assertSame('naming', $result['logged'][0]['label']);
        self::assertSame('Logged "Which name?" as naming. Go on with "beta". Asked, stop your turn.', $result['message']);
    }

    /** @param array<string, mixed> $change */
    #[DataProvider('refusedAsks')]
    public function test_a_labelled_ask_that_breaks_a_rule_is_refused(array $change, string $message): void
    {
        try {
            ($this->tool)(sessionId: (string) Uuid::v4(), items: [$this->item('Which name?', 'naming', $change)]);
            self::fail('The ask should be refused.');
        } catch (ToolCallException $e) {
            self::assertSame($message, $e->getMessage());
        }
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function refusedAsks(): iterable
    {
        yield 'no recommended option' => [['recommendedOption' => null, 'confidence' => null], 'items[0].recommendedOption: A labelled ask needs a recommendedOption with a confidence, because Loupe may answer it with that option.'];
        yield 'no undo field' => [['details' => ['Evidence' => 'None.']], 'items[0].details.undo: A labelled ask must carry this detail field. Write the facts behind your choice under it.'];
        yield 'no details' => [['details' => []], 'items[0].details.undo: A labelled ask must carry this detail field. Write the facts behind your choice under it.'];
        yield 'a to-do' => [['kind' => 'todo', 'options' => [], 'recommendedOption' => null, 'confidence' => null], 'items[0].label: A label belongs on a question with options. A todo, a review and a question with only freeText take none.'];
        yield 'not blocking' => [['blocking' => false], 'items[0].label: A labelled ask blocks. Leave out blocking, or pass true.'];
        yield 'several answers' => [['multiple' => true], 'items[0].multiple: A labelled ask takes one answer, so it accepts no multiple.'];
        yield 'a bad label' => [['label' => 'Not A Label'], 'items[0].label: A label must be lowercase words joined by hyphens, at most 40 characters, such as "naming".'];
    }

    private function openItems(): int
    {
        $items = self::getContainer()->get(InboxItemRepository::class);
        self::assertInstanceOf(InboxItemRepository::class, $items);

        return \count($items->findOldestOpen($this->project, 10));
    }

    private function stored(string $itemId): InboxItem
    {
        $this->em->clear();
        $item = $this->em->find(InboxItem::class, Uuid::fromString($itemId));
        self::assertInstanceOf(InboxItem::class, $item);

        return $item;
    }

    /**
     * @param array<string, mixed> $change
     *
     * @return array<string, mixed>
     */
    private function item(string $title, string $label, array $change = []): array
    {
        return array_filter([
            'kind' => 'question',
            'title' => $title,
            'label' => $label,
            'options' => ['alpha', 'beta'],
            'recommendedOption' => 1,
            'confidence' => 'high',
            'details' => ['undo' => 'Rename it again.'],
            ...$change,
        ], static fn (mixed $value): bool => null !== $value);
    }

    /** @return array<string, mixed> */
    private function ask(string $title, string $label): array
    {
        return ($this->tool)(sessionId: (string) Uuid::v4(), items: [$this->item($title, $label)]);
    }
}
