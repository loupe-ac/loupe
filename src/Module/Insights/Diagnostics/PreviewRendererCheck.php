<?php

declare(strict_types=1);

namespace App\Module\Insights\Diagnostics;

use App\Module\Insights\Preview\PreviewRenderer;
use App\Module\Insights\Preview\PreviewRenderFailed;
use Ubermuda\HealthCheckBundle\Diagnostic;
use Ubermuda\HealthCheckBundle\DiagnosticInterface;
use Ubermuda\HealthCheckBundle\DiagnosticState;

/** The share preview image comes from the rsvg-convert tool. A missing tool shows only as a share link with no image. */
final readonly class PreviewRendererCheck implements DiagnosticInterface
{
    private const string SVG = '<svg xmlns="http://www.w3.org/2000/svg" width="8" height="8" viewBox="0 0 8 8"><rect width="8" height="8" fill="#2a78d6"/><text x="1" y="6" font-size="6">A</text></svg>';

    public function __construct(
        private PreviewRenderer $renderer,
    ) {
    }

    #[\Override]
    public static function priority(): int
    {
        return 13;
    }

    #[\Override]
    public function __invoke(): Diagnostic
    {
        try {
            $this->renderer->render(self::SVG, 8, 8);
        } catch (PreviewRenderFailed) {
            return new Diagnostic('preview_renderer', DiagnosticState::Failed, 'insights.system_status.preview_renderer.failed');
        }

        return new Diagnostic('preview_renderer', DiagnosticState::Ok, 'insights.system_status.preview_renderer.ok');
    }
}
