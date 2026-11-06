<?php

declare(strict_types=1);

namespace App\Module\Bridge\View;

use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Service\CliCompatibility;
use App\Module\Bridge\ValueObject\CliInstallMethod;
use App\Module\Bridge\ValueObject\CliUpdateState;

/** The chip beside a bridge's CLI version. A draining bridge or a version outside the range wins over the other update states. */
final readonly class AgentUpdateChip
{
    /**
     * @param array<string, string> $parameters
     * @param string|null           $command    the shell command that installs the update, when the bridge does not install it itself
     */
    public function __construct(
        public string $label,
        public array $parameters,
        public string $tone,
        public ?string $command = null,
    ) {
    }

    public static function for(Bridge $bridge, bool $compatible, string $installScriptUrl): ?self
    {
        $command = self::installCommand($bridge, $installScriptUrl);

        if (!$compatible && CliUpdateState::Draining !== $bridge->updateState) {
            return new self('bridge.agents.update.incompatible', ['%range%' => CliCompatibility::RANGE], 'failed', $command);
        }

        return match ($bridge->updateState) {
            CliUpdateState::Current => new self('bridge.agents.update.current', [], 'ok'),
            CliUpdateState::Updating => new self('bridge.agents.update.updating', [], 'pending'),
            CliUpdateState::RolledBack => new self('bridge.agents.update.rolled_back', ['%version%' => $bridge->updateVersion ?? '?'], 'pending'),
            CliUpdateState::Blocked => new self('bridge.agents.update.blocked', [], 'failed'),
            // The CLI sends a version with "off" only when a newer release in the range waits.
            CliUpdateState::Off => null === $bridge->updateVersion ? null : new self(
                'bridge.agents.update.available',
                ['%version%' => $bridge->updateVersion],
                'neutral',
                $command,
            ),
            CliUpdateState::Dev, null => null,
            CliUpdateState::Draining => new self('bridge.agents.update.draining', ['%range%' => CliCompatibility::RANGE], 'failed', $command),
        };
    }

    private static function installCommand(Bridge $bridge, string $installScriptUrl): string
    {
        return CliInstallMethod::Homebrew === $bridge->installMethod
            ? 'brew upgrade loupe'
            : 'curl -fsSL '.$installScriptUrl.' | sh';
    }
}
