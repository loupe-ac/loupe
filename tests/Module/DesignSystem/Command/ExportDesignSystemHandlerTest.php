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
    }

    public function test_the_card_shows_each_tabs_variant_with_its_tabs(): void
    {
        $card = (string) file_get_contents($this->directory.'/components/core/core.card.html');
        $tabs = '<a className="lp-tabs__tab" href="#" aria-current="page">Open</a><a className="lp-tabs__tab" href="#">Completed</a>';

        self::assertStringContainsString('<Tabs>'.$tabs, $card);
        self::assertStringContainsString('<Tabs variant="segmented">'.$tabs, $card);
        self::assertStringNotContainsString('<Tabs variant="segmented">segmented</Tabs>', $card);
    }

    public function test_the_tabs_prompt_shows_each_variant_with_its_tabs(): void
    {
        $prompt = (string) file_get_contents($this->directory.'/components/core/Tabs.prompt.md');
        $tabs = '<a className="lp-tabs__tab" href="#" aria-current="page">Open</a><a className="lp-tabs__tab" href="#">Completed</a>';

        self::assertStringContainsString('<Tabs>'.$tabs, $prompt);
        self::assertStringContainsString('<Tabs variant="segmented">'.$tabs, $prompt);
        self::assertStringNotContainsString('<Tabs variant="segmented">Example</Tabs>', $prompt);
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

    public function test_the_export_writes_the_app_shell_wrapper_and_names_it_on_the_layout_card(): void
    {
        foreach (['AppShell.jsx', 'AppShell.d.ts', 'AppShell.prompt.md', 'layout.card.html'] as $file) {
            self::assertContains('components/layout/'.$file, $this->written);
        }

        $card = (string) file_get_contents($this->directory.'/components/layout/layout.card.html');
        self::assertStringContainsString("dsFind('AppShell')", $card);
        self::assertStringContainsString('<AppShell projectName="Acme"', $card);
        self::assertStringContainsString('counts={{ documents: 12, inbox: 2 }}', $card);

        $types = (string) file_get_contents($this->directory.'/components/layout/AppShell.d.ts');
        self::assertStringContainsString('@startingPoint section="Layout"', $types);
        self::assertStringContainsString('className?: string;', $types);
        self::assertStringContainsString('[attribute: string]: unknown;', $types);
    }

    public function test_the_app_shell_wrapper_forwards_attributes_and_merges_a_caller_class(): void
    {
        $jsx = (string) file_get_contents($this->directory.'/components/layout/AppShell.jsx');

        self::assertStringContainsString('className: extra', $jsx);
        self::assertStringContainsString('classes.push(extra)', $jsx);
        self::assertStringContainsString('<div {...rest} className={className}>', $jsx);
    }

    public function test_the_stylesheet_holds_the_real_shell_rules(): void
    {
        $css = (string) file_get_contents($this->directory.'/styles.css');

        foreach (['.lp-sidebar__link', '.lp-topbar__crumb', '.lp-shell'] as $selector) {
            self::assertStringContainsString($selector, $css);
        }
    }

    public function test_the_app_shell_wrapper_draws_every_shell_class_of_the_base_template(): void
    {
        $base = (string) file_get_contents(\dirname(__DIR__, 4).'/templates/base.html.twig');
        $jsx = (string) file_get_contents($this->directory.'/components/layout/AppShell.jsx');

        preg_match_all('/lp-(?:app|shell|main|scrim|sidebar|switcher|topbar)[a-z_-]*/', $base, $matches);
        $classes = array_unique($matches[0]);

        self::assertNotEmpty($classes);
        foreach ($classes as $class) {
            self::assertStringContainsString($class, $jsx, sprintf('base.html.twig uses %s and the wrapper does not.', $class));
        }
    }

    public function test_the_app_shell_wrapper_has_a_link_for_every_nav_link_of_the_base_template(): void
    {
        $base = (string) file_get_contents(\dirname(__DIR__, 4).'/templates/base.html.twig');
        $jsx = (string) file_get_contents($this->directory.'/components/layout/AppShell.jsx');

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
        $jsx = (string) file_get_contents($this->directory.'/components/layout/AppShell.jsx');

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

        self::assertContains('lp-switcher', $sidebarOnly);
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
        $jsx = (string) file_get_contents($this->directory.'/components/layout/AppShell.jsx');

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
            self::assertStringContainsString("dsFind('AppShell', 'SamplePart')", $card);
            self::assertStringContainsString('<SamplePart title="Example" />', $card);
            self::assertStringContainsString('<script src="../../ds-mount.js">', $card);
            self::assertStringNotContainsString('<Button', $card);
            self::assertFileExists($directory.'/components/core/Button.jsx');
        } finally {
            new Filesystem()->remove($directory);
        }
    }
}
