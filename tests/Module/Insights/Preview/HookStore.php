<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Preview;

use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\PersistingStoreInterface;
use Symfony\Component\Lock\Store\InMemoryStore;

/** Runs a callback on the first save, which stands for the time a request waits for the lock. */
final class HookStore implements PersistingStoreInterface
{
    private bool $ran = false;

    public function __construct(
        private readonly InMemoryStore $inner,
        private readonly \Closure $hook,
    ) {
    }

    #[\Override]
    public function save(Key $key): void
    {
        if (!$this->ran) {
            $this->ran = true;
            ($this->hook)();
        }
        $this->inner->save($key);
    }

    #[\Override]
    public function delete(Key $key): void
    {
        $this->inner->delete($key);
    }

    #[\Override]
    public function exists(Key $key): bool
    {
        return $this->inner->exists($key);
    }

    #[\Override]
    public function putOffExpiration(Key $key, float $ttl): void
    {
        $this->inner->putOffExpiration($key, $ttl);
    }
}
