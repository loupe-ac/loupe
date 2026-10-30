<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Mcp;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardEventKind;
use App\Module\Board\Mcp\CardCreateTool;
use App\Module\Board\Mcp\CardEventPayload;
use App\Module\Board\Mcp\CardGetTool;
use App\Module\Board\Mcp\CardListTool;
use App\Module\Board\Mcp\CardUpdateTool;
use App\Module\Board\Repository\CardEventRepository;
use App\Tests\Module\Workflow\WorkflowProjects;
use App\Tests\Support\McpTokenScenario;
use Mcp\Exception\ToolCallException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CardValuesToolTest extends KernelTestCase
{
    use McpTokenScenario;
    use WorkflowProjects;

    private const string KEY = 'tech-design-interactive';

    public function test_card_update_writes_a_value_and_the_payload_reports_it(): void
    {
        $card = $this->card('values-tool-write');

        $payload = $this->update()($card['cardId'], values: [self::KEY => true]);

        self::assertCount(1, $payload['values']);
        $value = $payload['values'][0];
        self::assertSame(self::KEY, $value['key']);
        self::assertSame('bool', $value['type']);
        self::assertTrue($value['value']);
        self::assertSame(['kind' => 'agent', 'name' => 'Riley'], $value['writer']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', (string) $value['updatedAt']);
    }

    public function test_an_unset_key_reads_null_everywhere(): void
    {
        $card = $this->card('values-tool-unset');

        self::assertSame(
            [['key' => self::KEY, 'type' => 'bool', 'value' => null, 'writer' => null, 'updatedAt' => null]],
            $card['values'],
        );
        $get = self::getContainer()->get(CardGetTool::class);
        self::assertInstanceOf(CardGetTool::class, $get);
        self::assertSame($card['values'], ($get($card['cardId'])->structuredContent ?? self::fail('card_get returned no structured content.'))['values']);
    }

    public function test_a_card_of_a_template_with_no_keys_has_an_empty_list(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('values-tool-none');
        $this->actAsMcpTokenBoundTo($project);

        $card = $this->create()('A card', 'Body', 'feature');

        self::assertSame([], $card['values']);
    }

    public function test_card_list_full_carries_the_values_of_each_card(): void
    {
        $card = $this->card('values-tool-list');
        $this->update()($card['cardId'], values: [self::KEY => false]);
        $list = self::getContainer()->get(CardListTool::class);
        self::assertInstanceOf(CardListTool::class, $list);

        $rows = $list(full: true)['cards'];

        self::assertCount(1, $rows);
        self::assertFalse($rows[0]['values'][0]['value']);
    }

    public function test_a_wrong_type_is_refused_with_the_field_name(): void
    {
        $card = $this->card('values-tool-type');

        try {
            $this->update()($card['cardId'], values: [self::KEY => 'yes']);
            self::fail('The call was not refused.');
        } catch (ToolCallException $e) {
            self::assertSame('values.'.self::KEY.': This key takes true or false.', $e->getMessage());
        }
    }

    public function test_an_undeclared_key_is_refused_and_lists_the_declared_keys(): void
    {
        $card = $this->card('values-tool-undeclared');

        try {
            $this->update()($card['cardId'], values: ['size' => true]);
            self::fail('The call was not refused.');
        } catch (ToolCallException $e) {
            self::assertSame('values.size: The workflow template declares no key size. Declared keys: '.self::KEY.'.', $e->getMessage());
        }
    }

    public function test_a_template_with_no_keys_says_none(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('values-tool-nokeys');
        $this->actAsMcpTokenBoundTo($project);
        $card = $this->create()('A card', 'Body', 'feature');

        try {
            $this->update()($card['cardId'], values: ['size' => true]);
            self::fail('The call was not refused.');
        } catch (ToolCallException $e) {
            self::assertSame('values.size: The workflow template declares no key size. Declared keys: none.', $e->getMessage());
        }
    }

    public function test_the_history_renders_a_value_change(): void
    {
        $card = $this->card('values-tool-history');
        $this->update()($card['cardId'], values: [self::KEY => true]);
        $this->update()($card['cardId'], values: [self::KEY => false]);
        $events = self::getContainer()->get(CardEventRepository::class);
        self::assertInstanceOf(CardEventRepository::class, $events);
        $entity = $this->em()->find(Card::class, $card['cardId']);
        self::assertInstanceOf(Card::class, $entity);
        $changes = array_values(array_filter($events->findForCard($entity), static fn ($e): bool => CardEventKind::ValueChanged === $e->kind));
        // Guard: the assertions below pass on an empty list.
        self::assertCount(2, $changes);
        $payload = self::getContainer()->get(CardEventPayload::class);
        self::assertInstanceOf(CardEventPayload::class, $payload);

        $rows = $payload->forEvents($changes);

        self::assertSame('value-changed', $rows[0]['kind']);
        self::assertSame(['key' => self::KEY, 'value' => false, 'previous' => true], $rows[0]['valueChange']);
        self::assertSame(['key' => self::KEY, 'value' => true, 'previous' => null], $rows[1]['valueChange']);
    }

    public function test_the_descriptions_name_the_new_surface(): void
    {
        $update = new \ReflectionClass(CardUpdateTool::class)->getAttributes()[0]->getArguments()['description'];
        self::assertStringContainsString('values', $update);
        self::assertStringContainsString('card_get', $update);
        $history = new \ReflectionClass(\App\Module\Board\Mcp\CardGetHistoryTool::class)->getAttributes()[0]->getArguments()['description'];
        self::assertStringContainsString('value-changed', $history);
        self::assertStringContainsString('valueChange', $history);
    }

    /** @return array<string, mixed> a card of a project bound to the lifecycle template, which declares the key */
    private function card(string $name): array
    {
        self::bootKernel();
        $project = $this->workflowProject($name);
        $this->bindLifecycle($project);
        $this->actAsMcpTokenBoundTo($project);

        return $this->create()('A card', 'Body', 'feature');
    }

    private function create(): CardCreateTool
    {
        $tool = self::getContainer()->get(CardCreateTool::class);
        self::assertInstanceOf(CardCreateTool::class, $tool);

        return $tool;
    }

    private function update(): CardUpdateTool
    {
        $tool = self::getContainer()->get(CardUpdateTool::class);
        self::assertInstanceOf(CardUpdateTool::class, $tool);

        return $tool;
    }
}
