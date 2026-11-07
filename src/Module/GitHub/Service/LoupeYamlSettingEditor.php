<?php

declare(strict_types=1);

namespace App\Module\GitHub\Service;

use App\Module\Forge\Service\PullRequestWriteFailed;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Sets one key of the `workflow:` map in `.loupe.yaml` with a line edit, so every other line and comment stays.
 * The result is parsed again and compared with the input before it is returned. An existing file must name the
 * project, because Loupe ignores a file that names no project or another one.
 */
final class LoupeYamlSettingEditor
{
    private const string TOKEN = '/^[A-Za-z0-9][A-Za-z0-9._-]*$/';

    /** @throws PullRequestWriteFailed */
    public static function edit(?string $content, string $key, string $value, string $projectId): string
    {
        if (1 !== preg_match(self::TOKEN, $key) || 1 !== preg_match(self::TOKEN, $value)) {
            throw new PullRequestWriteFailed('invalid_setting', permanent: true);
        }

        $existing = null === $content || '' === trim($content) ? null : $content;
        $before = null === $existing ? null : self::parse($existing);
        if (null !== $before && !\is_array($before)) {
            throw new PullRequestWriteFailed('file_unparsable', permanent: true);
        }
        if (null !== $existing && null === WorkflowFileSettings::forProject($existing, Uuid::fromString($projectId))) {
            throw new PullRequestWriteFailed('project_mismatch', permanent: true);
        }

        $result = null === $existing
            ? 'project: '.$projectId."\nworkflow:\n  ".$key.': '.$value."\n"
            : self::editLines($existing, $key, $value);

        if (\strlen($result) > GitHubWorkflowFileReader::MAX_BYTES) {
            throw new PullRequestWriteFailed('file_too_large', permanent: true);
        }

        $after = self::parse($result);
        if (!\is_array($after) || !self::keepsTheRest($before, $after, $key, $value)) {
            throw new PullRequestWriteFailed('file_unparsable', permanent: true);
        }

        return $result;
    }

    private static function editLines(string $content, string $key, string $value): string
    {
        $eol = str_contains($content, "\r\n") ? "\r\n" : "\n";
        $lines = explode("\n", rtrim(str_replace("\r\n", "\n", $content), "\n"));

        $start = null;
        foreach ($lines as $index => $line) {
            if (1 === preg_match('/^workflow:[ \t]*(#.*)?$/', $line)) {
                $start = $index;

                break;
            }
            if (str_starts_with($line, 'workflow:')) {
                throw new PullRequestWriteFailed('file_unparsable', permanent: true);
            }
        }

        if (null === $start) {
            array_push($lines, 'workflow:', '  '.$key.': '.$value);

            return implode($eol, $lines).$eol;
        }

        $indent = '  ';
        $lastContent = $start;
        $replace = null;
        $indentKnown = false;
        for ($i = $start + 1, $count = \count($lines); $i < $count; ++$i) {
            $line = $lines[$i];
            $trimmed = trim($line);
            if ('' === $trimmed || str_starts_with($trimmed, '#')) {
                continue;
            }
            if (1 !== preg_match('/^([ \t]+)\S/', $line, $match)) {
                break;
            }

            $lastContent = $i;
            if (!$indentKnown) {
                $indent = $match[1];
                $indentKnown = true;
            }
            if (null === $replace && 1 === preg_match('/^'.preg_quote($indent, '/').preg_quote($key, '/').':(\s|$)/', $line)) {
                $replace = $i;
            }
        }

        if (null !== $replace) {
            $lines[$replace] = $indent.$key.': '.$value;
        } else {
            array_splice($lines, $lastContent + 1, 0, [$indent.$key.': '.$value]);
        }

        return implode($eol, $lines).$eol;
    }

    private static function parse(string $content): mixed
    {
        try {
            return Yaml::parse($content);
        } catch (ParseException) {
            throw new PullRequestWriteFailed('file_unparsable', permanent: true);
        }
    }

    /**
     * @param ?array<mixed> $before null when the file did not exist
     * @param array<mixed>  $after
     */
    private static function keepsTheRest(?array $before, array $after, string $key, string $value): bool
    {
        $workflow = $after['workflow'] ?? null;
        if (!\is_array($workflow) || !\array_key_exists($key, $workflow)) {
            return false;
        }

        $set = $workflow[$key];
        if (\is_bool($set)) {
            $set = $set ? 'true' : 'false';
        }
        if (!\is_scalar($set) || (string) $set !== $value) {
            return false;
        }

        if (null === $before) {
            return true;
        }

        unset($workflow[$key]);
        $after['workflow'] = $workflow;
        $old = $before;
        $oldWorkflow = \is_array($old['workflow'] ?? null) ? $old['workflow'] : [];
        unset($oldWorkflow[$key]);
        $old['workflow'] = $oldWorkflow;

        return $old == $after;
    }
}
