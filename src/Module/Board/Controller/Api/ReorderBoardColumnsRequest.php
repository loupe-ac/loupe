<?php

declare(strict_types=1);

namespace App\Module\Board\Controller\Api;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

/** The new order. The handler checks every entry, so a refusal names the entry. */
#[OA\Schema(required: ['order'])]
final readonly class ReorderBoardColumnsRequest
{
    public function __construct(
        /** @var list<mixed>|null */
        #[Assert\NotNull]
        #[OA\Property(description: 'The slug of every column except backlog, each once, from left to right.', type: 'array', items: new OA\Items(type: 'string'), uniqueItems: true)]
        public ?array $order = null,
    ) {
    }
}
