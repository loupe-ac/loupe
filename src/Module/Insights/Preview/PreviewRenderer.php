<?php

declare(strict_types=1);

namespace App\Module\Insights\Preview;

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/** Renders an SVG document to a PNG image with the rsvg-convert tool. */
final readonly class PreviewRenderer
{
    private const float TIMEOUT_SECONDS = 5.0;
    private const string PNG_SIGNATURE = "\x89PNG\r\n\x1a\n";

    public function __construct(
        private string $binary = 'rsvg-convert',
    ) {
    }

    /** @throws PreviewRenderFailed when the tool is missing, exits with an error, times out or writes no PNG */
    public function render(string $svg, int $width = 1200, int $height = 630): string
    {
        $binary = str_contains($this->binary, '/') && is_executable($this->binary) ? $this->binary : new ExecutableFinder()->find($this->binary);
        if (null === $binary) {
            throw new PreviewRenderFailed(\sprintf('The %s tool is not installed.', $this->binary));
        }

        $process = new Process([$binary, '-f', 'png', '-w', (string) $width, '-h', (string) $height]);
        $process->setInput($svg);
        $process->setTimeout(self::TIMEOUT_SECONDS);

        try {
            $process->run();
        } catch (\Throwable $error) {
            throw new PreviewRenderFailed(\sprintf('The %s tool did not finish: %s', $this->binary, $error->getMessage()), previous: $error);
        }

        if (!$process->isSuccessful()) {
            throw new PreviewRenderFailed(\sprintf('The %s tool exited with code %d: %s', $this->binary, (int) $process->getExitCode(), trim($process->getErrorOutput())));
        }

        $png = $process->getOutput();
        if (!str_starts_with($png, self::PNG_SIGNATURE)) {
            throw new PreviewRenderFailed(\sprintf('The %s tool wrote no PNG image.', $this->binary));
        }

        return $png;
    }
}
