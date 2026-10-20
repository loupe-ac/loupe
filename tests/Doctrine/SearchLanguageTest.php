<?php

declare(strict_types=1);

namespace App\Tests\Doctrine;

use App\Doctrine\SearchLanguage;
use PHPUnit\Framework\TestCase;

final class SearchLanguageTest extends TestCase
{
    public function test_the_first_known_language_wins(): void
    {
        self::assertSame(SearchLanguage::German, SearchLanguage::fromPreferred(['xx_YY', 'de_CH', 'en']));
    }

    public function test_a_hyphenated_or_upper_case_code_matches(): void
    {
        self::assertSame(SearchLanguage::Norwegian, SearchLanguage::fromPreferred(['NB-no']));
    }

    public function test_no_known_language_gives_the_default(): void
    {
        self::assertSame(SearchLanguage::DEFAULT, SearchLanguage::fromPreferred(['xx', 'zz_ZZ']));
        self::assertSame(SearchLanguage::DEFAULT, SearchLanguage::fromPreferred([]));
    }
}
