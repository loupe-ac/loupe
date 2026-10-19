<?php

declare(strict_types=1);

namespace App\Tests\Module\DesignSystem\Token;

use App\Module\DesignSystem\Token\ArtifactTokens;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ArtifactTokensTest extends KernelTestCase
{
    /** @var array<string, mixed> */
    private array $built;

    /** @var array<string, mixed> */
    private array $reference;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $tokens = static::getContainer()->get(ArtifactTokens::class);
        self::assertInstanceOf(ArtifactTokens::class, $tokens);
        $this->built = $tokens->build();
        $this->reference = json_decode((string) file_get_contents(\dirname(__DIR__, 3).'/fixtures/design-system-artifact/tokens.json'), true, flags: \JSON_THROW_ON_ERROR);
    }

    public function test_it_has_the_top_level_keys_of_the_artifact(): void
    {
        self::assertSame(array_keys($this->reference), array_keys($this->built));
        self::assertSame($this->reference['name'], $this->built['name']);
        self::assertSame($this->reference['version'], $this->built['version']);
        self::assertSame($this->reference['color']['themes'], $this->built['color']['themes']);
    }

    public function test_the_colour_spacing_radius_and_shadow_lists_match_the_artifact(): void
    {
        $lists = [
            'color' => [$this->reference['color']['tokens'], $this->built['color']['tokens']],
            'spacing' => [$this->reference['spacing']['tokens'], $this->built['spacing']['tokens']],
            'radius' => [$this->reference['radius']['tokens'], $this->built['radius']['tokens']],
            'shadow' => [$this->reference['shadow']['tokens'], $this->built['shadow']['tokens']],
        ];

        foreach ($lists as $group => [$reference, $built]) {
            self::assertNotEmpty($built, $group);
            self::assertSame($this->nameAndValue($reference), $this->nameAndValue($built), $group);
        }
        self::assertSame($this->reference['shadow']['note'], $this->built['shadow']['note']);
    }

    public function test_the_type_section_matches_the_artifact(): void
    {
        self::assertSame($this->reference['type']['families'], $this->built['type']['families']);
        self::assertSame($this->withoutUsage($this->reference['type']['groups']), $this->withoutUsage($this->built['type']['groups']));
        self::assertEqualsCanonicalizing($this->reference['type']['fonts'], $this->built['type']['fonts']);
    }

    public function test_a_tailwind_colour_refers_to_the_token_it_aliases(): void
    {
        $aliases = array_filter($this->built['color']['tokens'], static fn (array $token): bool => str_starts_with($token['name'], 'color-'));

        self::assertNotEmpty($aliases);
        foreach ($aliases as $token) {
            self::assertMatchesRegularExpression('~^\{[\w-]+\}$~', $token['value'], $token['name']);
        }
    }

    /**
     * @param list<array{name: string, value: string}> $tokens
     *
     * @return list<array{name: string, value: string}>
     */
    private function nameAndValue(array $tokens): array
    {
        return array_map(static fn (array $token): array => ['name' => $token['name'], 'value' => $token['value']], $tokens);
    }

    private function withoutUsage(mixed $value): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }

        unset($value['usage']);

        return array_map($this->withoutUsage(...), $value);
    }
}
