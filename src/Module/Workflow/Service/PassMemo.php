<?php

declare(strict_types=1);

namespace App\Module\Workflow\Service;

use App\Module\Workflow\Contract\FactsPass;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Contracts\Service\ResetInterface;

/** The memo of one fact pass. Outside a pass it stores nothing, so a read after a write sees the new rows. */
#[AsAlias(FactsPass::class)]
final class PassMemo implements FactsPass, ResetInterface
{
    /** @var array<string, mixed>|null */
    private ?array $results = null;

    /**
     * @template T
     *
     * @param \Closure(): T $build
     *
     * @return T the result of the build, with the pass closed
     */
    public function during(\Closure $build): mixed
    {
        if (null !== $this->results) {
            return $build();
        }
        $this->results = [];
        try {
            return $build();
        } finally {
            $this->results = null;
        }
    }

    #[\Override]
    public function remember(string $key, \Closure $read): mixed
    {
        if (null === $this->results) {
            return $read();
        }
        if (!\array_key_exists($key, $this->results)) {
            $this->results[$key] = $read();
        }

        return $this->results[$key];
    }

    #[\Override]
    public function reset(): void
    {
        $this->results = null;
    }
}
