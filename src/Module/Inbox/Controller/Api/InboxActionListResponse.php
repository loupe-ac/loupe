<?php

declare(strict_types=1);

namespace App\Module\Inbox\Controller\Api;

use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/** The actions a worker can suggest, as the spec shows them. The controller builds the real body. */
#[OA\Schema(required: ['actions'])]
final readonly class InboxActionListResponse
{
    /** @param list<InboxActionRowResponse> $actions */
    public function __construct(
        #[OA\Property(type: 'array', items: new OA\Items(ref: new Model(type: InboxActionRowResponse::class)))]
        public array $actions,
    ) {
    }
}
