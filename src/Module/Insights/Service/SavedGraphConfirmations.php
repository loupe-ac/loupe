<?php

declare(strict_types=1);

namespace App\Module\Insights\Service;

use App\Module\Insights\Entity\InsightsSavedGraph;
use Psr\Cache\CacheItemPoolInterface;

/**
 * The one-time code that lets a tool remove a graph after it showed the graph. The code covers the id, the
 * name and the settings of the graph, plus a random value that lives for an hour. A removal or an Undo
 * deletes the value, so a code works for one removal only, also when the web page made the change.
 */
final readonly class SavedGraphConfirmations
{
    private const int LIFETIME_SECONDS = 3600;

    public function __construct(
        private CacheItemPoolInterface $cache,
    ) {
    }

    /** Replaces the code of an earlier preview of the same graph. */
    public function issue(InsightsSavedGraph $graph): string
    {
        $secret = bin2hex(random_bytes(16));
        $this->cache->save($this->cache->getItem(self::key($graph))->set($secret)->expiresAfter(self::LIFETIME_SECONDS));

        return self::code($graph, $secret);
    }

    /** Deletes the stored value first, so a failed removal needs a new preview. */
    public function consume(InsightsSavedGraph $graph, string $code): bool
    {
        $item = $this->cache->getItem(self::key($graph));
        if (!$item->isHit()) {
            return false;
        }
        $secret = $item->get();
        $this->forget($graph);

        return \is_string($secret) && hash_equals(self::code($graph, $secret), $code);
    }

    public function forget(InsightsSavedGraph $graph): void
    {
        $this->cache->deleteItem(self::key($graph));
    }

    private static function code(InsightsSavedGraph $graph, string $secret): string
    {
        return hash('sha256', json_encode([(string) $graph->id, $graph->name, $graph->settings(), $secret], \JSON_THROW_ON_ERROR));
    }

    private static function key(InsightsSavedGraph $graph): string
    {
        return 'saved_graph_removal.'.$graph->id;
    }
}
