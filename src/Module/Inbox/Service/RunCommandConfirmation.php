<?php

declare(strict_types=1);

namespace App\Module\Inbox\Service;

use App\Exception\DomainErrors;
use App\Module\Inbox\Command\RunInboxActionHandler;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\View\RunCommandView;

/**
 * Previews a run-command action and checks the digest of that preview.
 *
 * The digest binds the item, the entry, the run list, the branch and the param values, because the digest
 * of the view names only the bridge and the run list. Every surface that runs a command calls this class.
 */
final readonly class RunCommandConfirmation
{
    public function __construct(
        private ItemActions $itemActions,
    ) {
    }

    /**
     * @param array<string, string>|null $params
     *
     * @return array{RunCommandView, string} the view, and the digest the caller must send back to run it
     *
     * @throws DomainErrors `RunInboxActionHandler::NOT_AVAILABLE` when the item is closed or has no such command
     */
    public function preview(InboxItem $item, ?string $branch, ?array $params): array
    {
        $view = $this->viewOf($item, $branch, $params);

        return [$view, self::confirmation($item, $view)];
    }

    /**
     * Builds the view again and compares the digest the caller read with the digest of now.
     *
     * @param array<string, string>|null $params
     *
     * @return string the digest `RunInboxActionCommand` takes
     *
     * @throws DomainErrors `NOT_AVAILABLE` when there is no view, `COMMAND_CHANGED` when the digests differ
     */
    public function confirm(InboxItem $item, ?string $branch, ?array $params, string $given): string
    {
        $view = $this->viewOf($item, $branch, $params);
        if (!hash_equals(self::confirmation($item, $view), $given)) {
            throw new DomainErrors(['action' => RunInboxActionHandler::COMMAND_CHANGED]);
        }

        return $view->runDigest();
    }

    /** @param array<string, string>|null $params */
    private function viewOf(InboxItem $item, ?string $branch, ?array $params): RunCommandView
    {
        $view = InboxItemState::Open === $item->state ? $this->itemActions->runCommandFor($item, $branch, $params) : null;

        return $view ?? throw new DomainErrors(['action' => RunInboxActionHandler::NOT_AVAILABLE]);
    }

    private static function confirmation(InboxItem $item, RunCommandView $view): string
    {
        return hash('sha256', json_encode([(string) $item->id, $view->entry, $view->runDigest(), $view->branch, $view->values()], \JSON_THROW_ON_ERROR));
    }
}
