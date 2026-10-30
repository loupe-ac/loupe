<?php

declare(strict_types=1);

namespace App\Module\Inbox\Mcp;

use Mcp\Schema\Content\TextContent;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use Mcp\Server\Session\SessionInterface;

/**
 * Answers a call to a removed inbox tool with an error that names the new way.
 *
 * The SDK asks custom request handlers first, so the three names stay out of tools/list
 * and a client that still calls one reads what to call instead.
 *
 * @implements RequestHandlerInterface<CallToolResult>
 */
final readonly class RemovedInboxToolsCallHandler implements RequestHandlerInterface
{
    /** @var list<string> */
    public const array REMOVED = ['inbox_answer', 'inbox_done', 'inbox_decline'];

    #[\Override]
    public function supports(Request $request): bool
    {
        return $request instanceof CallToolRequest && \in_array($request->name, self::REMOVED, true);
    }

    #[\Override]
    public function handle(Request $request, SessionInterface $session): Response
    {
        \assert($request instanceof CallToolRequest);

        $action = match ($request->name) {
            'inbox_answer' => 'answer',
            'inbox_done' => 'complete',
            default => 'dismiss',
        };

        return new Response($request->getId(), CallToolResult::error([new TextContent(\sprintf(
            '%s was removed. Call %s with action "%s" and the same itemId. Pass the words of the owner as note%s.',
            $request->name,
            InboxActionRunTool::NAME,
            $action,
            'answer' === $action ? ', and the chosen options as selectedOptions' : '',
        ))]));
    }
}
