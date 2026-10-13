<?php

declare(strict_types=1);

namespace App\Tests\Module\DesignSystem;

use App\Module\DesignSystem\Catalog;
use App\Module\DesignSystem\ComponentEntry;
use PHPUnit\Framework\TestCase;

final class CatalogLegacyClassesTest extends TestCase
{
    public function test_an_entry_replaces_no_old_class_by_default(): void
    {
        foreach (new Catalog()->entries() as $entry) {
            self::assertSame([], $entry->legacyClasses, $entry->name);
            self::assertFalse($entry->legacyEnforced, $entry->name);
        }
    }

    public function test_an_entry_carries_its_old_classes(): void
    {
        $entry = new ComponentEntry(
            name: 'Panel',
            rootClass: 'lp-panel',
            template: 'components/Ds/Panel.html.twig',
            variants: [],
            states: [],
            enforced: true,
            legacyClasses: ['lp-settings-panel-card'],
            legacyEnforced: true,
        );

        self::assertSame(['lp-settings-panel-card'], $entry->legacyClasses);
        self::assertTrue($entry->legacyEnforced);
    }
}
