<?php

declare(strict_types=1);

namespace App\Module\Inbox\View;

use App\Module\Inbox\Entity\InboxAsk;

/**
 * Where a response form sends the owner back to: the route of the redirect
 * after a saved response, and the controller of the forward that re-renders
 * the page after a refused one.
 */
final readonly class InboxReturnTarget
{
    public const string PAGE_QUERY = 'returnTo';
    public const string ID_QUERY = 'returnId';
    public const string VERSION_QUERY = 'returnVersion';
    public const string INBOX_ROUTE = 'app_project_inbox';

    public function isInbox(): bool
    {
        return self::INBOX_ROUTE === $this->route;
    }

    /**
     * @param array<string, int|string> $routeParameters
     * @param array<string, mixed>      $attributes      what the forward hands the controller
     * @param array<string, int|string> $query           what the forward keeps of the query string
     * @param list<string>              $rowKeys         the rows of the open list the owner acted on, in order, empty when the target is not that list
     * @param ?InboxAsk                 $acted           the ask the response belongs to, null for a loose item
     */
    public function __construct(
        public string $route,
        public array $routeParameters,
        public string $controller,
        public array $attributes,
        public array $query,
        public array $rowKeys = [],
        public ?InboxAsk $acted = null,
    ) {
    }
}
