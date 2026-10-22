<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

interface ReadsCardType
{
    /**
     * The card types the condition reads. The condition holds for a card of any of them.
     *
     * @param array<string, mixed> $params
     *
     * @return list<string>
     */
    public function cardType(array $params): array;
}
