<?php

declare(strict_types=1);

namespace App\Tests\Module\DesignSystem;

use App\Module\DesignSystem\Command\ExportDesignSystemCommand;
use App\Module\DesignSystem\Command\ExportDesignSystemHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Twig\Environment;

final class SimpleComponentParityTest extends KernelTestCase
{
    /** The export runs once for the class, because each run compiles the stylesheet. A test must not change it. */
    private static string $directory;

    #[\Override]
    public static function setUpBeforeClass(): void
    {
        self::bootKernel();
        self::$directory = sys_get_temp_dir().'/ds-export-parity-'.bin2hex(random_bytes(4));
        $handler = static::getContainer()->get(ExportDesignSystemHandler::class);
        self::assertInstanceOf(ExportDesignSystemHandler::class, $handler);
        $handler(new ExportDesignSystemCommand(self::$directory));
        self::ensureKernelShutdown();
    }

    #[\Override]
    public static function tearDownAfterClass(): void
    {
        new Filesystem()->remove(self::$directory);
        parent::tearDownAfterClass();
    }

    /** @return iterable<string, array{string, string, string, string, string}> name, plain Twig, modified Twig, wrapper signature, modifier class in the wrapper */
    public static function components(): iterable
    {
        yield 'Badge' => ['Badge', '<twig:Ds:Badge status="draft">A</twig:Ds:Badge>', '<twig:Ds:Badge status="approved">A</twig:Ds:Badge>', '{ status,', "'lp-badge--' + modifier"];
        yield 'Tag' => ['Tag', '<twig:Ds:Tag>A</twig:Ds:Tag>', '<twig:Ds:Tag tone="lime" as="li">A</twig:Ds:Tag>', "{ tone, as: Element = 'span',", "'lp-tag--' + modifier"];
        yield 'Dialog' => ['Dialog', '<twig:Ds:Dialog>A</twig:Ds:Dialog>', '<twig:Ds:Dialog size="search">A</twig:Ds:Dialog>', '{ size,', "'lp-dialog--' + modifier"];
        yield 'Label' => ['Label', '<twig:Ds:Label>A</twig:Ds:Label>', '<twig:Ds:Label as="legend">A</twig:Ds:Label>', "{ as: Element = 'label',", ''];
        yield 'Hint' => ['Hint', '<twig:Ds:Hint>A</twig:Ds:Hint>', '<twig:Ds:Hint as="span">A</twig:Ds:Hint>', "{ as: Element = 'p',", ''];
        yield 'FieldErrors' => ['FieldErrors', '<twig:Ds:FieldErrors>A</twig:Ds:FieldErrors>', '<twig:Ds:FieldErrors as="p">A</twig:Ds:FieldErrors>', "{ as: Element = 'div',", ''];
        yield 'Input' => ['Input', '<twig:Ds:Input />', '<twig:Ds:Input :mono="true" />', '{ mono = false,', "'lp-input--mono'"];
        yield 'Tooltip' => ['Tooltip', '<twig:Ds:Tooltip>A</twig:Ds:Tooltip>', '<twig:Ds:Tooltip :interactive="true">A</twig:Ds:Tooltip>', '{ interactive = false,', "'lp-tooltip--interactive'"];
        yield 'Textarea' => ['Textarea', '<twig:Ds:Textarea>A</twig:Ds:Textarea>', '<twig:Ds:Textarea>A</twig:Ds:Textarea>', '{ defaultValue,', ''];
    }

    #[DataProvider('components')]
    public function test_the_wrapper_draws_the_classes_and_takes_the_props_of_its_twig_component(string $name, string $plain, string $modified, string $signature, string $modifierClass): void
    {
        $jsx = (string) file_get_contents(self::$directory.'/components/core/'.$name.'/'.$name.'.jsx');

        $base = $this->baseClasses($this->render($plain));
        sort($base);
        $wrapper = $this->baseClasses($jsx);
        sort($wrapper);
        self::assertSame($base, $wrapper);

        self::assertStringContainsString($signature, $jsx);
        if ('' !== $modifierClass) {
            self::assertStringContainsString($modifierClass, $jsx);
            self::assertNotSame($this->classNames($this->render($plain)), $this->classNames($this->render($modified)));
        }
        self::assertStringNotContainsString('variant,', $jsx);

        foreach ([$plain, $modified] as $template) {
            foreach ($this->fixedAttributes($this->render($template)) as $attribute => $value) {
                self::assertMatchesRegularExpression('/[\'"]?'.preg_quote($attribute, '/').'[\'"]?(=|: )[\'"]'.preg_quote($value, '/').'[\'"]/', $jsx, $attribute);
            }
        }
    }

    public function test_an_element_prop_renders_the_element_the_twig_component_renders(): void
    {
        self::assertStringContainsString('<li class="lp-tag lp-tag--lime">', $this->render('<twig:Ds:Tag tone="lime" as="li">A</twig:Ds:Tag>'));
        self::assertStringContainsString('<Element {...rest} className={className}>', (string) file_get_contents(self::$directory.'/components/core/Tag/Tag.jsx'));
        self::assertStringContainsString('as?: keyof JSX.IntrinsicElements;', (string) file_get_contents(self::$directory.'/components/core/Tag/Tag.d.ts'));
    }

    public function test_the_prop_types_name_the_twig_props(): void
    {
        foreach (['Badge' => 'status', 'Tag' => 'tone', 'Dialog' => 'size'] as $name => $prop) {
            $types = (string) file_get_contents(self::$directory.'/components/core/'.$name.'/'.$name.'.d.ts');

            self::assertStringContainsString('    '.$prop.'?:', $types);
            self::assertStringNotContainsString('variant?', $types);
        }
        self::assertStringContainsString('mono?: boolean;', (string) file_get_contents(self::$directory.'/components/core/Input/Input.d.ts'));
        self::assertStringContainsString('interactive?: boolean;', (string) file_get_contents(self::$directory.'/components/core/Tooltip/Tooltip.d.ts'));
        self::assertStringContainsString('defaultValue?: string;', (string) file_get_contents(self::$directory.'/components/core/Textarea/Textarea.d.ts'));
    }

    public function test_the_textarea_wrapper_passes_its_text_as_a_default_value(): void
    {
        $jsx = (string) file_get_contents(self::$directory.'/components/core/Textarea/Textarea.jsx');

        self::assertStringContainsString('<textarea {...rest} className={className} defaultValue={defaultValue ?? children} />', $jsx);
        self::assertStringNotContainsString('</textarea>', $jsx);
    }

    private function render(string $template): string
    {
        self::bootKernel();

        return static::getContainer()->get(Environment::class)->createTemplate($template)->render();
    }

    /**
     * The attributes of the rendered root other than its class, which the wrapper must set too.
     *
     * @return array<string, string>
     */
    private function fixedAttributes(string $html): array
    {
        preg_match('/^\s*<[a-z]+([^>]*)>/', $html, $root);
        preg_match_all('/([a-z-]+)="([^"]*)"/', $root[1] ?? '', $matches, \PREG_SET_ORDER);
        $attributes = [];
        foreach ($matches as [, $name, $value]) {
            if ('class' !== $name) {
                $attributes[$name] = $value;
            }
        }

        return $attributes;
    }

    /** @return list<string> */
    private function classNames(string $source): array
    {
        preg_match_all('/lp-[a-z_-]+/', $source, $matches);

        return array_values(array_unique($matches[0]));
    }

    /**
     * Drops the modifier part, so a rendered `lp-badge--draft` and a wrapper
     * `'lp-badge--' + modifier` both read as `lp-badge`.
     *
     * @return list<string>
     */
    private function baseClasses(string $source): array
    {
        return array_values(array_unique(array_map(
            static fn (string $class): string => explode('--', $class)[0],
            $this->classNames($source),
        )));
    }
}
