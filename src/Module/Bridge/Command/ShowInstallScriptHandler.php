<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\Service\CliCompatibility;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Returns the CLI install script, with the instance URL and the CLI major version set under its shebang.
 * It leaves out each release key line whose fingerprint the instance lists as revoked.
 */
final readonly class ShowInstallScriptHandler
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/cli/install.sh')]
        private string $path,
        /** @var list<string> */
        private array $revokedKeys = CliCompatibility::REVOKED_RELEASE_KEYS,
    ) {
    }

    public function __invoke(ShowInstallScriptCommand $command): string
    {
        $script = is_file($this->path) ? file_get_contents($this->path) : false;
        if (false === $script) {
            throw new \RuntimeException(sprintf('The install script "%s" cannot be read.', $this->path));
        }

        $shebang = strpos($script, "\n");
        if (false === $shebang) {
            throw new \RuntimeException(sprintf('The install script "%s" has no line after its shebang.', $this->path));
        }

        return substr($script, 0, $shebang + 1)
            .'LOUPE_URL='.self::quote($command->loupeUrl)."\n"
            .'LOUPE_CLI_MAJOR='.self::quote((string) CliCompatibility::major())."\n"
            .$this->withoutRevokedKeys(substr($script, $shebang + 1));
    }

    private function withoutRevokedKeys(string $script): string
    {
        if ([] === $this->revokedKeys) {
            return $script;
        }

        $inBlock = false;
        $kept = [];
        foreach (explode("\n", $script) as $line) {
            if ('# BEGIN release_keys' === $line) {
                $inBlock = true;
            } elseif ('# END release_keys' === $line) {
                $inBlock = false;
            } elseif ($inBlock && in_array(self::fingerprint($line), $this->revokedKeys, true)) {
                continue;
            }
            $kept[] = $line;
        }

        return implode("\n", $kept);
    }

    /** The `ssh-keygen -l` fingerprint of a `loupe-release ssh-ed25519 <blob>` line, or null for any other line. */
    private static function fingerprint(string $line): ?string
    {
        if (1 !== preg_match('/^loupe-release ssh-ed25519 ([A-Za-z0-9+\/]+=*)\s*$/', $line, $matches)) {
            return null;
        }
        $blob = base64_decode($matches[1], true);
        if (false === $blob) {
            return null;
        }

        return 'SHA256:'.rtrim(base64_encode(hash('sha256', $blob, true)), '=');
    }

    private static function quote(string $value): string
    {
        return "'".str_replace("'", "'\\''", $value)."'";
    }
}
