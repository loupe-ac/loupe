<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Template;

use App\Module\Workflow\Template\TemplateVersion;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TemplateVersionTest extends TestCase
{
    public function test_the_version_is_twelve_hex_characters(): void
    {
        self::assertMatchesRegularExpression('/^[0-9a-f]{12}$/', TemplateVersion::of(['key' => 'a', 'rules' => [['id' => 'x']]]));
    }

    public function test_the_order_of_map_keys_does_not_change_the_version(): void
    {
        self::assertSame(
            TemplateVersion::of(['key' => 'a', 'types' => [['key' => 'x', 'label' => 'y']]]),
            TemplateVersion::of(['types' => [['label' => 'y', 'key' => 'x']], 'key' => 'a']),
        );
    }

    public function test_the_order_of_a_list_changes_the_version(): void
    {
        self::assertNotSame(
            TemplateVersion::of(['key' => 'a', 'backoffMinutes' => [1, 2]]),
            TemplateVersion::of(['key' => 'a', 'backoffMinutes' => [2, 1]]),
        );
    }

    public function test_a_change_of_content_changes_the_version(): void
    {
        self::assertNotSame(TemplateVersion::of(['key' => 'a', 'defaultType' => 'x']), TemplateVersion::of(['key' => 'a', 'defaultType' => 'y']));
    }

    #[DataProvider('ignoredKeys')]
    public function test_a_key_that_the_project_owns_does_not_change_the_version(string $key, mixed $value): void
    {
        $source = ['key' => 'a', 'defaultType' => 'x'];

        self::assertSame(TemplateVersion::of($source), TemplateVersion::of([...$source, $key => $value]));
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function ignoredKeys(): iterable
    {
        yield 'version' => ['version', 41];
        yield 'epicBranch' => ['epicBranch', 'feature/{number}'];
        yield 'approvalCoversConflictResolution' => ['approvalCoversConflictResolution', false];
    }
}
