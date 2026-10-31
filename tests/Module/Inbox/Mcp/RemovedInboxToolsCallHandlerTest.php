<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Mcp;

use App\Module\Inbox\Mcp\RemovedInboxToolsCallHandler;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Request\ListToolsRequest;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Server\Session\SessionInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RemovedInboxToolsCallHandlerTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function removed(): iterable
    {
        yield 'answer' => ['inbox_answer', 'inbox_answer was removed. Call inbox_action_run with action "answer" and the same itemId. Pass the words of the owner as note, and the chosen options as selectedOptions.'];
        yield 'done' => ['inbox_done', 'inbox_done was removed. Call inbox_action_run with action "complete" and the same itemId. Pass the words of the owner as note.'];
        yield 'decline' => ['inbox_decline', 'inbox_decline was removed. Call inbox_action_run with action "dismiss" and the same itemId. Pass the words of the owner as note.'];
    }

    #[DataProvider('removed')]
    public function test_a_call_to_a_removed_tool_gets_an_error_that_names_the_new_way(string $name, string $message): void
    {
        $handler = new RemovedInboxToolsCallHandler();
        $request = new CallToolRequest($name, ['itemId' => 'x'])->withId(1);

        self::assertTrue($handler->supports($request));
        $response = $handler->handle($request, $this->createStub(SessionInterface::class));

        self::assertInstanceOf(Response::class, $response);
        self::assertInstanceOf(CallToolResult::class, $response->result);
        self::assertTrue($response->result->isError);
        $content = $response->result->content[0];
        self::assertInstanceOf(TextContent::class, $content);
        self::assertSame($message, $content->text);
    }

    public function test_other_requests_pass_to_the_default_handlers(): void
    {
        $handler = new RemovedInboxToolsCallHandler();

        self::assertFalse($handler->supports(new CallToolRequest('inbox_action_run', [])));
        self::assertFalse($handler->supports(new ListToolsRequest()));
    }
}
