<?php

declare(strict_types=1);

namespace App\Tests\Module\DesignSystem;

use App\Module\DesignSystem\Catalog;
use App\Tests\Support\ComponentSourceReader;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

final class SimpleComponentParityTest extends KernelTestCase
{
    use ComponentSourceReader;

    /** @return iterable<string, array{string, string, string, string, string}> name, plain Twig, modified Twig, wrapper signature, modifier class in the wrapper */
    public static function components(): iterable
    {
        yield 'Badge' => ['Badge', '<twig:Ds:Badge status="draft">A</twig:Ds:Badge>', '<twig:Ds:Badge status="approved">A</twig:Ds:Badge>', '{ status,', "'lp-badge--' + modifier"];
        yield 'Tag' => ['Tag', '<twig:Ds:Tag>A</twig:Ds:Tag>', '<twig:Ds:Tag tone="lime" as="li">A</twig:Ds:Tag>', "{ tone, as: Element = 'span',", "'lp-tag--' + modifier"];
        yield 'Avatar' => ['Avatar', '<twig:Ds:Avatar>A</twig:Ds:Avatar>', '<twig:Ds:Avatar tone="tint">A</twig:Ds:Avatar>', "{ tone = 'accent', size = 'md', shape = 'round',", "'lp-avatar--' + tone"];
        yield 'Dialog' => ['Dialog', '<twig:Ds:Dialog>A</twig:Ds:Dialog>', '<twig:Ds:Dialog size="search">A</twig:Ds:Dialog>', '{ size,', "'lp-dialog--' + modifier"];
        yield 'Label' => ['Label', '<twig:Ds:Label>A</twig:Ds:Label>', '<twig:Ds:Label as="legend">A</twig:Ds:Label>', "{ as: Element = 'label',", ''];
        yield 'Hint' => ['Hint', '<twig:Ds:Hint>A</twig:Ds:Hint>', '<twig:Ds:Hint as="span">A</twig:Ds:Hint>', "{ as: Element = 'p',", ''];
        yield 'FieldErrors' => ['FieldErrors', '<twig:Ds:FieldErrors>A</twig:Ds:FieldErrors>', '<twig:Ds:FieldErrors as="p">A</twig:Ds:FieldErrors>', "{ as: Element = 'div',", ''];
        yield 'Input' => ['Input', '<twig:Ds:Input />', '<twig:Ds:Input :mono="true" />', '{ mono = false,', "'lp-input--mono'"];
        yield 'Tooltip' => ['Tooltip', '<twig:Ds:Tooltip>A</twig:Ds:Tooltip>', '<twig:Ds:Tooltip :interactive="true">A</twig:Ds:Tooltip>', '{ interactive = false,', "'lp-tooltip--interactive'"];
        yield 'Flash' => ['Flash', '<twig:Ds:Flash severity="info" dismissLabel="Dismiss" title="T">A<twig:block name="actions"><b>B</b></twig:block></twig:Ds:Flash>', '<twig:Ds:Flash severity="error" dismissLabel="Dismiss" title="T">A<twig:block name="actions"><b>B</b></twig:block></twig:Ds:Flash>', '{ severity = ', "'lp-flash--' + severity"];
        yield 'EmptyState' => ['EmptyState', '<twig:Ds:EmptyState icon="lucide:inbox" title="T" body="B" linkLabel="L" linkHref="/x" />', '<twig:Ds:EmptyState onCard icon="lucide:inbox" title="T" body="B" linkLabel="L" linkHref="/x" />', '{ icon, title, body, linkLabel, linkHref, compact, onCard,', "'lp-empty-state--on-card'"];
        yield 'SectionHeading' => ['SectionHeading', '<twig:Ds:SectionHeading description="D">T<twig:block name="actions"><b>B</b></twig:block></twig:Ds:SectionHeading>', '<twig:Ds:SectionHeading :level="3" description="D">T<twig:block name="actions"><b>B</b></twig:block></twig:Ds:SectionHeading>', '{ level = 2, description, titleId, actions,', ''];
        yield 'Pagination' => ['Pagination', '<twig:Ds:Pagination route="app_projects" :page="1" :totalPages="3" :pageList="[1, 2, null, 3]" />', '<twig:Ds:Pagination route="app_projects" :page="2" :totalPages="3" :pageList="[1, 2, null, 3]" />', '{ page, totalPages, pageList,', ''];
        yield 'Textarea' => ['Textarea', '<twig:Ds:Textarea>A</twig:Ds:Textarea>', '<twig:Ds:Textarea>A</twig:Ds:Textarea>', '{ defaultValue,', ''];
    }

    #[DataProvider('components')]
    public function test_the_wrapper_draws_the_classes_and_takes_the_props_of_its_twig_component(string $name, string $plain, string $modified, string $signature, string $modifierClass): void
    {
        $jsx = self::componentJsx($name);

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
        self::assertStringContainsString('<Element {...rest} className={className}>', self::componentJsx('Tag'));
        self::assertStringContainsString('as?: keyof JSX.IntrinsicElements;', self::componentTypes('Tag'));
    }

    public function test_the_prop_types_name_the_twig_props(): void
    {
        foreach (['Avatar' => 'tone', 'Badge' => 'status', 'Tag' => 'tone', 'Dialog' => 'size'] as $name => $prop) {
            $types = self::componentTypes($name);

            self::assertStringContainsString('    '.$prop.'?:', $types);
            self::assertStringNotContainsString('variant?', $types);
        }
        self::assertStringContainsString("shape?: 'round' | 'square';", self::componentTypes('Avatar'));
        self::assertStringContainsString('mono?: boolean;', self::componentTypes('Input'));
        self::assertStringContainsString('interactive?: boolean;', self::componentTypes('Tooltip'));
        self::assertStringContainsString('defaultValue?: string;', self::componentTypes('Textarea'));
        self::assertStringContainsString('severity?:', self::componentTypes('Flash'));
        self::assertStringNotContainsString('variant', self::componentTypes('Flash'));
        self::assertStringContainsString('hrefFor?: (page: number) => string;', self::componentTypes('Pagination'));
    }

    public function test_the_avatar_wrapper_draws_the_shape_of_the_twig_avatar(): void
    {
        $jsx = self::componentJsx('Avatar');

        self::assertStringContainsString('lp-avatar--round', $this->render('<twig:Ds:Avatar>A</twig:Ds:Avatar>'));

        $square = $this->render('<twig:Ds:Avatar shape="square">A</twig:Ds:Avatar>');
        self::assertStringContainsString('lp-avatar--square', $square);
        self::assertStringNotContainsString('lp-avatar--round', $square);

        self::assertStringContainsString("shape = 'round'", $jsx);
        self::assertStringContainsString("'lp-avatar--' + shape", $jsx);
        self::assertStringContainsString("AVATAR_SHAPES = ['round', 'square'];", $jsx);
    }

    public function test_the_textarea_wrapper_passes_its_text_as_a_default_value(): void
    {
        $jsx = self::componentJsx('Textarea');

        self::assertStringContainsString('<textarea {...rest} className={className} defaultValue={defaultValue ?? children} />', $jsx);
        self::assertStringNotContainsString('</textarea>', $jsx);
    }

    public function test_the_pagination_wrapper_uses_the_labels_of_the_app(): void
    {
        $jsx = self::componentJsx('Pagination');
        $html = $this->render('<twig:Ds:Pagination route="app_projects" :page="2" :totalPages="7" :pageList="[1, 2, 3, null, 7]" />');

        foreach (['Page 2 of 7' => 'Page {page} of {totalPages}', 'Previous page' => 'Previous page', 'Next page' => 'Next page', 'aria-label="Pagination"' => 'aria-label="Pagination"'] as $rendered => $wrapper) {
            self::assertStringContainsString($rendered, $html);
            self::assertStringContainsString($wrapper, $jsx);
        }
        self::assertStringContainsString('if (!(totalPages > 1))', $jsx);
    }

    public function test_the_flash_wrapper_draws_the_dismiss_button_only_with_a_label(): void
    {
        $jsx = self::componentJsx('Flash');

        self::assertStringNotContainsString('<button', $this->render('<twig:Ds:Flash severity="info">A</twig:Ds:Flash>'));
        self::assertStringContainsString('{dismissLabel && <button type="button" className="lp-flash__dismiss" data-action="click->flash#dismiss">{dismissLabel}</button>}', $jsx);
    }

    public function test_the_section_heading_wrapper_picks_the_tag_the_id_and_the_description_like_the_twig_component(): void
    {
        $jsx = self::componentJsx('SectionHeading');
        $html = $this->render('<twig:Ds:SectionHeading :level="3" titleId="t" description="D">T</twig:Ds:SectionHeading>');

        self::assertStringContainsString('<h3 class="lp-section-heading__title" id="t">T</h3>', $html);
        self::assertStringContainsString('<p class="lp-section-heading__description">D</p>', $html);
        self::assertStringNotContainsString('lp-section-heading__description', $this->render('<twig:Ds:SectionHeading>T</twig:Ds:SectionHeading>'));

        self::assertStringContainsString("const Heading = 'h' + level;", $jsx);
        self::assertStringContainsString('<Heading className="lp-section-heading__title" id={titleId}>{children}</Heading>', $jsx);
        self::assertStringContainsString('{description && <p className="lp-section-heading__description">{description}</p>}', $jsx);
    }

    public function test_the_section_heading_preview_mounts_the_button_component_for_its_action(): void
    {
        $names = array_map(static fn ($entry): string => $entry->name, new Catalog()->entries());
        $preview = self::componentSource()->preview(self::catalogEntry('SectionHeading'), $names);

        self::assertStringContainsString("dsFind('Button', 'SectionHeading')", $preview);
        self::assertStringNotContainsString('lp-btn', $preview);
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
