<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Mcp;

use Mcp\Capability\Registry;
use Mcp\Server;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DecisionToolRegistrationTest extends KernelTestCase
{
    private Registry $registry;

    protected function setUp(): void
    {
        self::bootKernel();
        self::assertInstanceOf(Server::class, self::getContainer()->get('mcp.server'));
        $registry = self::getContainer()->get('mcp.registry');
        self::assertInstanceOf(Registry::class, $registry);
        $this->registry = $registry;
    }

    public function test_the_answer_tool_asks_for_at_least_one_option_and_caps_the_note(): void
    {
        $schema = $this->registry->getTool('document_decision_answer')->tool->inputSchema;

        self::assertSame(['documentId', 'versionNumber', 'decisionId', 'optionIndexes'], $schema['required']);
        self::assertSame(1, $schema['properties']['optionIndexes']['minItems']);
        self::assertSame(0, $schema['properties']['optionIndexes']['items']['minimum']);
        self::assertSame(2000, $schema['properties']['note']['maxLength']);
    }

    public function test_the_clear_tool_takes_a_version_and_no_options(): void
    {
        $schema = $this->registry->getTool('document_decision_clear')->tool->inputSchema;

        self::assertSame(['documentId', 'versionNumber', 'decisionId'], array_keys($schema['properties']));
        self::assertSame(['documentId', 'versionNumber', 'decisionId'], $schema['required']);
    }

    public function test_the_submit_tool_requires_a_nullable_expected_verdict_id(): void
    {
        $schema = $this->registry->getTool('document_verdict_submit')->tool->inputSchema;

        self::assertSame(['documentId', 'versionNumber', 'verdict', 'expectedVerdictId'], $schema['required']);
        self::assertContains('null', (array) $schema['properties']['expectedVerdictId']['type']);
        self::assertSame(['approved', 'changes-requested'], $schema['properties']['verdict']['enum']);
    }

    public function test_the_withdraw_tool_requires_the_verdict_id(): void
    {
        $schema = $this->registry->getTool('document_verdict_withdraw')->tool->inputSchema;

        self::assertSame(['documentId', 'versionNumber', 'verdictId'], $schema['required']);
    }
}
