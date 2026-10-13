<?php

declare(strict_types=1);

namespace App\Tests\Module\DesignSystem\Command;

use App\Module\DesignSystem\Catalog;
use App\Module\DesignSystem\Command\ExportDesignSystemCommand;
use App\Module\DesignSystem\Command\ExportDesignSystemHandler;
use App\Module\DesignSystem\ComponentEntry;
use App\Module\DesignSystem\Service\TailwindCompiler;
use App\Module\DesignSystem\Token\TokenReader;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;

final class ExportDesignSystemHandlerTest extends KernelTestCase
{
    private string $directory;

    /** @var list<string> */
    private array $written;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->directory = sys_get_temp_dir().'/ds-export-'.bin2hex(random_bytes(4));
        $handler = static::getContainer()->get(ExportDesignSystemHandler::class);
        self::assertInstanceOf(ExportDesignSystemHandler::class, $handler);
        $this->written = $handler(new ExportDesignSystemCommand($this->directory));
    }

    #[\Override]
    protected function tearDown(): void
    {
        new Filesystem()->remove($this->directory);
        parent::tearDown();
    }

    public function test_it_writes_the_project_layout(): void
    {
        foreach ([
            'SKILL.md',
            'readme.md',
            'styles.css',
            'tokens/colors.css',
            'tokens/typography.css',
            'tokens/spacing.css',
            'tokens/elevation.css',
            'tokens/motion.css',
            'tokens/fonts.css',
            'tokens/base.css',
            'components/core/Button.jsx',
            'components/core/Button.d.ts',
            'components/core/Button.prompt.md',
            'components/core/core.card.html',
            'guidelines/colour.html',
            'assets/fonts/Geist-Variable.woff2',
            'assets/fonts/LICENSE.txt',
        ] as $path) {
            self::assertContains($path, $this->written);
            self::assertFileExists($this->directory.'/'.$path);
        }
    }

    public function test_it_reports_exactly_the_files_on_disk(): void
    {
        $onDisk = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $onDisk[] = substr((string) $file, \strlen($this->directory) + 1);
        }
        sort($onDisk);

        self::assertSame($onDisk, $this->written);
    }

    public function test_it_writes_no_platform_file(): void
    {
        foreach (['_ds_bundle.js', '_ds_manifest.json', '_adherence.oxlintrc.json', '.thumbnail', 'ds-mount.js'] as $file) {
            self::assertFileDoesNotExist($this->directory.'/'.$file);
        }
    }

    public function test_button_lists_every_catalog_variant_and_the_real_classes(): void
    {
        $jsx = (string) file_get_contents($this->directory.'/components/core/Button.jsx');
        $types = (string) file_get_contents($this->directory.'/components/core/Button.d.ts');
        $entry = new Catalog()->entries()[0];

        self::assertNotEmpty($entry->variants);
        foreach ($entry->variants as $variant) {
            self::assertStringContainsString("'".$variant."'", $jsx);
            self::assertStringContainsString("| '".$variant."'", $types);
        }
        self::assertStringContainsString("'lp-btn--' + modifier", $jsx);
        self::assertStringContainsString('@startingPoint section="Core"', $types);
    }

    public function test_the_stylesheet_holds_the_real_button_rules(): void
    {
        $css = (string) file_get_contents($this->directory.'/styles.css');

        self::assertStringStartsWith("@import './tokens/fonts.css';", $css);
        self::assertStringContainsString('.lp-btn--primary', $css);
        self::assertStringContainsString('--accent:', $css);
        foreach (glob($this->directory.'/tokens/*.css') ?: [] as $file) {
            self::assertStringContainsString("@import './tokens/".basename($file)."';", $css);
        }
    }

    public function test_the_second_tailwind_input_imports_every_component_stylesheet(): void
    {
        $root = \dirname(__DIR__, 4).'/assets/styles';
        $input = (string) file_get_contents($root.'/design-system.css');

        foreach (glob($root.'/components/*.css') ?: [] as $file) {
            self::assertStringContainsString("@import './components/".basename($file)."';", $input);
        }
    }

    public function test_every_wrapper_forwards_attributes_and_merges_a_caller_class(): void
    {
        foreach (['Button', 'Flash'] as $name) {
            $jsx = (string) file_get_contents($this->directory.'/components/core/'.$name.'.jsx');

            self::assertStringContainsString('className: extra', $jsx);
            self::assertStringContainsString('classes.push(extra)', $jsx);
            self::assertStringContainsString('{...rest}', $jsx);

            $types = (string) file_get_contents($this->directory.'/components/core/'.$name.'.d.ts');
            self::assertStringContainsString('className?: string;', $types);
            self::assertStringContainsString('[attribute: string]: unknown;', $types);
        }
    }

    public function test_each_wrapper_renders_the_element_of_its_component(): void
    {
        foreach (['Dialog' => 'dialog', 'Tabs' => 'nav', 'Pagination' => 'nav', 'Tooltip' => 'span'] as $name => $element) {
            $jsx = (string) file_get_contents($this->directory.'/components/core/'.$name.'.jsx');

            self::assertStringContainsString('<'.$element.' {...rest}', $jsx);
        }
    }

    public function test_the_export_defines_every_keyframe_a_component_stylesheet_uses(): void
    {
        $css = (string) file_get_contents($this->directory.'/styles.css');

        foreach (['lp-appear', 'lp-dialog-backdrop-in'] as $keyframes) {
            self::assertStringContainsString('@keyframes '.$keyframes, $css);
        }
    }

    public function test_tokens_are_plain_root_blocks(): void
    {
        $css = (string) file_get_contents($this->directory.'/tokens/elevation.css');

        self::assertStringContainsString('--radius-lg', $css);
        self::assertStringContainsString('--shadow-card', $css);
        self::assertStringNotContainsString('@theme', $css);
        self::assertStringNotContainsString('@group', $css);
    }

    public function test_the_card_uses_the_platform_mount(): void
    {
        $card = (string) file_get_contents($this->directory.'/components/core/core.card.html');

        $count = \count(array_filter(new Catalog()->entries(), static fn ($entry) => 'core' === $entry->group));

        self::assertStringStartsWith('<!-- @dsCard group="Components" viewport="700x'.(80 + 70 * $count).'"', $card);
        self::assertStringContainsString("dsFind('Button', 'Input'", $card);
        self::assertStringContainsString('<script src="../../ds-mount.js">', $card);
    }

    public function test_the_card_shows_each_dialog_and_tooltip_example(): void
    {
        $card = (string) file_get_contents($this->directory.'/components/core/core.card.html');

        self::assertStringContainsString('<Dialog variant="document" open>document</Dialog>', $card);
        self::assertStringContainsString('.row > dialog[open] { position: static; margin: 0; }', $card);
        self::assertStringContainsString('.row > .lp-tooltip { position: static; margin: 0; visibility: visible; opacity: 1; }', $card);
        self::assertStringContainsString('<Tabs>Example</Tabs>', $card);
    }

    public function test_a_non_button_component_is_not_a_button(): void
    {
        $jsx = (string) file_get_contents($this->directory.'/components/core/Flash.jsx');
        $types = (string) file_get_contents($this->directory.'/components/core/Badge.d.ts');
        $prompt = (string) file_get_contents($this->directory.'/components/core/Flash.prompt.md');
        $card = (string) file_get_contents($this->directory.'/components/core/core.card.html');

        self::assertStringContainsString('<div {...rest} className={className}>', $jsx);
        self::assertStringNotContainsString('<button', $jsx);
        self::assertStringNotContainsString('disabled', $jsx);
        self::assertStringNotContainsString('href', $types);
        self::assertStringNotContainsString('disabled', $prompt);
        self::assertStringContainsString('<Flash variant="success">Example</Flash>', $prompt);
        self::assertStringContainsString('<EmptyState>Example</EmptyState>', $card);
    }

    public function test_a_component_with_a_dot_renders_its_child_elements(): void
    {
        $flash = (string) file_get_contents($this->directory.'/components/core/Flash.jsx');
        $chip = (string) file_get_contents($this->directory.'/components/core/StatusChip.jsx');
        $badge = (string) file_get_contents($this->directory.'/components/core/Badge.jsx');

        self::assertStringContainsString('{dot && <span className="lp-flash__dot"></span>}', $flash);
        self::assertStringContainsString('<span className="lp-flash__message">{children}</span>', $flash);
        self::assertStringContainsString('{dot && <span className="lp-status-chip__dot"></span>}', $chip);
        self::assertStringContainsString('dot = true', $chip);
        self::assertStringContainsString('dot?: boolean;', (string) file_get_contents($this->directory.'/components/core/StatusChip.d.ts'));
        self::assertStringNotContainsString('__dot', $badge);
        self::assertStringNotContainsString('dot', (string) file_get_contents($this->directory.'/components/core/Badge.d.ts'));
    }

    public function test_the_input_wrapper_is_a_void_element(): void
    {
        $jsx = (string) file_get_contents($this->directory.'/components/core/Input.jsx');
        $prompt = (string) file_get_contents($this->directory.'/components/core/Input.prompt.md');
        $card = (string) file_get_contents($this->directory.'/components/core/core.card.html');

        self::assertStringContainsString('return <input {...rest} className={className} />;', $jsx);
        self::assertStringNotContainsString('</input>', $jsx);
        self::assertStringContainsString('<Input placeholder="Example" />', $prompt);
        self::assertStringContainsString('<Input variant="mono" placeholder="mono" />', $card);
        self::assertStringNotContainsString('</Input>', $card);
        self::assertStringContainsString('<Select><option>Example</option></Select>', $card);
        self::assertStringContainsString('children?: never;', (string) file_get_contents($this->directory.'/components/core/Input.d.ts'));
        self::assertStringContainsString("const classes = ['lp-input', 'lp-select'];", (string) file_get_contents($this->directory.'/components/core/Select.jsx'));
        self::assertStringContainsString("const classes = ['lp-input', 'lp-textarea'];", (string) file_get_contents($this->directory.'/components/core/Textarea.jsx'));
        self::assertStringContainsString("const classes = ['lp-label'];", (string) file_get_contents($this->directory.'/components/core/Label.jsx'));
        self::assertStringContainsString('<label {...rest} className={className}>', (string) file_get_contents($this->directory.'/components/core/Label.jsx'));
    }

    public function test_the_marketing_shell_export_draws_the_header_the_body_and_the_footer(): void
    {
        foreach (['MarketingShell.jsx', 'MarketingShell.d.ts', 'MarketingShell.prompt.md'] as $file) {
            self::assertContains('components/layout/'.$file, $this->written);
        }

        $jsx = (string) file_get_contents($this->directory.'/components/layout/MarketingShell.jsx');
        self::assertStringContainsString("const classes = ['lp-landing'];", $jsx);
        self::assertStringContainsString('<header className="lp-landing-nav">', $jsx);
        self::assertStringContainsString('<main>{children}</main>', $jsx);
        self::assertStringContainsString('<footer className="lp-landing-footer">', $jsx);

        $types = (string) file_get_contents($this->directory.'/components/layout/MarketingShell.d.ts');
        self::assertStringContainsString('@startingPoint section="Layout"', $types);

        $card = (string) file_get_contents($this->directory.'/components/layout/layout.card.html');
        self::assertStringContainsString('<MarketingShell>', $card);
    }

    public function test_the_brand_mark_export_draws_the_brand_name_and_each_option(): void
    {
        foreach (['BrandMark.jsx', 'BrandMark.d.ts', 'BrandMark.prompt.md', 'layout.card.html'] as $file) {
            self::assertContains('components/layout/'.$file, $this->written);
        }

        $jsx = (string) file_get_contents($this->directory.'/components/layout/BrandMark.jsx');
        self::assertStringContainsString("export const BRANDMARK_SIZES = ['sm', 'md'];", $jsx);
        self::assertStringContainsString("'lp-brand--' + size", $jsx);
        self::assertStringContainsString("classes.push('lp-brand--mark-only')", $jsx);
        self::assertStringContainsString('<span className="lp-brand__mark" role="img" aria-label="loupe"></span>', $jsx);
        self::assertStringContainsString('<span className="lp-brand__mark" aria-hidden="true"></span>', $jsx);
        self::assertStringContainsString('<span className="lp-brand__word">loupe</span>', $jsx);
        self::assertStringContainsString('{...rest}', $jsx);

        $types = (string) file_get_contents($this->directory.'/components/layout/BrandMark.d.ts');
        self::assertStringContainsString("size?: 'sm' | 'md';", $types);
        self::assertStringContainsString('markOnly?: boolean;', $types);
        self::assertStringContainsString('@startingPoint section="Layout"', $types);

        $card = (string) file_get_contents($this->directory.'/components/layout/layout.card.html');
        self::assertStringContainsString("dsFind('BrandMark', 'MarketingShell')", $card);
        foreach (['<BrandMark size="md" />', '<BrandMark size="sm" />', '<BrandMark markOnly />'] as $example) {
            self::assertStringContainsString($example, $card);
        }
        self::assertStringNotContainsString('>Example</BrandMark>', $card);
    }

    public function test_an_entry_with_an_export_folder_renders_its_own_templates(): void
    {
        $directory = sys_get_temp_dir().'/ds-export-layout-'.bin2hex(random_bytes(4));
        $catalog = new readonly class extends Catalog {
            #[\Override]
            public function entries(): array
            {
                return [
                    ...parent::entries(),
                    new ComponentEntry(
                        name: 'SamplePart',
                        rootClass: 'lp-sample-part',
                        template: null,
                        variants: [],
                        states: [],
                        enforced: false,
                        group: 'layout',
                        summary: 'A sample part.',
                        export: 'Sample',
                    ),
                ];
            }
        };
        $handler = new ExportDesignSystemHandler(
            $catalog,
            static::getContainer()->get(TokenReader::class),
            static::getContainer()->get(TailwindCompiler::class),
            static::getContainer()->get('twig'),
            \dirname(__DIR__, 4),
        );

        try {
            $written = $handler(new ExportDesignSystemCommand($directory));

            foreach (['SamplePart.jsx', 'SamplePart.d.ts', 'SamplePart.prompt.md', 'layout.card.html'] as $file) {
                self::assertContains('components/layout/'.$file, $written);
            }
            self::assertStringContainsString('<header className="lp-sample-part">', (string) file_get_contents($directory.'/components/layout/SamplePart.jsx'));
            self::assertStringContainsString('@startingPoint section="Layout"', (string) file_get_contents($directory.'/components/layout/SamplePart.d.ts'));

            $card = (string) file_get_contents($directory.'/components/layout/layout.card.html');
            self::assertMatchesRegularExpression("~dsFind\([^)]*'SamplePart'\)~", $card);
            self::assertStringContainsString('<SamplePart title="Example" />', $card);
            self::assertStringContainsString('<script src="../../ds-mount.js">', $card);
            self::assertStringNotContainsString('<Button', $card);
            self::assertFileExists($directory.'/components/core/Button.jsx');
        } finally {
            new Filesystem()->remove($directory);
        }
    }
}
