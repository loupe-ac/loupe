<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Preview;

use App\Module\Insights\Preview\PreviewRenderer;
use App\Module\Insights\Preview\PreviewRenderFailed;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;

final class PreviewRendererTest extends TestCase
{
    private const string SVG = '<svg xmlns="http://www.w3.org/2000/svg" width="40" height="20" viewBox="0 0 40 20"><rect width="40" height="20" fill="#2a78d6"/></svg>';

    public function test_a_missing_tool_fails(): void
    {
        $this->expectException(PreviewRenderFailed::class);

        new PreviewRenderer('no-such-render-tool')->render(self::SVG);
    }

    public function test_a_tool_that_exits_with_an_error_fails(): void
    {
        $this->expectException(PreviewRenderFailed::class);

        new PreviewRenderer('false')->render(self::SVG);
    }

    public function test_a_tool_that_writes_no_png_fails(): void
    {
        $this->expectException(PreviewRenderFailed::class);

        new PreviewRenderer('true')->render(self::SVG);
    }

    public function test_a_document_the_tool_cannot_read_fails(): void
    {
        $this->requireTool();

        $this->expectException(PreviewRenderFailed::class);

        new PreviewRenderer()->render('not an svg document');
    }

    public function test_an_svg_becomes_a_png_of_the_given_size(): void
    {
        $this->requireTool();

        $png = new PreviewRenderer()->render(self::SVG, 80, 40);

        self::assertStringStartsWith("\x89PNG\r\n\x1a\n", $png);
        $size = getimagesizefromstring($png);
        self::assertIsArray($size);
        self::assertSame([80, 40], [$size[0], $size[1]]);
    }

    private function requireTool(): void
    {
        if (null === new ExecutableFinder()->find('rsvg-convert')) {
            self::markTestSkipped('The rsvg-convert tool is not installed.');
        }
    }
}
