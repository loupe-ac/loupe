<?php

declare(strict_types=1);

namespace App\Tests\Module\DesignSystem;

use App\Module\DesignSystem\Command\ExportDesignSystemCommand;
use App\Module\DesignSystem\Command\ExportDesignSystemHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Twig\Environment;

final class StatusComponentsParityTest extends KernelTestCase
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

    /** @return iterable<string, array{string, string}> name, the Twig that draws every inner part */
    public static function components(): iterable
    {
        yield 'StatusChip' => ['StatusChip', '<twig:Ds:StatusChip modifier="ok" reason="Why">A</twig:Ds:StatusChip>'];
        yield 'StateMark' => ['StateMark', '<twig:Ds:StateMark kind="stuck" label="Stuck">Why</twig:Ds:StateMark>'];
        yield 'StatusBox' => ['StatusBox', '<twig:Ds:StatusBox kind="stuck" title="T" chip="C" lead="L" :fields="[{label: \'A\', value: \'B\', wide: true}, {label: \'C\', value: \'D\'}]" :others="[{kind: \'waiting\', label: \'W\', lead: \'L\', since: \'1 h\'}]" othersTitle="O" />'];
    }

    #[DataProvider('components')]
    public function test_the_wrapper_draws_the_classes_of_its_twig_component(string $name, string $twig): void
    {
        $actual = $this->baseClasses($this->render($twig));
        $expected = $this->baseClasses($this->wrapper($name));
        sort($actual);
        sort($expected);

        self::assertSame($actual, $expected);
    }

    public function test_the_status_chip_wrapper_takes_the_props_of_the_twig_component(): void
    {
        $jsx = $this->wrapper('StatusChip');

        self::assertStringContainsString("{ modifier, label, dot = true, as: Element = 'span', reason, id,", $jsx);
        self::assertStringContainsString("'lp-status-chip--' + modifier", $jsx);
        self::assertStringContainsString("'lp-status-chip--reason'", $jsx);
        self::assertStringContainsString('<span className="lp-status-chip__dot"></span>', $jsx);
        self::assertStringNotContainsString('variant', $jsx);
        self::assertStringContainsString('<span class="lp-status-chip lp-status-chip--ok">', preg_replace('/\s+/', ' ', $this->render('<twig:Ds:StatusChip modifier="ok" :dot="false">A</twig:Ds:StatusChip>')) ?? '');
    }

    public function test_the_state_mark_wrapper_opens_its_tooltip_without_stimulus(): void
    {
        $jsx = $this->wrapper('StateMark');

        self::assertStringNotContainsString('data-controller', $jsx);
        self::assertStringNotContainsString('data-action', $jsx);
        self::assertStringContainsString('React.useEffect(() => attachTooltip(rootRef.current, tooltipRef.current), [])', $jsx);
        self::assertStringContainsString('{ kind, label, id,', $jsx);
        self::assertStringContainsString("'lp-state-mark--' + kind", $jsx);
        foreach (['stuck', 'needs-you', 'working', 'waiting', 'chip'] as $kind) {
            self::assertMatchesRegularExpression('/(^|\s)\'?'.preg_quote($kind, '/').'\'?: /', $jsx, $kind);
        }
    }

    public function test_the_status_box_wrapper_draws_a_dot_only_inside_the_list_of_other_states(): void
    {
        $jsx = $this->wrapper('StatusBox');
        $html = $this->render('<twig:Ds:StatusBox kind="working" title="T" chip="C" lead="L" />');

        self::assertStringNotContainsString('lp-status-box__dot', $html);
        self::assertStringContainsString('{ kind, title, chip, lead, fields = [], others = [], othersTitle,', $jsx);
        self::assertSame(1, substr_count($jsx, 'lp-status-box__dot'));
        self::assertStringContainsString("'lp-status-box--' + kind", $jsx);
        self::assertStringContainsString('{field.label}', $jsx);
        self::assertStringContainsString('{other.lead}', $jsx);
    }

    public function test_each_wrapper_has_its_own_folder_with_a_card_and_its_types(): void
    {
        foreach (['StatusChip', 'StateMark', 'StatusBox'] as $name) {
            $base = self::$directory.'/components/core/'.$name.'/'.$name;

            foreach (['.jsx', '.d.ts', '.prompt.md', '.card.html'] as $extension) {
                self::assertFileExists($base.$extension);
            }
            self::assertStringContainsString('<'.$name.' ', (string) file_get_contents($base.'.card.html'));
        }
    }

    private function wrapper(string $name): string
    {
        return (string) file_get_contents(self::$directory.'/components/core/'.$name.'/'.$name.'.jsx');
    }

    private function render(string $template): string
    {
        self::bootKernel();

        return static::getContainer()->get(Environment::class)->createTemplate($template)->render();
    }

    /**
     * Drops the modifier part, so a rendered `lp-status-chip--ok` and a wrapper
     * `'lp-status-chip--' + modifier` both read as `lp-status-chip`, and keeps the fixed classes.
     *
     * @return list<string>
     */
    private function baseClasses(string $source): array
    {
        preg_match_all('/lp-[a-z_-]+/', $source, $matches);

        return array_values(array_unique(array_map(
            static fn (string $class): string => explode('--', $class)[0],
            $matches[0],
        )));
    }
}
