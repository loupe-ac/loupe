<?php

declare(strict_types=1);

namespace App\Tests\Module\AgentReview\Mcp;

use App\Mcp\FlagGatedToolInterface;
use App\Module\AgentReview\Mcp\AgentReviewSubmitTool;
use App\Module\AgentReview\Mcp\DocumentReviewAnswerTool;
use App\Module\AgentReview\Mcp\DocumentReviewSubmitTool;
use App\Module\Board\Mcp\CardCreateTool;
use App\Module\Board\Mcp\FeedbackMarkAddressedTool;
use App\Module\Project\Mcp\AdvertisedTools;
use Mcp\Capability\Registry;
use Mcp\Server;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class AgentReviewToolRegistrationTest extends KernelTestCase
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

    public function test_the_tool_is_published_with_no_flag_and_a_connect_page_description(): void
    {
        self::assertTrue($this->registry->hasTool(AgentReviewSubmitTool::NAME));
        self::assertNotInstanceOf(FlagGatedToolInterface::class, self::getContainer()->get(AgentReviewSubmitTool::class));

        $translator = self::getContainer()->get(TranslatorInterface::class);
        self::assertInstanceOf(TranslatorInterface::class, $translator);
        $key = 'project.connect.tool.'.AgentReviewSubmitTool::NAME;
        self::assertNotSame($key, $translator->trans($key));
    }

    public function test_the_schema_bounds_the_findings_and_names_every_field(): void
    {
        $schema = $this->registry->getTool(AgentReviewSubmitTool::NAME)->tool->inputSchema;

        self::assertSame(['cardId', 'pullRequestUrl', 'headSha', 'summary', 'findings'], $schema['required']);
        self::assertSame(AgentReviewSubmitTool::MAX_FINDINGS, $schema['properties']['findings']['maxItems']);
        self::assertSame(AgentReviewSubmitTool::FINDING_ITEM, $schema['properties']['findings']['items']);
        self::assertSame('^[0-9a-f]{40}$', $schema['properties']['headSha']['pattern']);
    }

    public function test_the_tool_is_advertised_right_after_feedback_mark_addressed(): void
    {
        $advertised = self::getContainer()->get(AdvertisedTools::class);
        self::assertInstanceOf(AdvertisedTools::class, $advertised);
        $names = array_column($advertised->enabled(), 'name');
        $start = array_search(FeedbackMarkAddressedTool::NAME, $names, true);
        self::assertIsInt($start);

        self::assertSame([FeedbackMarkAddressedTool::NAME, AgentReviewSubmitTool::NAME], \array_slice($names, $start, 2));
    }

    public function test_the_document_review_tools_are_published_after_the_pull_request_tool(): void
    {
        foreach ([DocumentReviewSubmitTool::NAME, DocumentReviewAnswerTool::NAME] as $name) {
            self::assertTrue($this->registry->hasTool($name));
            $translator = self::getContainer()->get(TranslatorInterface::class);
            self::assertInstanceOf(TranslatorInterface::class, $translator);
            self::assertNotSame('project.connect.tool.'.$name, $translator->trans('project.connect.tool.'.$name));
        }
        self::assertSame(['cardId', 'documentId', 'versionNumber', 'summary', 'findings'], $this->registry->getTool(DocumentReviewSubmitTool::NAME)->tool->inputSchema['required']);
        self::assertSame(['cardId', 'documentId', 'answers'], $this->registry->getTool(DocumentReviewAnswerTool::NAME)->tool->inputSchema['required']);

        $advertised = self::getContainer()->get(AdvertisedTools::class);
        self::assertInstanceOf(AdvertisedTools::class, $advertised);
        $names = array_column($advertised->enabled(), 'name');
        $start = array_search(AgentReviewSubmitTool::NAME, $names, true);
        self::assertIsInt($start);
        self::assertSame([AgentReviewSubmitTool::NAME, DocumentReviewSubmitTool::NAME, DocumentReviewAnswerTool::NAME, CardCreateTool::NAME], \array_slice($names, $start, 4));
    }
}
