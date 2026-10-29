<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Workflow\Contract\PauseReasonText;
use App\Module\Workflow\Contract\StoredMarker;
use Symfony\Contracts\Translation\TranslatorInterface;

/** The label of a stored marker as words. The stored marker keeps the raw pause code, and the words follow the text and the locale of each render. */
final readonly class CardMarkerText
{
    private const string PAUSED = 'marker.paused';

    public function __construct(
        private TranslatorInterface $translator,
        private PauseReasonText $pauseReasonText,
    ) {
    }

    public function label(StoredMarker $marker): string
    {
        if (self::PAUSED !== $marker->label) {
            return $this->translator->trans($marker->label, $marker->params);
        }

        $code = (string) ($marker->params['%reason%'] ?? '');
        $text = '' === $code ? $code : $this->pauseReasonText->forPause($code);
        if ($text === $code) {
            return $this->translator->trans(self::PAUSED.'.code', ['%code%' => $code]);
        }

        return $this->translator->trans(self::PAUSED, ['%reason%' => rtrim($text, '.')]);
    }
}
