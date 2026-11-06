<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Template;

use App\Module\Workflow\Template\ShippedTemplates;
use App\Module\Workflow\Template\TemplateParser;
use App\Module\Workflow\Template\TemplateVersion;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ShippedTemplateVersionTest extends KernelTestCase
{
    /** The old migrations read the `version:` line, so it stays at the number it had when the hash replaced it. */
    public function test_the_frozen_version_lines_stay_as_they_are(): void
    {
        $shipped = self::getContainer()->get(ShippedTemplates::class);
        self::assertInstanceOf(ShippedTemplates::class, $shipped);

        self::assertSame(44, $shipped->source('lifecycle')['version']);
        self::assertSame(15, $shipped->source('simple')['version']);
    }

    public function test_a_stored_copy_with_project_values_has_the_version_of_its_shipped_source(): void
    {
        $shipped = self::getContainer()->get(ShippedTemplates::class);
        $parser = self::getContainer()->get(TemplateParser::class);
        self::assertInstanceOf(ShippedTemplates::class, $shipped);
        self::assertInstanceOf(TemplateParser::class, $parser);

        foreach ($shipped->keys() as $key) {
            $source = $shipped->source($key);
            $stored = [...$source, 'epicBranch' => 'feature/epic-{number}', 'approvalCoversConflictResolution' => false];
            unset($stored['version']);

            self::assertSame(TemplateVersion::of($source), $parser->parseStored($stored)->version, $key);
            self::assertSame(TemplateVersion::of($source), $parser->parse($source)->version, $key);
        }
    }
}
