<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\Card;
use App\Module\Board\Repository\CardRepository;

final readonly class FindCardByNumberHandler
{
    public function __construct(
        private CardRepository $cards,
    ) {
    }

    public function __invoke(FindCardByNumberCommand $command): ?Card
    {
        return $this->cards->findOneByProjectAndNumber($command->project, $command->number);
    }
}
