<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Module\Forge\Service\ForgeBranchEntry;
use App\Module\Forge\Service\ForgeBranchPresence;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\CacheItem;

/** A cache pool that holds a fresh "exists" answer for every branch that anyone asks about. */
final class ExistingBranchesPool extends ArrayAdapter
{
    #[\Override]
    public function getItem(mixed $key): CacheItem
    {
        if (str_starts_with((string) $key, ForgeBranchPresence::KEY_PREFIX) && !$this->hasItem($key)) {
            $now = (float) new \DateTimeImmutable()->format('U.u');
            $item = parent::getItem($key);
            $item->set(new ForgeBranchEntry(true, $now, $now)->toArray());
            $this->save($item);
        }

        return parent::getItem($key);
    }
}
