<?php

declare(strict_types=1);

namespace App\Module\Account\Command\Admin;

use App\Module\Account\Repository\WaitlistBlockPatternRepository;
use App\Module\Account\Repository\WaitlistEntryRepository;

final readonly class ListWaitlistBlockPatternsHandler
{
    public function __construct(
        private WaitlistBlockPatternRepository $waitlistBlockPatterns,
        private WaitlistEntryRepository $waitlistEntries,
    ) {
    }

    public function __invoke(ListWaitlistBlockPatternsCommand $command): ListWaitlistBlockPatternsView
    {
        return new ListWaitlistBlockPatternsView(
            blockPatterns: $this->waitlistBlockPatterns->findAllOrdered(),
            hiddenCount: $this->waitlistEntries->countHidden(),
        );
    }
}
