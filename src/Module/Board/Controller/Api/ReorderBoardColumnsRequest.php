<?php

declare(strict_types=1);

namespace App\Module\Board\Controller\Api;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The new order. The handler checks every entry, so a refusal names the entry. A
 * missing list stays uninitialized and fails NotNull. An explicit null fails the
 * property type. Both answer 422.
 */
#[OA\Schema(required: ['order'])]
final class ReorderBoardColumnsRequest
{
    /** @var list<mixed> */
    #[Assert\NotNull]
    #[OA\Property(description: 'The slug of every column except backlog, each once, from left to right.', type: 'array', items: new OA\Items(type: 'string'), uniqueItems: true)]
    public array $order;
}
