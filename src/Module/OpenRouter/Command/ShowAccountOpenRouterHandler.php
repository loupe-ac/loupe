<?php

declare(strict_types=1);

namespace App\Module\OpenRouter\Command;

use App\Module\OpenRouter\Repository\OpenRouterKeyRepository;
use App\Module\OpenRouter\View\AccountOpenRouterView;

final readonly class ShowAccountOpenRouterHandler
{
    public function __construct(
        private OpenRouterKeyRepository $openRouterKeys,
    ) {
    }

    public function __invoke(ShowAccountOpenRouterCommand $command): AccountOpenRouterView
    {
        return new AccountOpenRouterView(
            $this->openRouterKeys->findSummaryForUser($command->user->id ?? throw new \LogicException('A stored user has an id.')),
        );
    }
}
