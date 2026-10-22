<?php

declare(strict_types=1);

namespace App\Tests\Site;

use App\Site\SiteLinks;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Ubermuda\FeatureFlagsBundle\Dto\ResolvedFlag;
use Ubermuda\FeatureFlagsBundle\Enum\FeatureFlagType;
use Ubermuda\FeatureFlagsBundle\FeatureFlagService;
use Ubermuda\FeatureFlagsBundle\Reader\FeatureFlagReaderInterface;
use Ubermuda\FeatureFlagsBundle\Reader\InMemoryFeatureFlagReader;

final class SiteLinksTest extends TestCase
{
    private const string DEFAULT_SOURCE = 'https://github.com/loupe-ac/loupe';

    /** @param array<string, mixed> $values */
    private function links(array $values, ?FeatureFlagReaderInterface $reader = null): SiteLinks
    {
        $reader ??= new InMemoryFeatureFlagReader(array_map(
            static fn (string $name, mixed $value): ResolvedFlag => new ResolvedFlag($name, FeatureFlagType::String, $value),
            array_keys($values),
            $values,
        ));

        return new SiteLinks(new FeatureFlagService($reader, new NullLogger()), new NullLogger(), self::DEFAULT_SOURCE);
    }

    public function test_discord_and_x_are_off_when_the_flags_are_missing_or_empty(): void
    {
        foreach ([[], [SiteLinks::DISCORD_FLAG => '', SiteLinks::X_FLAG => '']] as $values) {
            $links = $this->links($values);

            self::assertSame('', $links->discordUrl());
            self::assertSame('', $links->xUrl());
        }
    }

    public function test_discord_and_x_return_the_flag_values(): void
    {
        $links = $this->links([
            SiteLinks::DISCORD_FLAG => ' https://discord.gg/loupe ',
            SiteLinks::X_FLAG => 'https://x.com/loupehq',
        ]);

        self::assertSame('https://discord.gg/loupe', $links->discordUrl());
        self::assertSame('https://x.com/loupehq', $links->xUrl());
    }

    public function test_the_source_link_defaults_to_the_environment_value(): void
    {
        self::assertSame(self::DEFAULT_SOURCE, $this->links([])->sourceUrl());
        self::assertSame(self::DEFAULT_SOURCE, $this->links([SiteLinks::SOURCE_FLAG => ''])->sourceUrl());
    }

    public function test_the_source_flag_overrides_the_environment_value(): void
    {
        $links = $this->links([SiteLinks::SOURCE_FLAG => 'https://git.example/fork/loupe']);

        self::assertSame('https://git.example/fork/loupe', $links->sourceUrl());
    }

    /** @return iterable<string, array{string}> */
    public static function badValues(): iterable
    {
        yield 'relative path' => ['/join'];
        yield 'no scheme' => ['discord.gg/loupe'];
        yield 'javascript scheme' => ['javascript:alert(1)'];
        yield 'mailto' => ['mailto:a@b.example'];
        yield 'scheme without host' => ['https://'];
        yield 'garbage' => ['http:///x'];
    }

    #[DataProvider('badValues')]
    public function test_a_value_that_is_not_a_web_address_counts_as_unset(string $value): void
    {
        $links = $this->links([
            SiteLinks::DISCORD_FLAG => $value,
            SiteLinks::X_FLAG => $value,
            SiteLinks::SOURCE_FLAG => $value,
        ]);

        self::assertSame('', $links->discordUrl());
        self::assertSame('', $links->xUrl());
        self::assertSame(self::DEFAULT_SOURCE, $links->sourceUrl());
    }

    public function test_a_flag_of_the_wrong_type_counts_as_unset(): void
    {
        $reader = new InMemoryFeatureFlagReader([new ResolvedFlag(SiteLinks::DISCORD_FLAG, FeatureFlagType::Bool, true)]);

        self::assertSame('', $this->links([], $reader)->discordUrl());
    }

    public function test_a_database_that_is_down_counts_as_unset(): void
    {
        $reader = $this->createStub(FeatureFlagReaderInterface::class);
        $reader->method('get')->willThrowException(new \RuntimeException('connection refused'));
        $links = $this->links([], $reader);

        self::assertSame('', $links->discordUrl());
        self::assertSame('', $links->xUrl());
        self::assertSame(self::DEFAULT_SOURCE, $links->sourceUrl());
    }
}
