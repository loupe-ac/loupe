<?php

declare(strict_types=1);

namespace App\Tests\DevSeed;

use App\DevSeed\DevSeedId;
use PHPUnit\Framework\TestCase;

final class DevSeedIdTest extends TestCase
{
    public function test_the_same_key_gives_the_same_id(): void
    {
        self::assertTrue(DevSeedId::of('board/card/a')->equals(DevSeedId::of('board/card/a')));
    }

    public function test_other_keys_give_other_ids(): void
    {
        self::assertFalse(DevSeedId::of('board/card/a')->equals(DevSeedId::of('board/card/b')));
    }

    public function test_the_id_is_a_fixed_version_5_uuid(): void
    {
        self::assertSame('5', DevSeedId::of('board/card/a')->toRfc4122()[14]);
        self::assertSame(DevSeedId::of('x')->toRfc4122(), DevSeedId::of('x')->toRfc4122());
    }
}
