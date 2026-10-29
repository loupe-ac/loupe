<?php

declare(strict_types=1);

namespace App\Tests\Module\Forge\Settings;

use App\Module\Forge\Settings\RepositoryRules;
use App\Module\Forge\Settings\RepositoryRuleset;
use App\Module\Forge\Settings\RepositorySettings;
use PHPUnit\Framework\TestCase;

final class RepositorySettingsTest extends TestCase
{
    public function test_it_survives_a_round_trip_through_json(): void
    {
        $settings = new RepositorySettings(
            'main',
            ['squash', 'rebase'],
            123_456,
            new RepositoryRules(true, false, ['squash'], ['ci', 'Loupe / Approval'], [123_456, null], true, false),
            [new RepositoryRuleset(7, 'Protect main', 'Organization', 'loupe-ac', ['pull_request', 'deletion'])],
            ['ci', 'lint'],
            true,
        );

        $decoded = json_decode(json_encode($settings, \JSON_THROW_ON_ERROR), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        self::assertEquals($settings, RepositorySettings::fromArray($decoded));
        self::assertTrue($settings->rulesets[0]->isOrganization());
    }

    public function test_it_lists_the_usable_merge_methods_in_a_fixed_order(): void
    {
        $settings = new RepositorySettings('main', ['rebase', 'squash', 'merge'], null, new RepositoryRules(true, false, ['rebase', 'merge'], [], [], false, false), [], [], false);

        self::assertSame(['merge', 'rebase'], $settings->usableMergeMethods());
    }

    public function test_it_refuses_a_stored_shape_it_does_not_know(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        RepositorySettings::fromArray(['defaultBranch' => 'main']);
    }
}
