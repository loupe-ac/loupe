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
    private const string DISCONNECTED = 'marker.bridge_disconnected';

    public function __construct(
        private TranslatorInterface $translator,
        private PauseReasonText $pauseReasonText,
    ) {
    }

    /** Plain text, for an attribute or an API. A reset time reads in UTC and names its zone. */
    public function label(StoredMarker $marker): string
    {
        if (self::USAGE_LIMIT === $marker->label) {
            return $this->usageLimit($marker->params, false);
        }

        return $this->text($marker);
    }

    /** Escaped HTML for a page. A reset time is a `<time>` element that the `local-time` controller rewrites in the zone of the reader. */
    public function html(StoredMarker $marker): string
    {
        if (self::USAGE_LIMIT === $marker->label) {
            return $this->usageLimit($marker->params, true);
        }

        return htmlspecialchars($this->text($marker), \ENT_QUOTES);
    }

    private function text(StoredMarker $marker): string
    {
        if (self::DISCONNECTED === $marker->label && '' === ($marker->params['%machine%'] ?? '')) {
            return $this->translator->trans(self::DISCONNECTED.'.unnamed');
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
     * The wording follows the cause. The server knows no zone of the reader, so the browser localises the HTML form.
     *
     * @param array<string, string|int> $params
     */
    private function usageLimit(array $params, bool $html): string
    {
        $window = (string) ($params['%window%'] ?? '');
        $use = $params['%use%'] ?? '';
        $resetsAt = new \DateTimeImmutable('@'.(int) ($params['%resetsAt%'] ?? 0));
        $named = \in_array($window, ['session', 'week'], true);

        $key = match (true) {
            'stop' === ($params['%cause%'] ?? '') => 'stop',
            '' === $use => 'limit_unmeasured',
            default => 'limit',
        };

        $words = [
            '%account%' => (string) ($params['%account%'] ?? ''),
            '%window%' => $named ? $this->translator->trans(self::USAGE_LIMIT.'.window.'.$window) : $window,
            '%cadence%' => $named ? $this->translator->trans(self::USAGE_LIMIT.'.cadence.'.$window) : $window,
            '%use%' => (string) $use,
            '%stopAt%' => (string) ($params['%stopAt%'] ?? ''),
        ];
        $date = $resetsAt->format('D j M');
        $time = $resetsAt->format('H:i').' UTC';
        if (!$html) {
            return $this->translator->trans(self::USAGE_LIMIT.'.'.$key, [...$words, '%date%' => $date, '%time%' => $time]);
        }

        $escaped = array_map(static fn (string $word): string => htmlspecialchars($word, \ENT_QUOTES), $words);

        return $this->translator->trans(self::USAGE_LIMIT.'.'.$key, [
            ...$escaped,
            '%date%' => self::timeElement($resetsAt, 'date', $date),
            '%time%' => self::timeElement($resetsAt, 'time', $time),
        ]);
    }

    private static function timeElement(\DateTimeImmutable $at, string $part, string $fallback): string
    {
        return \sprintf('<time datetime="%s" data-controller="local-time" data-local-time-part-value="%s">%s</time>', $at->format(\DATE_ATOM), $part, $fallback);
    }
}
