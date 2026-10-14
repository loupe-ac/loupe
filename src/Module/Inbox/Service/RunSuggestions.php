<?php

declare(strict_types=1);

namespace App\Module\Inbox\Service;

use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\View\SuggestedAction;

/** Reads the title and the suggested actions that a worker puts in its structured result. */
final class RunSuggestions
{
    /** The built-in action keys. A worker sends a key as a plain string, so the app drops one it does not know. */
    public const array ACTIONS = ['resume-run', 'retry-card', 'review', 'open', 'send-agent', 'start-session', 'dismiss'];

    public const int MAX_SUGGESTIONS = 3;

    public const int MAX_REASON_LENGTH = 200;

    /** The title as one line of plain text, or null when the worker sent none. */
    public static function title(WorkerRun $run): ?string
    {
        $title = $run->resultFields['title'] ?? null;
        $line = \is_string($title) ? self::oneLine($title) : '';

        return '' === $line ? null : mb_substr($line, 0, InboxItem::MAX_TITLE_LENGTH);
    }

    /** @return list<SuggestedAction> */
    public static function actions(WorkerRun $run): array
    {
        $list = $run->resultFields['suggestedActions'] ?? null;
        if (!\is_array($list)) {
            return [];
        }

        $actions = [];
        foreach ($list as $entry) {
            if (!\is_array($entry)) {
                continue;
            }
            $key = $entry['action'] ?? null;
            $reason = $entry['reason'] ?? null;
            if (!\is_string($key) || !\in_array($key, self::ACTIONS, true) || isset($actions[$key]) || !\is_string($reason)) {
                continue;
            }
            $actions[$key] = new SuggestedAction($key, mb_substr(self::oneLine($reason), 0, self::MAX_REASON_LENGTH), true === ($entry['recommended'] ?? null));
            if (self::MAX_SUGGESTIONS === \count($actions)) {
                break;
            }
        }

        return array_values($actions);
    }

    private static function oneLine(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
