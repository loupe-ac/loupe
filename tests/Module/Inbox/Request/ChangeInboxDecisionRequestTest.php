<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Request;

use App\Module\Inbox\Form\ChangeInboxDecisionRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ChangeInboxDecisionRequestTest extends TestCase
{
    /** @return iterable<string, array{?string, ?string, string}> */
    public static function inputs(): iterable
    {
        yield 'a ticked option' => ['1', null, 'gamma'];
        yield 'own words win over a ticked option' => ['1', '  delta  ', 'delta'];
        yield 'blank words fall back to the option' => ['0', '   ', 'alpha'];
        yield 'nothing' => [null, null, ''];
        yield 'an index that is not offered' => ['7', null, ''];
    }

    #[DataProvider('inputs')]
    public function test_it_picks_the_choice_to_record(?string $option, ?string $custom, string $expected): void
    {
        self::assertSame($expected, new ChangeInboxDecisionRequest($option, $custom)->choice(['alpha', 'gamma']));
    }
}
