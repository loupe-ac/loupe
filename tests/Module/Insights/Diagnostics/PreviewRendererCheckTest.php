<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Diagnostics;

use App\Module\Insights\Diagnostics\PreviewRendererCheck;
use App\Module\Insights\Preview\PreviewRenderer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Ubermuda\HealthCheckBundle\DiagnosticState;

final class PreviewRendererCheckTest extends TestCase
{
    public function test_a_missing_tool_fails(): void
    {
        $check = new PreviewRendererCheck(new PreviewRenderer('no-such-render-tool'));

        self::assertSame(DiagnosticState::Failed, $check()->state);
    }

    public function test_a_working_tool_passes(): void
    {
        if (null === new ExecutableFinder()->find('rsvg-convert')) {
            self::markTestSkipped('The rsvg-convert tool is not installed.');
        }

        self::assertSame(DiagnosticState::Ok, new PreviewRendererCheck(new PreviewRenderer())()->state);
    }
}
