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
    public const array ACTIONS = ['resume-run', 'retry-card', 'review', 'open', 'send-agent', 'start-session', 'run-command', 'dismiss'];

    public const int MAX_SUGGESTIONS = 3;

    public const int MAX_REASON_LENGTH = 200;

    /** The longest YAML text of a proposed command entry. */
    public const int MAX_PROPOSED_ENTRY_LENGTH = 4000;

    public const string DEFAULT_BRANCH = 'main';

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
            $command = null;
            $proposedEntry = null;
            if ('run-command' === $key) {
                $command = self::command($entry['command'] ?? null);
                $proposedEntry = $entry['proposedEntry'] ?? null;
                if (null === $command || (null !== $proposedEntry && (!\is_string($proposedEntry) || mb_strlen($proposedEntry) > self::MAX_PROPOSED_ENTRY_LENGTH))) {
                    continue;
                }
            }
            $recommended = true === ($entry['recommended'] ?? null) && !array_any($actions, static fn (SuggestedAction $earlier): bool => $earlier->recommended);
            $actions[$key] = new SuggestedAction($key, mb_substr(self::oneLine($reason), 0, self::MAX_REASON_LENGTH), $recommended, $command, $proposedEntry);
            if (self::MAX_SUGGESTIONS === \count($actions)) {
                break;
            }
        }

        return array_values($actions);
    }

    /**
     * The command of a run-command suggestion, or null when its shape is wrong. The params come as a map
     * of name to value, or as a list of name and value objects, which is the form the result schema takes.
     *
     * @return array{entry: string, branch: string, params: array<string, string>}|null
     */
    private static function command(mixed $command): ?array
    {
        if (!\is_array($command)) {
            return null;
        }
        $entry = $command['entry'] ?? null;
        $branch = $command['branch'] ?? self::DEFAULT_BRANCH;
        $raw = $command['params'] ?? [];
        if (!\is_string($entry) || '' === trim($entry) || !\is_string($branch) || !\is_array($raw)) {
            return null;
        }

        $params = [];
        foreach ($raw as $name => $value) {
            if (\is_array($value)) {
                [$name, $value] = [$value['name'] ?? null, $value['value'] ?? null];
            }
            if (!\is_string($name) || '' === $name || !\is_string($value) || isset($params[$name])) {
                return null;
            }
            $params[$name] = $value;
        }

        return ['entry' => trim($entry), 'branch' => '' === $branch ? self::DEFAULT_BRANCH : $branch, 'params' => $params];
    }

    private static function oneLine(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
