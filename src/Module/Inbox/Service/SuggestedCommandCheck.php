<?php

declare(strict_types=1);

namespace App\Module\Inbox\Service;

use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Inbox\Command\AskInboxHandler;
use App\Module\Inbox\Command\AskInboxRunCommand;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Checks the command of a run-command suggestion. The patterns come from the entry of the asking bridge,
 * else from the proposed entry. With neither, the values stay unchecked and the bridge decides.
 */
final class SuggestedCommandCheck
{
    public const int MAX_BRANCH_LENGTH = WorkRequest::MAX_BRANCH_LENGTH;

    /** A delimiter that no pattern is likely to hold, because PCRE has no escape for it. */
    private const string DELIMITER = "\x01";

    /** @return ?string the error key of the first refusal, or null when the command passes */
    public static function error(AskInboxRunCommand $command, ?string $proposedEntry, ?Bridge $bridge): ?string
    {
        if (!self::isBranchName($command->branch)) {
            return AskInboxHandler::SUGGESTION_BRANCH_INVALID;
        }

        $proposed = null;
        if (null !== $proposedEntry) {
            $proposed = self::proposedPatterns($proposedEntry);
            if (!\is_array($proposed)) {
                return AskInboxHandler::SUGGESTION_ENTRY_INVALID;
            }
        }

        $patterns = self::bridgePatterns($bridge, $command->entry) ?? $proposed;
        if (null === $patterns) {
            return null;
        }

        $values = [];
        foreach ($command->params as $name => $value) {
            $values[(string) $name] = $value;
        }
        if (array_any(array_keys($values), static fn (string $name): bool => !\array_key_exists($name, $patterns))
            || array_any(array_keys($patterns), static fn (string $name): bool => !\array_key_exists($name, $values))) {
            return AskInboxHandler::SUGGESTION_PARAM_INVALID;
        }
        foreach ($patterns as $name => $pattern) {
            if (false === self::matches($pattern, $values[$name])) {
                return AskInboxHandler::SUGGESTION_PARAM_INVALID;
            }
        }

        return null;
    }

    public static function isBranchName(string $branch): bool
    {
        return WorkRequest::isBranchName($branch);
    }

    /** @return array<string, string>|null the pattern of each param of the entry the bridge reported, or null when it has no such entry */
    private static function bridgePatterns(?Bridge $bridge, string $entry): ?array
    {
        $row = array_find($bridge->commandEntries ?? [], static fn (array $row): bool => $row['name'] === $entry);
        if (null === $row) {
            return null;
        }

        $patterns = [];
        foreach ($row['params'] as $param) {
            $patterns[$param['name']] = $param['pattern'];
        }

        return $patterns;
    }

    /** @return array<string, string>|null the pattern of each param of the proposed entry, or null when the text is no entry of action command or a param has no pattern */
    private static function proposedPatterns(string $text): ?array
    {
        if (mb_strlen($text) > RunSuggestions::MAX_PROPOSED_ENTRY_LENGTH) {
            return null;
        }
        try {
            $entry = Yaml::parse($text);
        } catch (ParseException) {
            return null;
        }
        if (!\is_array($entry) || array_is_list($entry) || 'command' !== ($entry['action'] ?? null)) {
            return null;
        }

        $params = $entry['params'] ?? [];
        if (!\is_array($params)) {
            return null;
        }
        $patterns = [];
        foreach ($params as $name => $param) {
            $pattern = \is_array($param) ? ($param['pattern'] ?? null) : null;
            if (!\is_string($pattern) || '' === $pattern) {
                return null;
            }
            $patterns[(string) $name] = $pattern;
        }

        return $patterns;
    }

    /** True when the whole value matches, false when it does not, null when PCRE cannot compile the pattern. */
    public static function matches(string $pattern, string $value): ?bool
    {
        $regex = self::DELIMITER.'^(?:'.$pattern.')$'.self::DELIMITER.'Du';

        set_error_handler(static fn (): bool => true);
        try {
            if (false === preg_match($regex, '')) {
                return null;
            }

            return 1 === preg_match($regex, $value);
        } finally {
            restore_error_handler();
        }
    }
}
