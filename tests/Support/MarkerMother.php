<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Module\Board\Service\StoredCardMarkers;
use App\Module\Workflow\Contract\MarkerKind;
use App\Module\Workflow\Contract\StoredMarker;

/** Builds stored markers for a test. */
final class MarkerMother
{
    /** @param array<string, string|int> $params */
    public static function marker(string $key, MarkerKind $kind, array $params = [], string $since = '2026-10-02 09:45:00', ?string $remedy = null): StoredMarker
    {
        return new StoredMarker($key, $kind, 'marker.'.$key, $remedy ?? 'marker.'.$key.'.remedy', $params, new \DateTimeImmutable($since));
    }

    public static function markers(StoredMarker ...$markers): StoredCardMarkers
    {
        return new StoredCardMarkers(array_values($markers));
    }
}
