<?php

declare(strict_types=1);

namespace App\Module\Inbox\Controller\Api;

use OpenApi\Attributes as OA;

/** The preview of a run-command action, as the spec shows it. The controller builds the real body. */
#[OA\Schema(required: ['itemId', 'entry', 'bridge', 'branch', 'runList', 'argv', 'params', 'runDigest', 'disabledReason'])]
final readonly class InboxCommandPreviewResponse
{
    /**
     * @param list<string>          $runList
     * @param list<string>          $argv
     * @param array<string, string> $params
     */
    public function __construct(
        #[OA\Property(format: 'uuid')]
        public string $itemId,

        #[OA\Property(description: 'The command entry of the rule file of the bridge.')]
        public string $entry,

        #[OA\Property(description: 'The name of the bridge that runs the command, or null when none follows the project.', nullable: true)]
        public ?string $bridge,

        #[OA\Property(description: 'The branch the command runs on.')]
        public string $branch,

        #[OA\Property(description: 'The run list of the entry with its placeholders, as the last heartbeat of the bridge reported it.', type: 'array', items: new OA\Items(type: 'string'))]
        public array $runList,

        #[OA\Property(description: 'The argv with the built-in values and the params filled in, as the bridge runs it.', type: 'array', items: new OA\Items(type: 'string'))]
        public array $argv,

        #[OA\Property(description: 'The value of each param, by name.', type: 'object', additionalProperties: new OA\AdditionalProperties(type: 'string'))]
        public array $params,

        #[OA\Property(description: 'Binds the item, the entry, the run list, the branch and the param values. Send it with `runInboxItemAction` to run exactly this command.')]
        public string $runDigest,

        #[OA\Property(description: 'Why the command cannot run now, or null when it can.', nullable: true)]
        public ?string $disabledReason,
    ) {
    }
}
