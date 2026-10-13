<?php

declare(strict_types=1);

namespace App\Tests\Module\DesignSystem;

use App\Module\DesignSystem\ComponentEntry;
use Gamache\Check\DesignSystemComponent;
use PHPUnit\Framework\TestCase;

final class CatalogLegacyClassesTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once \dirname(__DIR__, 3).'/gamache.php';
    }

    public function test_an_entry_replaces_no_old_class_by_default(): void
    {
        $entry = new ComponentEntry(
            name: 'Panel',
            rootClass: 'lp-panel',
            template: 'components/Ds/Panel.html.twig',
            variants: [],
            states: [],
            enforced: true,
        );

        self::assertSame([], $entry->legacyClasses);
        self::assertFalse($entry->legacyEnforced);
    }

    public function test_the_check_gets_the_root_class_of_an_entry(): void
    {
        $components = designSystemComponents([
            new ComponentEntry(
                name: 'Panel',
                rootClass: 'lp-panel',
                template: 'components/Ds/Panel.html.twig',
                variants: [],
                states: [],
                enforced: true,
            ),
        ]);

        self::assertEquals(
            [new DesignSystemComponent('Panel', 'lp-panel', 'templates/components/Ds/Panel.html.twig', true)],
            $components,
        );
    }

    public function test_the_check_gets_each_old_class_with_its_own_enforcement(): void
    {
        $components = designSystemComponents([
            new ComponentEntry(
                name: 'Panel',
                rootClass: 'lp-panel',
                template: 'components/Ds/Panel.html.twig',
                variants: [],
                states: [],
                enforced: true,
                legacyClasses: ['lp-settings-panel-card', 'lp-card'],
                legacyEnforced: false,
            ),
        ]);

        self::assertEquals(
            [
                new DesignSystemComponent('Panel', 'lp-panel', 'templates/components/Ds/Panel.html.twig', true),
                new DesignSystemComponent('Panel', 'lp-settings-panel-card', 'templates/components/Ds/Panel.html.twig', false),
                new DesignSystemComponent('Panel', 'lp-card', 'templates/components/Ds/Panel.html.twig', false),
            ],
            $components,
        );
    }
}
