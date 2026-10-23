<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Service;

use App\Module\Account\Service\WaitlistNotificationRecipients;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ubermuda\FeatureFlagsBundle\FeatureFlagService;

final class WaitlistNotificationRecipientsTest extends TestCase
{
    /** @return iterable<string, array{string, list<string>, list<string>}> */
    public static function values(): iterable
    {
        yield 'empty' => ['', [], []];
        yield 'blank' => ["  \n ", [], []];
        yield 'one address' => ['ana@example.com', ['ana@example.com'], []];
        yield 'commas' => ['ana@example.com,bo@example.org', ['ana@example.com', 'bo@example.org'], []];
        yield 'commas and spaces' => ['ana@example.com, bo@example.org', ['ana@example.com', 'bo@example.org'], []];
        yield 'one per line' => ["ana@example.com\nbo@example.org\r\ncy@example.net", ['ana@example.com', 'bo@example.org', 'cy@example.net'], []];
        yield 'lowercased' => ['Ana@Example.COM', ['ana@example.com'], []];
        yield 'duplicates removed' => ['ana@example.com, ANA@example.com, ana@example.com', ['ana@example.com'], []];
        yield 'bad entries are split off' => ['ana@example.com, bo@example, carl', ['ana@example.com'], ['bo@example', 'carl']];
        yield 'bad entries keep order and lose duplicates' => ['carl, bo@example, Carl', [], ['carl', 'bo@example']];
        yield 'trailing comma' => ['ana@example.com,', ['ana@example.com'], []];
    }

    /**
     * @param list<string> $valid
     * @param list<string> $invalid
     */
    #[DataProvider('values')]
    public function test_parse_splits_valid_from_invalid(string $value, array $valid, array $invalid): void
    {
        self::assertSame(['valid' => $valid, 'invalid' => $invalid], WaitlistNotificationRecipients::parse($value));
    }

    public function test_it_reads_the_flag(): void
    {
        $flags = $this->createMock(FeatureFlagService::class);
        $flags->expects($this->once())
            ->method('getStringValue')
            ->with(WaitlistNotificationRecipients::FLAG, '')
            ->willReturn('ana@example.com, bo@example');

        $recipients = new WaitlistNotificationRecipients($flags)->read();

        self::assertSame(['ana@example.com'], $recipients['valid']);
        self::assertSame(['bo@example'], $recipients['invalid']);
    }
}
