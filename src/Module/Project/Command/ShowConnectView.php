<?php

declare(strict_types=1);

namespace App\Module\Project\Command;

use App\Module\Project\Workshop\WorkshopConnection;

final readonly class ShowConnectView
{
    /**
     * @param list<array{name: string, descriptionKey: string}> $tools
     * @param list<WorkshopConnection>                          $machines
     */
    public function __construct(
        public array $tools,
        public array $machines,
    ) {
    }
}
