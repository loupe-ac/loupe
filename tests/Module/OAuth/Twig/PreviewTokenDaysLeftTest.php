<?php

declare(strict_types=1);

namespace App\Tests\Module\OAuth\Twig;

use App\Module\OAuth\Twig\PreviewSignInExtension;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

final class PreviewTokenDaysLeftTest extends TestCase
{
    #[TestWith(['+14 days', 14])]
    #[TestWith(['+14 days -1 second', 14])]
    #[TestWith(['+13 days +1 second', 14])]
    #[TestWith(['+1 day', 1])]
    #[TestWith(['+23 hours', 0])]
    #[TestWith(['-1 hour', 0])]
    public function test_it_rounds_the_days_left_up(string $expiry, int $days): void
    {
        $now = new \DateTimeImmutable('2026-10-11 12:00:00');

        self::assertSame($days, PreviewSignInExtension::daysLeft($now->modify($expiry), $now));
    }
}
