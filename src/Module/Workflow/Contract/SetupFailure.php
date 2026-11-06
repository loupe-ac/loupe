<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

/**
 * What the output of a failed `before` command says: the exit code, the command and the error line.
 * The bridge writes the reason first and a `command:` line second. An older bridge writes no command line.
 */
final readonly class SetupFailure
{
    private const string PREFIX = 'the before command';

    private const string COMMAND_PREFIX = 'command: ';

    public function __construct(
        public ?int $exitCode,
        /** Null for an output from a bridge that names no command. */
        public ?string $command,
        /** The last non-empty line of the output, or an empty string when the command printed nothing. */
        public string $errorLine,
    ) {
    }

    /** Null when the output is not the output of a failed `before` command. */
    public static function fromOutput(string $output): ?self
    {
        $lines = preg_split('/\R/u', $output) ?: [];
        $first = $lines[0] ?? '';
        if (!str_starts_with($first, self::PREFIX)) {
            return null;
        }
        array_shift($lines);
        $exitCode = 1 === preg_match('/^the before command exited with code (-?\d+)$/D', $first, $match) ? (int) $match[1] : null;
        $command = null;
        if (isset($lines[0]) && str_starts_with($lines[0], self::COMMAND_PREFIX)) {
            $command = substr(array_shift($lines), \strlen(self::COMMAND_PREFIX));
        }
        $errorLine = '';
        foreach (array_reverse($lines) as $line) {
            if ('' !== trim($line)) {
                $errorLine = trim($line);
                break;
            }
        }

        return new self($exitCode, $command, $errorLine);
    }

    /** Two failures with the same fingerprint have the same command, the same exit code and the same error line. */
    public function fingerprint(): string
    {
        return hash('sha256', json_encode([$this->exitCode, $this->command, $this->errorLine], \JSON_THROW_ON_ERROR));
    }
}
