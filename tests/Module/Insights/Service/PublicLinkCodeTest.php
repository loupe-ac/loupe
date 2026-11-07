<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Service;

use App\Module\Insights\Service\PublicLinkCode;
use PHPUnit\Framework\TestCase;

final class PublicLinkCodeTest extends TestCase
{
    public function test_a_code_has_twelve_characters_of_the_base32_alphabet(): void
    {
        for ($i = 0; $i < 50; ++$i) {
            self::assertMatchesRegularExpression('/^'.PublicLinkCode::PATTERN.'$/D', PublicLinkCode::generate());
        }
    }

    public function test_two_codes_differ(): void
    {
        self::assertNotSame(PublicLinkCode::generate(), PublicLinkCode::generate());
    }
}
