<?php

declare(strict_types=1);

namespace App\Tests\Module\DesignSystem;

use App\Module\DesignSystem\Service\ComponentBundler;
use App\Tests\Support\ComponentSourceReader;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ComponentBundlerTest extends KernelTestCase
{
    use ComponentSourceReader;

    public function test_it_wraps_a_component_in_its_own_scope_and_assigns_it_to_the_namespace(): void
    {
        $bundler = new ComponentBundler(self::componentSource());

        $bundle = $bundler->bundle([self::catalogEntry('Tag')]);

        self::assertStringContainsString('try {', $bundle);
        self::assertStringContainsString('Object.assign(__ds_scope, {', $bundle);
        self::assertStringContainsString('__ds_ns.Tag = __ds_scope.Tag;', $bundle);
        self::assertStringNotContainsString('</', $bundle);
    }
}
