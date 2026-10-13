<?php

declare(strict_types=1);

namespace App\Module\Account\Command\Admin;

use App\Module\Account\Entity\WaitlistEntry;
use Doctrine\ORM\Tools\Pagination\Paginator;

final readonly class ListWaitlistView
{
    /**
     * @param Paginator<WaitlistEntry> $entries
     * @param list<int|null>           $pageList
     * @param array<string, int>       $pickCounts pick value to entry count
     */
    public function __construct(
        public Paginator $entries,
        public int $total,
        public int $totalPages,
        public array $pageList,
        public ?int $clampedPage,
        public array $pickCounts,
    ) {
    }
}
