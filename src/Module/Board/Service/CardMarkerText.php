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
    private const string USAGE_LIMIT = 'marker.usage_limit';

    public function __construct(
        private TranslatorInterface $translator,
        private PauseReasonText $pauseReasonText,
    ) {
    }

    public function label(StoredMarker $marker): string
    {
        if (self::USAGE_LIMIT === $marker->label) {
            return $this->usageLimit($marker->params);
        }
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

    /**
     * The wording follows the cause. The reset time is stored as a timestamp and reads in the time zone of the reader.
     *
     * @param array<string, string|int> $params
     */
    private function usageLimit(array $params): string
    {
        $window = (string) ($params['%window%'] ?? '');
        $use = $params['%use%'] ?? '';
        $resetsAt = new \DateTimeImmutable('@'.(int) ($params['%resetsAt%'] ?? 0))->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        $named = \in_array($window, ['session', 'week'], true);

        $key = match (true) {
            'stop' === ($params['%cause%'] ?? '') => 'stop',
            '' === $use => 'limit_unmeasured',
            default => 'limit',
        };

        return $this->translator->trans(self::USAGE_LIMIT.'.'.$key, [
            '%account%' => (string) ($params['%account%'] ?? ''),
            '%window%' => $named ? $this->translator->trans(self::USAGE_LIMIT.'.window.'.$window) : $window,
            '%cadence%' => $named ? $this->translator->trans(self::USAGE_LIMIT.'.cadence.'.$window) : $window,
            '%use%' => (string) $use,
            '%stopAt%' => (string) ($params['%stopAt%'] ?? ''),
            '%date%' => $resetsAt->format('D j M'),
            '%time%' => $resetsAt->format('H:i'),
        ]);
    }
}
