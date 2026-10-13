<?php

declare(strict_types=1);

namespace App\Tests\Module\DesignSystem;

use App\Module\DesignSystem\Twig\AvatarExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

final class AvatarComponentTest extends KernelTestCase
{
    private function render(string $template): string
    {
        self::bootKernel();

        return static::getContainer()->get(Environment::class)->createTemplate($template)->render();
    }

    public function test_a_name_draws_its_first_letter_with_the_default_modifiers(): void
    {
        $html = $this->render('<twig:Ds:Avatar name="loupe" />');

        self::assertStringContainsString('class="lp-avatar lp-avatar--md lp-avatar--round lp-avatar--accent"', $html);
        self::assertStringContainsString('>L</span>', $html);
    }

    public function test_the_size_shape_and_tone_become_modifiers(): void
    {
        $html = $this->render('<twig:Ds:Avatar name="Loupe" size="xs" shape="square" tone="danger" data-subject="x" />');

        self::assertStringContainsString('lp-avatar--xs lp-avatar--square lp-avatar--danger', $html);
        self::assertStringContainsString('data-subject="x"', $html);
    }

    public function test_an_icon_draws_in_place_of_a_letter(): void
    {
        $html = $this->render('<twig:Ds:Avatar icon="lucide:bot" />');

        self::assertStringContainsString('<svg', $html);
    }

    public function test_the_content_block_draws_when_there_is_no_name_or_icon(): void
    {
        self::assertStringContainsString('>Custom</span>', $this->render('<twig:Ds:Avatar>Custom</twig:Ds:Avatar>'));
    }

    public function test_a_presence_draws_a_dot_and_no_presence_draws_none(): void
    {
        self::assertStringContainsString('lp-avatar__dot lp-avatar__dot--live', $this->render('<twig:Ds:Avatar name="A" presence="live" />'));
        self::assertStringNotContainsString('lp-avatar__dot', $this->render('<twig:Ds:Avatar name="A" />'));
    }

    #[DataProvider('initials')]
    public function test_the_initial_is_the_first_whole_letter(?string $name, string $expected): void
    {
        self::assertSame($expected, new AvatarExtension()->initial($name));
    }

    /** @return iterable<string, array{?string, string}> */
    public static function initials(): iterable
    {
        yield 'latin' => ['loupe', 'L'];
        yield 'leading space' => ['  ada lovelace', 'A'];
        yield 'greek' => ['ωmega', 'Ω'];
        yield 'combined letter' => ["e\u{0301}clair", "E\u{0301}"];
        yield 'emoji' => ['🔍 search', '🔍'];
        yield 'empty' => ['', ''];
        yield 'null' => [null, ''];
    }
}
