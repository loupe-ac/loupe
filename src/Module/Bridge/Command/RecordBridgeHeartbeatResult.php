<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Entity\BridgeCommand;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\ValueObject\AccountLimit;

/**
 * The recorded bridge, the CLI range the server answers it with, the commands
 * the bridge has still to act on, oldest first, the open work requests it can
 * claim, oldest first, the ids of the named claims it no longer holds, and the
 * ids of the interactive sessions it launched and has to report on, and the
 * accounts that take no new work.
 */
final readonly class RecordBridgeHeartbeatResult
{
    /**
     * @param list<BridgeCommand>         $commands
     * @param list<WorkRequest>           $workRequests
     * @param list<string>                $lostClaims      RFC 4122 ids
     * @param list<string>                $watchSessions   RFC 4122 ids
     * @param array<string, AccountLimit> $blockedAccounts by account name
     */
    public function __construct(
        public Bridge $bridge,
        public string $cliRange,
        public array $commands,
        public array $workRequests,
        public array $lostClaims,
        public array $watchSessions = [],
        public array $blockedAccounts = [],
    ) {
    }
}
