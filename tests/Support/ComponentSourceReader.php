<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Module\DesignSystem\Catalog;
use App\Module\DesignSystem\ComponentEntry;
use App\Module\DesignSystem\Service\ComponentSource;

/** Reads the exported source of a catalog component. The test class boots the kernel first. */
trait ComponentSourceReader
{
    private static function componentSource(): ComponentSource
    {
        if (!static::$booted) {
            static::bootKernel();
        }

        $source = static::getContainer()->get(ComponentSource::class);
        self::assertInstanceOf(ComponentSource::class, $source);

        return $source;
    }

    private static function catalogEntry(string $name): ComponentEntry
    {
        foreach (new Catalog()->entries() as $entry) {
            if ($entry->name === $name) {
                return $entry;
            }
        }

        self::fail($name.' is not in the catalog.');
    }

    private static function componentJsx(string $name): string
    {
        return self::componentSource()->jsx(self::catalogEntry($name));
    }

    private static function componentTypes(string $name): string
    {
        return self::componentSource()->types(self::catalogEntry($name));
    }
}
