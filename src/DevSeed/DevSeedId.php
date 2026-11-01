<?php

declare(strict_types=1);

namespace App\DevSeed;

use Symfony\Component\Uid\Uuid;

final class DevSeedId
{
    public const string NAMESPACE_ID = '6f1d3c52-8a47-4e0b-9b2e-5d7c4a91e3f8';

    public static function of(string $key): Uuid
    {
        return Uuid::v5(Uuid::fromString(self::NAMESPACE_ID), $key);
    }
}
