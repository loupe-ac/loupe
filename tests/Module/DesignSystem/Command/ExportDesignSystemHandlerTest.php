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
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Filesystem\Filesystem;
use Twig\Environment;

final class ExportDesignSystemHandlerTest extends KernelTestCase
{
    /** The export runs once for the class, because each run compiles the stylesheet. A test must not change it. */
    private string $directory;

    /** @var list<string> */
    private array $written;

    private static string $exportDirectory;

    /** @var list<string> */
    private static array $exportWritten;

    #[\Override]
    public static function setUpBeforeClass(): void
    {
        self::bootKernel();
        self::$exportDirectory = sys_get_temp_dir().'/ds-export-'.bin2hex(random_bytes(4));
        $handler = static::getContainer()->get(ExportDesignSystemHandler::class);
        self::assertInstanceOf(ExportDesignSystemHandler::class, $handler);
        self::$exportWritten = $handler(new ExportDesignSystemCommand(self::$exportDirectory));
        self::ensureKernelShutdown();
    }

    #[\Override]
    public static function tearDownAfterClass(): void
    {
        new Filesystem()->remove(self::$exportDirectory);
        parent::tearDownAfterClass();
    }

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->directory = self::$exportDirectory;
        $this->written = self::$exportWritten;
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
            'components/core/Button/Button.jsx',
            'components/core/Button/Button.d.ts',
            'components/core/Button/Button.prompt.md',
            'components/core/Button/Button.card.html',
            'components/layout/PageHeader/PageHeader.jsx',
            'components/layout/PageHeader/PageHeader.d.ts',
            'components/layout/PageHeader/PageHeader.prompt.md',
            'components/layout/SplitView/SplitView.jsx',
            'components/layout/SplitView/SplitView.d.ts',
            'components/layout/SplitView/SplitView.prompt.md',
            'components/layout/ListRow/ListRow.jsx',
            'components/layout/ListRow/ListRow.d.ts',
            'components/layout/ListRow/ListRow.prompt.md',
            'components/layout/Workspace/Workspace.jsx',
            'components/layout/Workspace/Workspace.d.ts',
            'components/layout/Workspace/Workspace.prompt.md',
            'components/layout/PageHeader/PageHeader.card.html',
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
        $jsx = (string) file_get_contents($this->directory.'/components/core/Button/Button.jsx');
        $types = (string) file_get_contents($this->directory.'/components/core/Button/Button.d.ts');
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
        foreach (['Button', 'Badge', 'Flash', 'EmptyState'] as $name) {
            $jsx = (string) file_get_contents($this->directory.'/components/core/'.$name.'/'.$name.'.jsx');

            self::assertStringContainsString('className: extra', $jsx);
            self::assertStringContainsString('classes.push(extra)', $jsx);
            self::assertStringContainsString('{...rest}', $jsx);

            $types = (string) file_get_contents($this->directory.'/components/core/'.$name.'/'.$name.'.d.ts');
            self::assertStringContainsString('className?: string;', $types);
            self::assertStringContainsString('[attribute: string]: unknown;', $types);
        }
    }

    public function test_each_wrapper_renders_the_element_of_its_component(): void
    {
        foreach (['Dialog' => '<dialog data-modal-target="dialog" {...rest}', 'Tabs' => '<nav {...rest}', 'Pagination' => '<nav {...rest}'] as $name => $tag) {
            $jsx = (string) file_get_contents($this->directory.'/components/core/'.$name.'/'.$name.'.jsx');

            self::assertStringContainsString($tag, $jsx);
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
        $card = (string) file_get_contents($this->directory.'/components/core/Button/Button.card.html');

        self::assertStringStartsWith('<!-- @dsCard group="Components" viewport="700x330" subtitle="Any action or link that looks like a button." name="Button" -->', $card);
        self::assertStringContainsString("dsFind('Button')", $card);
        self::assertStringContainsString('<link rel="stylesheet" href="../../../styles.css">', $card);
        self::assertStringContainsString('<script src="../../../ds-mount.js">', $card);
    }

    public function test_each_entry_has_a_card_of_its_own_that_names_only_its_component(): void
    {
        foreach (new Catalog()->entries() as $entry) {
            $path = sprintf('components/%1$s/%2$s/%2$s.card.html', $entry->group, $entry->name);
            self::assertContains($path, $this->written);

            $card = (string) file_get_contents($this->directory.'/'.$path);
            self::assertStringContainsString(sprintf('viewport="%s"', $entry->viewport), $card);
            self::assertStringContainsString(sprintf('name="%s"', $entry->name), $card);
            self::assertMatchesRegularExpression("~dsFind\\([^)]*'".$entry->name."'~", $card);
        }
        self::assertNotContains('components/core/core.card.html', $this->written);
        self::assertNotContains('components/layout/layout.card.html', $this->written);
    }

    public function test_the_badge_card_holds_no_button(): void
    {
        $card = (string) file_get_contents($this->directory.'/components/core/Badge/Badge.card.html');

        self::assertStringContainsString('<Badge status="draft">draft</Badge>', $card);
        self::assertStringNotContainsString('<Button', $card);
        self::assertStringNotContainsString('<Input', $card);
    }

    public function test_the_button_card_draws_outline_on_chrome_and_icons_with_a_glyph(): void
    {
        $card = (string) file_get_contents($this->directory.'/components/core/Button/Button.card.html');

        self::assertStringContainsString('<div className="chrome">', $card);
        self::assertMatchesRegularExpression('~<div className="chrome">\s*<Button variant="outline">outline</Button>~', $card);
        self::assertStringNotContainsString('<Button variant="outline">outline</Button>'."\n".'        <Button variant="primary"', $card);
        self::assertStringNotContainsString('>icon</Button>', $card);
        self::assertStringNotContainsString('>compact</Button>', $card);
        foreach (['icon', 'ghost icon', 'ghost icon compact'] as $variant) {
            self::assertMatchesRegularExpression('~<Button variant="'.$variant.'" aria-label="Close"><svg ~', $card);
        }
    }

    public function test_the_textarea_card_passes_its_text_as_a_default_value(): void
    {
        $card = (string) file_get_contents($this->directory.'/components/core/Textarea/Textarea.card.html');

        self::assertStringContainsString('<Textarea defaultValue="Example" />', $card);
        self::assertStringNotContainsString('</Textarea>', $card);
    }

    public function test_the_card_shows_each_dialog_and_tooltip_example(): void
    {
        $card = (string) file_get_contents($this->directory.'/components/core/Dialog/Dialog.card.html');
        $tooltip = (string) file_get_contents($this->directory.'/components/core/Tooltip/Tooltip.card.html');

        self::assertStringContainsString('<Dialog size="document" open>document</Dialog>', $card);
        self::assertStringContainsString('.row > dialog[open] { position: static; margin: 0; }', $card);
        self::assertStringContainsString('.row > .lp-tooltip { position: static; margin: 0; visibility: visible; opacity: 1; }', $tooltip);
        self::assertStringContainsString('.row > .lp-tooltip { position: static; margin: 0; visibility: visible; opacity: 1; }', $card);
    }

    public function test_the_card_shows_each_tabs_variant_with_its_tabs(): void
    {
        $card = (string) file_get_contents($this->directory.'/components/core/Tabs/Tabs.card.html');
        $tabs = '<a className="lp-tabs__tab" href="#" aria-current="page">Open</a><a className="lp-tabs__tab" href="#">Completed</a>';

        self::assertStringContainsString('<Tabs>'.$tabs, $card);
        self::assertStringContainsString('<Tabs variant="segmented">'.$tabs, $card);
        self::assertStringNotContainsString('<Tabs variant="segmented">segmented</Tabs>', $card);
    }

    public function test_the_tabs_prompt_shows_each_variant_with_its_tabs(): void
    {
        $prompt = (string) file_get_contents($this->directory.'/components/core/Tabs/Tabs.prompt.md');
        $tabs = '<a className="lp-tabs__tab" href="#" aria-current="page">Open</a><a className="lp-tabs__tab" href="#">Completed</a>';

        self::assertStringContainsString('<Tabs>'.$tabs, $prompt);
        self::assertStringContainsString('<Tabs variant="segmented">'.$tabs, $prompt);
        self::assertStringNotContainsString('<Tabs variant="segmented">Example</Tabs>', $prompt);
    }

    public function test_a_non_button_component_is_not_a_button(): void
    {
        $jsx = (string) file_get_contents($this->directory.'/components/core/Badge/Badge.jsx');
        $types = (string) file_get_contents($this->directory.'/components/core/Badge/Badge.d.ts');
        $prompt = (string) file_get_contents($this->directory.'/components/core/Badge/Badge.prompt.md');
        $card = (string) file_get_contents($this->directory.'/components/core/Badge/Badge.card.html');

        self::assertStringContainsString('<span {...rest} className={className}>', $jsx);
        self::assertStringNotContainsString('<button', $jsx);
        self::assertStringNotContainsString('disabled', $jsx);
        self::assertStringNotContainsString('href', $types);
        self::assertStringNotContainsString('disabled', $prompt);
        self::assertStringContainsString('<Badge status="draft">draft</Badge>', $card);
    }

    public function test_the_flash_empty_state_and_pagination_have_their_own_wrappers_and_cards(): void
    {
        $flash = (string) file_get_contents($this->directory.'/components/core/Flash/Flash.jsx');
        $empty = (string) file_get_contents($this->directory.'/components/core/EmptyState/EmptyState.jsx');
        $pagination = (string) file_get_contents($this->directory.'/components/core/Pagination/Pagination.jsx');

        self::assertStringContainsString('{dot && <span className="lp-flash__dot"></span>}', $flash);
        self::assertStringContainsString('<span className="lp-flash__message">{children}</span>', $flash);
        self::assertStringContainsString('<h2 className="lp-empty-state__title">{title}</h2>', $empty);
        self::assertStringContainsString('<nav {...rest} className={className} aria-label="Pagination">', $pagination);
        self::assertStringContainsString('<Flash severity="error" dismissLabel="Dismiss">Upload failed</Flash>', (string) file_get_contents($this->directory.'/components/core/Flash/Flash.card.html'));
        self::assertStringContainsString('linkLabel="Read the guide"', (string) file_get_contents($this->directory.'/components/core/EmptyState/EmptyState.card.html'));
        self::assertStringContainsString('<Pagination page={4} totalPages={7}', (string) file_get_contents($this->directory.'/components/core/Pagination/Pagination.card.html'));
    }

    public function test_a_component_with_a_dot_renders_its_child_elements(): void
    {
        $chip = (string) file_get_contents($this->directory.'/components/core/StatusChip/StatusChip.jsx');
        $badge = (string) file_get_contents($this->directory.'/components/core/Badge/Badge.jsx');

        self::assertStringContainsString('{dot && <span className="lp-status-chip__dot"></span>}', $chip);
        self::assertStringContainsString('dot = true', $chip);
        self::assertStringContainsString('dot?: boolean;', (string) file_get_contents($this->directory.'/components/core/StatusChip/StatusChip.d.ts'));
        self::assertStringNotContainsString('__dot', $badge);
        self::assertStringNotContainsString('dot', (string) file_get_contents($this->directory.'/components/core/Badge/Badge.d.ts'));
    }

    public function test_the_status_box_wrapper_draws_no_dot_at_its_root(): void
    {
        $jsx = (string) file_get_contents($this->directory.'/components/core/StatusBox/StatusBox.jsx');

        self::assertStringNotContainsString('{dot &&', $jsx);
        self::assertStringNotContainsString('dot?:', (string) file_get_contents($this->directory.'/components/core/StatusBox/StatusBox.d.ts'));
    }

    public function test_the_input_wrapper_is_a_void_element(): void
    {
        $jsx = (string) file_get_contents($this->directory.'/components/core/Input/Input.jsx');
        $prompt = (string) file_get_contents($this->directory.'/components/core/Input/Input.prompt.md');
        $card = (string) file_get_contents($this->directory.'/components/core/Input/Input.card.html');

        self::assertStringContainsString('return <input {...rest} className={className} />;', $jsx);
        self::assertStringNotContainsString('</input>', $jsx);
        self::assertStringContainsString('<Input placeholder="Example" />', $prompt);
        self::assertStringContainsString('<Input mono placeholder="mono" />', $card);
        self::assertStringNotContainsString('</Input>', $card);
        self::assertStringContainsString('<Select><option>Example</option></Select>', (string) file_get_contents($this->directory.'/components/core/Select/Select.card.html'));
        self::assertStringContainsString('children?: never;', (string) file_get_contents($this->directory.'/components/core/Input/Input.d.ts'));
        self::assertStringContainsString("const classes = ['lp-input', 'lp-select'];", (string) file_get_contents($this->directory.'/components/core/Select/Select.jsx'));
        self::assertStringContainsString("const classes = ['lp-input', 'lp-textarea'];", (string) file_get_contents($this->directory.'/components/core/Textarea/Textarea.jsx'));
        self::assertStringContainsString("const classes = ['lp-label'];", (string) file_get_contents($this->directory.'/components/core/Label/Label.jsx'));
        self::assertStringContainsString('<Element {...rest} className={className}>', (string) file_get_contents($this->directory.'/components/core/Label/Label.jsx'));
    }

    public function test_the_icon_wrapper_draws_an_svg_at_each_size(): void
    {
        $jsx = (string) file_get_contents($this->directory.'/components/core/Icon/Icon.jsx');
        $prompt = (string) file_get_contents($this->directory.'/components/core/Icon/Icon.prompt.md');
        $card = (string) file_get_contents($this->directory.'/components/core/Icon/Icon.card.html');

        self::assertStringContainsString('<svg {...rest} className={className} viewBox="0 0 24 24"', $jsx);
        self::assertStringContainsString('{children || <path d=', $jsx);
        self::assertStringNotContainsString('<span', $jsx);
        foreach (['xs', 'sm', 'md', 'lg', 'xl', '2xl'] as $size) {
            self::assertStringContainsString('<Icon variant="'.$size.'" />', $card);
            self::assertStringContainsString('<Icon variant="'.$size.'" />', $prompt);
        }
        self::assertStringNotContainsString('</Icon>', $card);
    }

    public function test_the_marketing_shell_export_draws_the_header_the_body_and_the_footer(): void
    {
        foreach (['MarketingShell.jsx', 'MarketingShell.d.ts', 'MarketingShell.prompt.md'] as $file) {
            self::assertContains('components/layout/'.explode('.', $file)[0].'/'.$file, $this->written);
        }

        $jsx = (string) file_get_contents($this->directory.'/components/layout/MarketingShell/MarketingShell.jsx');
        self::assertStringContainsString("const classes = ['lp-landing'];", $jsx);
        self::assertStringContainsString('<header className="lp-landing-nav">', $jsx);
        self::assertStringContainsString('<main>{children}</main>', $jsx);
        self::assertStringContainsString('<footer className="lp-landing-footer">', $jsx);

        $types = (string) file_get_contents($this->directory.'/components/layout/MarketingShell/MarketingShell.d.ts');
        self::assertStringContainsString('@startingPoint section="Layout"', $types);

        $card = (string) file_get_contents($this->directory.'/components/layout/MarketingShell/MarketingShell.card.html');
        self::assertStringContainsString('<MarketingShell>', $card);
    }

    public function test_the_marketing_shell_export_names_every_link_of_the_shared_footer(): void
    {
        $twig = static::getContainer()->get('twig');
        self::assertInstanceOf(Environment::class, $twig);
        $footer = new Crawler($twig->render('_marketing_footer.html.twig'));
        $jsx = (string) file_get_contents($this->directory.'/components/layout/MarketingShell/MarketingShell.jsx');

        $names = $footer->filter('footer a')->each(static fn (Crawler $link): string => $link->attr('aria-label') ?? trim($link->text()));
        self::assertCount(8, $names);
        foreach ($names as $name) {
            self::assertNotSame('', $name);
            self::assertStringContainsString($name, $jsx);
        }
        self::assertStringContainsString('lp-landing-nav__brand--sm', $jsx);
    }

    public function test_the_brand_mark_export_draws_the_brand_name_and_each_option(): void
    {
        foreach (['BrandMark.jsx', 'BrandMark.d.ts', 'BrandMark.prompt.md', 'BrandMark.card.html'] as $file) {
            self::assertContains('components/layout/'.explode('.', $file)[0].'/'.$file, $this->written);
        }

        $jsx = (string) file_get_contents($this->directory.'/components/layout/BrandMark/BrandMark.jsx');
        self::assertStringContainsString("export const BRANDMARK_SIZES = ['sm', 'md'];", $jsx);
        self::assertStringContainsString("'lp-brand--' + size", $jsx);
        self::assertStringContainsString("classes.push('lp-brand--mark-only')", $jsx);
        self::assertStringContainsString('<span className="lp-brand__mark" role="img" aria-label="loupe"></span>', $jsx);
        self::assertStringContainsString('<span className="lp-brand__mark" aria-hidden="true"></span>', $jsx);
        self::assertStringContainsString('<span className="lp-brand__word">loupe</span>', $jsx);
        self::assertStringContainsString('{...rest}', $jsx);

        $types = (string) file_get_contents($this->directory.'/components/layout/BrandMark/BrandMark.d.ts');
        self::assertStringContainsString("size?: 'sm' | 'md';", $types);
        self::assertStringContainsString('markOnly?: boolean;', $types);
        self::assertStringContainsString('@startingPoint section="Layout"', $types);

        $card = (string) file_get_contents($this->directory.'/components/layout/BrandMark/BrandMark.card.html');
        self::assertStringContainsString("dsFind('BrandMark')", $card);
        foreach (['<BrandMark size="md" />', '<BrandMark size="sm" />', '<BrandMark markOnly />'] as $example) {
            self::assertStringContainsString($example, $card);
        }
        self::assertStringNotContainsString('>Example</BrandMark>', $card);
    }

    public function test_the_drawer_export_draws_the_three_bands_of_the_dialog(): void
    {
        foreach (['Drawer.jsx', 'Drawer.d.ts', 'Drawer.prompt.md'] as $file) {
            self::assertContains('components/layout/'.explode('.', $file)[0].'/'.$file, $this->written);
        }

        $jsx = (string) file_get_contents($this->directory.'/components/layout/Drawer/Drawer.jsx');
        self::assertStringContainsString('<dialog {...rest} className={classes.join(\' \')}>', $jsx);
        foreach (['lp-drawer__header', 'lp-drawer__body', 'lp-drawer__footer'] as $class) {
            self::assertStringContainsString($class, $jsx);
        }

        $card = (string) file_get_contents($this->directory.'/components/layout/Drawer/Drawer.card.html');
        self::assertStringContainsString('<Drawer open', $card);
    }

    public function test_the_auth_shell_export_draws_the_page_frame_with_the_brand_name(): void
    {
        foreach (['AuthShell.jsx', 'AuthShell.d.ts', 'AuthShell.prompt.md'] as $file) {
            self::assertContains('components/layout/'.explode('.', $file)[0].'/'.$file, $this->written);
        }

        $jsx = (string) file_get_contents($this->directory.'/components/layout/AuthShell/AuthShell.jsx');
        self::assertStringContainsString('className="lp-auth"', $jsx);
        self::assertStringContainsString("'lp-auth__card--wide'", $jsx);
        self::assertStringContainsString('<span className="lp-brand__word">loupe</span>', $jsx);
        self::assertStringContainsString('footer = defaultFooter', $jsx);
        self::assertStringContainsString('<span>© Geoffrey Bachelet</span>', $jsx);
        foreach (['Docs', 'Privacy', 'Terms', 'AI Policy'] as $label) {
            self::assertStringContainsString('className="lp-auth__footer-link">'.$label.'</a>', $jsx);
        }

        $types = (string) file_get_contents($this->directory.'/components/layout/AuthShell/AuthShell.d.ts');
        self::assertStringContainsString('wide?: boolean;', $types);
    }

    public function test_the_export_writes_the_app_shell_wrapper_and_names_it_on_the_layout_card(): void
    {
        foreach (['AppShell.jsx', 'AppShell.d.ts', 'AppShell.prompt.md', 'AppShell.card.html'] as $file) {
            self::assertContains('components/layout/'.explode('.', $file)[0].'/'.$file, $this->written);
        }

        $card = (string) file_get_contents($this->directory.'/components/layout/AppShell/AppShell.card.html');
        self::assertStringContainsString("dsFind('AppShell')", $card);
        self::assertStringContainsString('<AppShell projectName="Acme"', $card);
        self::assertStringContainsString('counts={{ documents: 12, inbox: 2 }}', $card);

        $types = (string) file_get_contents($this->directory.'/components/layout/AppShell/AppShell.d.ts');
        self::assertStringContainsString('@startingPoint section="Layout"', $types);
        self::assertStringContainsString('className?: string;', $types);
        self::assertStringContainsString('[attribute: string]: unknown;', $types);
    }

    public function test_the_settings_layout_export_draws_the_header_the_navigation_and_the_content(): void
    {
        foreach (['SettingsLayout.jsx', 'SettingsLayout.d.ts', 'SettingsLayout.prompt.md'] as $file) {
            self::assertContains('components/layout/'.explode('.', $file)[0].'/'.$file, $this->written);
        }

        $jsx = (string) file_get_contents($this->directory.'/components/layout/SettingsLayout/SettingsLayout.jsx');
        self::assertStringContainsString('<nav className="lp-settings-nav" aria-label={navLabel}>', $jsx);
        self::assertStringContainsString("'lp-settings-nav__item' + (item.active ? ' lp-settings-nav__item--active' : '')", $jsx);
        self::assertStringContainsString("aria-current={item.active ? 'page' : undefined}", $jsx);
        self::assertStringContainsString('<div className="lp-settings-content">{children}</div>', $jsx);
        self::assertStringContainsString("classes.push('lp-settings--columns')", $jsx);

        $types = (string) file_get_contents($this->directory.'/components/layout/SettingsLayout/SettingsLayout.d.ts');
        self::assertStringContainsString('items?: SettingsLayoutItem[];', $types);
        self::assertStringContainsString('@startingPoint section="Layout"', $types);

        $card = (string) file_get_contents($this->directory.'/components/layout/SettingsLayout/SettingsLayout.card.html');
        self::assertStringContainsString('<SettingsLayout title="Project settings"', $card);
    }

    public function test_the_app_shell_wrapper_forwards_attributes_and_merges_a_caller_class(): void
    {
        $jsx = (string) file_get_contents($this->directory.'/components/layout/AppShell/AppShell.jsx');

        self::assertStringContainsString('className: extra', $jsx);
        self::assertStringContainsString('classes.push(extra)', $jsx);
        self::assertStringContainsString('<div {...rest} className={className}>', $jsx);
    }

    public function test_the_app_shell_switcher_lists_every_project_and_marks_the_current_one(): void
    {
        $base = (string) file_get_contents(\dirname(__DIR__, 4).'/templates/base.html.twig');
        $jsx = (string) file_get_contents($this->directory.'/components/layout/AppShell/AppShell.jsx');
        $types = (string) file_get_contents($this->directory.'/components/layout/AppShell/AppShell.d.ts');
        $card = (string) file_get_contents($this->directory.'/components/layout/AppShell/AppShell.card.html');

        self::assertStringContainsString('{% for switcherProject in switcherProjects %}', $base);
        self::assertStringContainsString(':current="currentProject"', $base);
        self::assertStringContainsString('{% if currentProject %}<twig:ux:icon name="lucide:check"', $base);

        self::assertStringContainsString('switcherProjects.map((project) => {', $jsx);
        self::assertStringContainsString('const current = project.name === projectName;', $jsx);
        self::assertStringContainsString("aria-current={current ? 'true' : undefined}", $jsx);
        self::assertStringContainsString('{current && <Icon name="check" className="lp-switcher__item-check" />}', $jsx);
        self::assertSame(1, substr_count($jsx, 'lp-switcher__item-check'));
        self::assertStringContainsString('projects?: Array<{ name: string; documentCount?: number }>;', $types);
        self::assertStringContainsString("projects={[{ name: 'Acme', documentCount: 12 }, { name: 'Globex', documentCount: 3 }]}", $card);
    }

    public function test_the_stylesheet_holds_the_real_shell_rules(): void
    {
        $css = (string) file_get_contents($this->directory.'/styles.css');

        foreach (['.lp-sidebar__link', '.lp-crumbs__link', '.lp-shell'] as $selector) {
            self::assertStringContainsString($selector, $css);
        }
    }

    public function test_no_layout_wrapper_draws_a_class_that_a_component_replaced(): void
    {
        $legacy = [];
        foreach (new Catalog()->entries() as $entry) {
            $legacy = [...$legacy, ...$entry->legacyClasses];
        }
        self::assertNotEmpty($legacy);

        $files = array_values(array_filter($this->written, static fn (string $file): bool => str_starts_with($file, 'components/layout/') && 1 === preg_match('/\.(jsx|md|html)$/', $file)));
        self::assertContains('components/layout/SettingsLayout/SettingsLayout.prompt.md', $files);
        self::assertContains('components/layout/AppShell/AppShell.card.html', $files);

        foreach ($files as $file) {
            $text = (string) file_get_contents($this->directory.'/'.$file);
            foreach ($legacy as $class) {
                self::assertDoesNotMatchRegularExpression('/'.preg_quote($class, '/').'(?![a-z_-])/', $text, sprintf('%s draws %s, which a component replaced.', $file, $class));
            }
        }
    }

    public function test_the_stylesheet_styles_every_app_shell_option(): void
    {
        $css = (string) file_get_contents($this->directory.'/styles.css');
        $types = (string) file_get_contents($this->directory.'/components/layout/AppShell/AppShell.d.ts');

        foreach (['shellWidth' => 'lp-shell--', 'mainSurface' => 'lp-main--'] as $prop => $prefix) {
            if (1 !== preg_match('/'.$prop."\\?: ((?:'[a-z]+'(?: \\| )?)+);/", $types, $match)) {
                self::fail(sprintf('AppShell.d.ts declares no %s union.', $prop));
            }
            preg_match_all("/'([a-z]+)'/", $match[1], $options);
            $default = 'shellWidth' === $prop ? 'default' : 'app';
            foreach (array_diff($options[1], [$default]) as $option) {
                self::assertStringContainsString('.'.$prefix.$option, $css, sprintf('The export has no rule for %s="%s".', $prop, $option));
            }
        }
    }

    public function test_the_app_shell_wrapper_draws_the_menu_and_avatar_classes_of_the_base_template(): void
    {
        $jsx = (string) file_get_contents($this->directory.'/components/layout/AppShell/AppShell.jsx');

        self::assertStringContainsString('<div className="lp-menu lp-menu--chrome lp-menu--stretch">', $jsx);
        self::assertStringContainsString('className="lp-menu__item"', $jsx);
        self::assertStringContainsString('lp-avatar lp-avatar--sm lp-avatar--square lp-avatar--danger', $jsx);
        self::assertStringContainsString('lp-avatar lp-avatar--xs lp-avatar--round lp-avatar--chrome', $jsx);
        self::assertStringContainsString('<Icon name="plus" className="lp-icon lp-icon--md" />', $jsx);
    }

    public function test_the_app_shell_wrapper_draws_every_shell_class_of_the_base_template(): void
    {
        $base = (string) file_get_contents(\dirname(__DIR__, 4).'/templates/base.html.twig');
        $jsx = (string) file_get_contents($this->directory.'/components/layout/AppShell/AppShell.jsx');

        preg_match_all('/lp-(?:app|shell|main|scrim|sidebar|switcher|topbar)[a-z_-]*/', $base, $matches);
        $classes = array_unique($matches[0]);

        self::assertNotEmpty($classes);
        foreach ($classes as $class) {
            self::assertStringContainsString($class, $jsx, sprintf('base.html.twig uses %s and the wrapper does not.', $class));
        }
    }

    public function test_the_app_shell_wrapper_draws_the_brand_mark_the_base_template_renders(): void
    {
        $base = (string) file_get_contents(\dirname(__DIR__, 4).'/templates/base.html.twig');
        self::assertMatchesRegularExpression('~class="lp-sidebar__brand">\s*<twig:Ds:BrandMark />~', $base);

        $twig = static::getContainer()->get('twig');
        self::assertInstanceOf(Environment::class, $twig);
        $mark = new Crawler($twig->createTemplate('<twig:Ds:BrandMark />')->render());
        $jsx = (string) file_get_contents($this->directory.'/components/layout/AppShell/AppShell.jsx');

        $classes = $mark->filter('[class]')->each(static fn (Crawler $node): string => (string) $node->attr('class'));
        self::assertNotEmpty($classes);
        foreach ($classes as $class) {
            self::assertStringContainsString('className="'.$class.'"', $jsx);
        }
        self::assertStringNotContainsString('lp-sidebar__brand-mark', $jsx);
    }

    public function test_the_app_shell_wrapper_has_a_link_for_every_nav_link_of_the_base_template(): void
    {
        $base = (string) file_get_contents(\dirname(__DIR__, 4).'/templates/base.html.twig');
        $jsx = (string) file_get_contents($this->directory.'/components/layout/AppShell/AppShell.jsx');

        preg_match_all('/nav\.link\.([a-z_]+)/', $base, $matches);
        $names = array_unique($matches[1]);

        self::assertNotEmpty($names);
        foreach ($names as $name) {
            self::assertStringContainsString("key: '".$name."'", $jsx, sprintf('base.html.twig has nav.link.%s and the wrapper has no link for it.', $name));
        }
    }

    public function test_the_app_shell_wrapper_hides_what_the_base_template_hides_with_no_project(): void
    {
        $base = (string) file_get_contents(\dirname(__DIR__, 4).'/templates/base.html.twig');
        $jsx = (string) file_get_contents($this->directory.'/components/layout/AppShell/AppShell.jsx');

        // Inside the with block, project is the sidebar project. After it, project is the route project again.
        if (1 !== preg_match('/\{%\s*with \{project: sidebar_project\(\)\}\s*%\}(.*?)\{%\s*endwith\s*%\}(.*)$/s', $base, $scopes)) {
            self::fail('base.html.twig has no sidebar_project() scope.');
        }
        [, $sidebar, $topbar] = $scopes;
        $sidebarGated = $this->ifBlocks($sidebar, 'project');
        $topbarGated = $this->ifBlocks($topbar, 'project');
        $ungated = str_replace([...$sidebarGated, ...$topbarGated], '', $sidebar.$topbar);
        $sidebarGuarded = $this->guardBlocks($jsx, 'hasProject');
        $topbarGuarded = $this->guardBlocks($jsx, 'onProjectPage');
        $unguarded = str_replace([...$sidebarGuarded, ...$topbarGuarded], '', $jsx);

        $classPattern = '/lp-(?:sidebar|switcher|topbar)[a-z_-]*/';
        preg_match_all($classPattern, $ungated, $ungatedClasses);
        // NavLink draws the per-link classes, and the nav-link check below covers which links it draws.
        if (1 !== preg_match('/function NavLink\(.*?\n}\n/s', $jsx, $navLink)) {
            self::fail('The wrapper has no NavLink function.');
        }
        preg_match_all($classPattern, $navLink[0], $navLinkClasses);
        $projectOnly = static function (array $gated) use ($classPattern, $ungatedClasses, $navLinkClasses): array {
            preg_match_all($classPattern, implode("\n", $gated), $gatedClasses);

            return array_diff(array_unique($gatedClasses[0]), $ungatedClasses[0], $navLinkClasses[0]);
        };
        $sidebarOnly = $projectOnly($sidebarGated);
        $topbarOnly = $projectOnly($topbarGated);

        self::assertContains('lp-sidebar__switcher', $sidebarOnly);
        self::assertSame(['lp-topbar__new-work'], array_values($topbarOnly));
        foreach (['hasProject' => [$sidebarOnly, $sidebarGuarded], 'onProjectPage' => [$topbarOnly, $topbarGuarded]] as $guard => [$classes, $guarded]) {
            foreach ($classes as $class) {
                self::assertStringContainsString($class, implode("\n", $guarded), sprintf('base.html.twig shows %s only with a project, and the wrapper does not guard it with %s.', $class, $guard));
                self::assertDoesNotMatchRegularExpression('/'.preg_quote($class, '/').'(?![a-z_-])/', $unguarded, sprintf('The wrapper draws %s outside a project guard.', $class));
            }
        }

        preg_match_all('/nav\.link\.([a-z_]+)/', implode("\n", $sidebarGated), $gatedLinks);
        self::assertStringContainsString('WORKSPACE_LINKS.filter(shown).map', implode("\n", $sidebarGuarded));
        self::assertStringContainsString('AGENT_LINKS.filter(shown).map', implode("\n", $sidebarGuarded));
        if (1 !== preg_match('/const WORKSPACE_LINKS = \[(.*?)\];/s', $jsx, $workspace)) {
            self::fail('The wrapper has no WORKSPACE_LINKS list.');
        }
        if (1 !== preg_match('/const AGENT_LINKS = \[(.*?)\];/s', $jsx, $agents)) {
            self::fail('The wrapper has no AGENT_LINKS list.');
        }
        preg_match_all("/key: '([a-z_]+)'/", $workspace[1].$agents[1], $listed);
        preg_match_all("/key: '([a-z_]+)'[^}]*project: true/", $jsx, $flagged);
        $jsxProjectOnly = array_merge($listed[1], $flagged[1]);
        sort($jsxProjectOnly);
        $baseProjectOnly = array_values(array_unique($gatedLinks[1]));
        sort($baseProjectOnly);

        self::assertSame($baseProjectOnly, $jsxProjectOnly);
        self::assertStringContainsString('(hasProject || !link.project)', $jsx);
        self::assertStringContainsString('const hasProject = projectName !== null;', $jsx);
        self::assertStringContainsString("projectName = 'Project', projectPage = true,", $jsx);
        self::assertStringContainsString('const onProjectPage = hasProject && projectPage;', $jsx);
    }

    public function test_the_app_shell_wrapper_gates_each_link_the_base_template_gates_by_flag_or_role(): void
    {
        $base = (string) file_get_contents(\dirname(__DIR__, 4).'/templates/base.html.twig');
        $jsx = (string) file_get_contents($this->directory.'/components/layout/AppShell/AppShell.jsx');

        // The All inboxes link is the one nav link whose label is not a nav.link.* string.
        $linkKey = static fn (string $block): ?string => 1 === preg_match('/\'(?:nav\.link\.([a-z_]+)|(inbox\.account\.nav))\'\|trans/', $block, $match)
            ? ('' !== ($match[2] ?? '') ? 'account_inboxes' : $match[1])
            : null;
        $expected = [];
        foreach ($this->ifBlocks($base, 'is_feature_enabled\(\'[a-z]+\.enabled\'\)') as $block) {
            $key = $linkKey($block);
            if (null !== $key && 1 === preg_match('/is_feature_enabled\(\'([a-z]+)\.enabled\'\)/', $block, $flag)) {
                $expected[$key] = "feature: '".$flag[1]."'";
            }
        }
        foreach ($this->ifBlocks($base, 'is_granted\(\'ROLE_ADMIN\'\)') as $block) {
            $key = $linkKey($block);
            if (null !== $key) {
                $expected[$key] = 'admin: true';
            }
        }

        self::assertArrayHasKey('billing', $expected);
        self::assertArrayHasKey('account_inboxes', $expected);
        self::assertArrayHasKey('admin', $expected);
        preg_match_all("/\\{ key: '([a-z_]+)'[^}]*\\}/", $jsx, $links, \PREG_SET_ORDER);
        self::assertNotEmpty($links);
        foreach ($links as [$definition, $key]) {
            if (isset($expected[$key])) {
                self::assertStringContainsString($expected[$key], $definition, sprintf('base.html.twig gates nav.link.%s, and the wrapper does not.', $key));
            } else {
                self::assertDoesNotMatchRegularExpression('/feature:|admin:/', $definition, sprintf('The wrapper gates %s, and base.html.twig does not.', $key));
            }
        }
        self::assertStringContainsString('(!link.feature || features[link.feature] === true) && (!link.admin || admin)', $jsx);
        self::assertSame(3, substr_count($jsx, '.filter(shown).map('));
    }

    public function test_the_app_shell_wrapper_always_shows_the_counts_the_base_template_always_shows(): void
    {
        $base = (string) file_get_contents(\dirname(__DIR__, 4).'/templates/base.html.twig');
        $jsx = (string) file_get_contents($this->directory.'/components/layout/AppShell/AppShell.jsx');

        preg_match_all('/<twig:Ds:NavItem\b.*?\'nav\.link\.([a-z_]+)\'\|trans.*?(?:\/>|<\/twig:Ds:NavItem>)/s', $base, $anchors, \PREG_SET_ORDER);
        $expected = [];
        foreach ($anchors as [$anchor, $key]) {
            if (str_contains($anchor, ':count=') || str_contains($anchor, '<span class="lp-sidebar__pill"')) {
                $expected[] = $key;
            }
        }
        sort($expected);
        self::assertSame(['documents'], $expected);

        preg_match_all("/\\{ key: '([a-z_]+)'[^}]*alwaysCount: true[^}]*\\}/", $jsx, $links);
        $actual = $links[1];
        sort($actual);
        self::assertSame($expected, $actual);
        self::assertStringContainsString('<span className="lp-sidebar__pill">{count ?? 0}</span>', $jsx);
        self::assertStringContainsString(') : count > 0 && (', $jsx);
    }

    /** @return list<string> the body of each `{% if <condition> %}` block, nested blocks included */
    private function ifBlocks(string $twig, string $condition): array
    {
        $blocks = [];
        preg_match_all('/\{%\s*if '.$condition.'\s*%\}/', $twig, $starts, \PREG_OFFSET_CAPTURE);
        foreach ($starts[0] as [$tag, $offset]) {
            preg_match_all('/\{%\s*(if|endif)\b[^%]*%\}/', $twig, $tags, \PREG_OFFSET_CAPTURE | \PREG_SET_ORDER, $offset + \strlen($tag));
            $depth = 1;
            foreach ($tags as $match) {
                $depth += 'if' === $match[1][0] ? 1 : -1;
                if (0 === $depth) {
                    $blocks[] = substr($twig, $offset, $match[0][1] - $offset);
                    break;
                }
            }
        }

        return $blocks;
    }

    /** @return list<string> the body of each `{<guard> && (…)}` block */
    private function guardBlocks(string $jsx, string $guard): array
    {
        $blocks = [];
        $offset = 0;
        while (false !== ($start = strpos($jsx, '{'.$guard.' && (', $offset))) {
            $depth = 0;
            for ($i = $start + \strlen('{'.$guard.' && '); $i < \strlen($jsx); ++$i) {
                $depth += match ($jsx[$i]) {
                    '(' => 1, ')' => -1, default => 0,
                };
                if (0 === $depth) {
                    break;
                }
            }
            $blocks[] = substr($jsx, $start, $i - $start + 1);
            $offset = $i;
        }

        return $blocks;
    }

    public function test_the_page_header_wrapper_draws_the_copy_block_and_the_actions(): void
    {
        $jsx = (string) file_get_contents($this->directory.'/components/layout/PageHeader/PageHeader.jsx');
        $types = (string) file_get_contents($this->directory.'/components/layout/PageHeader/PageHeader.d.ts');
        $prompt = (string) file_get_contents($this->directory.'/components/layout/PageHeader/PageHeader.prompt.md');
        $card = (string) file_get_contents($this->directory.'/components/layout/PageHeader/PageHeader.card.html');

        self::assertStringContainsString('export function PageHeader({ title, description, variant, titleClass, actions, className: extra, ...rest })', $jsx);
        self::assertStringContainsString('<div className="lp-page-header__copy">', $jsx);
        self::assertStringContainsString('{actions}', $jsx);
        self::assertStringContainsString('@startingPoint section="Layout"', $types);
        self::assertStringContainsString("| 'workshop'", $types);
        self::assertStringContainsString('<PageHeader title=', $prompt);
        self::assertStringContainsString("dsFind('PageHeader')", $card);
        self::assertStringContainsString('<PageHeader variant="workshop"', $card);
        self::assertStringNotContainsString('<Button', $card);
    }

    public function test_the_workspace_wrapper_draws_the_frame_with_a_named_width(): void
    {
        $jsx = (string) file_get_contents($this->directory.'/components/layout/Workspace/Workspace.jsx');
        $types = (string) file_get_contents($this->directory.'/components/layout/Workspace/Workspace.d.ts');
        $prompt = (string) file_get_contents($this->directory.'/components/layout/Workspace/Workspace.prompt.md');
        $card = (string) file_get_contents($this->directory.'/components/layout/Workspace/Workspace.card.html');

        self::assertStringContainsString("classes.push('lp-workspace--' + width);", $jsx);
        self::assertStringContainsString("| 'narrow'", $types);
        self::assertStringContainsString("| 'full'", $types);
        self::assertStringContainsString('<Workspace width="narrow">', $prompt);
        self::assertStringContainsString('<Workspace width="narrow">', $card);
    }

    public function test_the_split_view_wrapper_draws_the_region_the_view_and_two_panes(): void
    {
        $jsx = (string) file_get_contents($this->directory.'/components/layout/SplitView/SplitView.jsx');
        $types = (string) file_get_contents($this->directory.'/components/layout/SplitView/SplitView.d.ts');
        $prompt = (string) file_get_contents($this->directory.'/components/layout/SplitView/SplitView.prompt.md');
        $card = (string) file_get_contents($this->directory.'/components/layout/SplitView/SplitView.card.html');

        self::assertStringContainsString('export function SplitView({ header, list, detail, className: extra, ...rest })', $jsx);
        self::assertStringContainsString('<div className="lp-split-region">', $jsx);
        self::assertStringContainsString('<div className="lp-split-view">', $jsx);
        self::assertStringContainsString('<div className="lp-split-pane">{list}</div>', $jsx);
        self::assertStringContainsString('<div className="lp-split-pane">{detail}</div>', $jsx);
        self::assertStringContainsString('@startingPoint section="Layout"', $types);
        self::assertStringContainsString('<SplitView', $prompt);
        self::assertStringContainsString('<SplitView style={{ height: 240 }}', $card);
        self::assertStringNotContainsString('<Button', $card);
    }

    public function test_the_list_row_wrapper_draws_the_icon_the_title_and_the_meta_line(): void
    {
        $jsx = (string) file_get_contents($this->directory.'/components/layout/ListRow/ListRow.jsx');
        $types = (string) file_get_contents($this->directory.'/components/layout/ListRow/ListRow.d.ts');
        $prompt = (string) file_get_contents($this->directory.'/components/layout/ListRow/ListRow.prompt.md');
        $card = (string) file_get_contents($this->directory.'/components/layout/ListRow/ListRow.card.html');

        self::assertStringContainsString('export function ListRow({ title, icon, meta, current, href, className: extra, ...rest })', $jsx);
        self::assertStringContainsString("aria-current={current ? 'true' : undefined}", $jsx);
        self::assertStringContainsString('<span className="lp-list-row__title">{title}</span>', $jsx);
        self::assertStringContainsString('@startingPoint section="Layout"', $types);
        self::assertStringContainsString('lp-list-row__flag', $prompt);
        self::assertStringContainsString('<ListRow href="#" title="Which format?"', $card);
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

            foreach (['SamplePart.jsx', 'SamplePart.d.ts', 'SamplePart.prompt.md', 'SamplePart.card.html'] as $file) {
                self::assertContains('components/layout/SamplePart/'.$file, $written);
            }
            self::assertStringContainsString('<header className="lp-sample-part">', (string) file_get_contents($directory.'/components/layout/SamplePart/SamplePart.jsx'));
            self::assertStringContainsString('@startingPoint section="Layout"', (string) file_get_contents($directory.'/components/layout/SamplePart/SamplePart.d.ts'));

            $card = (string) file_get_contents($directory.'/components/layout/SamplePart/SamplePart.card.html');
            self::assertMatchesRegularExpression("~dsFind\([^)]*'SamplePart'\)~", $card);
            self::assertStringContainsString('<SamplePart title="Example" />', $card);
            self::assertStringContainsString('<script src="../../../ds-mount.js">', $card);
            self::assertStringNotContainsString('<Button', $card);
            self::assertFileExists($directory.'/components/core/Button/Button.jsx');
        } finally {
            new Filesystem()->remove($directory);
        }
    }
}
