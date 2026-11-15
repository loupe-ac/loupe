<?php

declare(strict_types=1);

namespace App\Tests\Mercure;

use App\Mercure\AccountArea;
use App\Tests\Support\RecordedAccountSignals;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class AccountChangedPublisherTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function areas(): iterable
    {
        yield 'billing' => [AccountArea::BILLING, 'billing'];
        yield 'exports' => [AccountArea::EXPORTS, 'exports'];
        yield 'api keys' => [AccountArea::API_KEYS, 'api_keys'];
        yield 'apps' => [AccountArea::APPS, 'apps'];
        yield 'profile' => [AccountArea::PROFILE, 'profile'];
    }

    #[DataProvider('areas')]
    public function test_it_queues_one_private_signal_that_names_the_area_and_nothing_else(string $area, string $name): void
    {
        $signals = new RecordedAccountSignals();
        $userId = Uuid::v7();

        $signals->publisher->changed($userId, $area);

        self::assertSame($name, $area);
        $signals->assertNothingSentYet();
        $signals->assertSignalled($userId, $area);
    }

    public function test_the_same_area_twice_in_one_request_sends_one_signal(): void
    {
        $signals = new RecordedAccountSignals();
        $userId = Uuid::v7();

        $signals->publisher->changed($userId, AccountArea::APPS);
        $signals->publisher->changed($userId, AccountArea::APPS);

        $signals->assertSignalled($userId, AccountArea::APPS);
    }
}
