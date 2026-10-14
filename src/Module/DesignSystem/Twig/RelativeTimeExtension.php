<?php

declare(strict_types=1);

namespace App\Module\DesignSystem\Twig;

use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * The compact age of a moment, such as "2h ago" or "last week". The RelativeTime
 * component calls it, and so does a template that builds a sentence around the age.
 */
final class RelativeTimeExtension extends AbstractExtension
{
    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[\Override]
    public function getFilters(): array
    {
        return [new TwigFilter('relative_time', $this->relativeTime(...))];
    }

    public function relativeTime(\DateTimeInterface $when, ?\DateTimeInterface $now = null): string
    {
        $now ??= new \DateTimeImmutable();
        $seconds = max(0, $now->getTimestamp() - $when->getTimestamp());

        [$key, $count] = match (true) {
            $seconds < 60 => ['just_now', 0],
            $seconds < 3600 => ['minutes', intdiv($seconds, 60)],
            $seconds < 86400 => ['hours', intdiv($seconds, 3600)],
            $seconds < 604800 => ['days', intdiv($seconds, 86400)],
            $seconds < 1209600 => ['last_week', 0],
            $seconds < 2592000 => ['weeks', intdiv($seconds, 604800)],
            $seconds < 5259600 => ['last_month', 0],
            $seconds < 31557600 => ['months', intdiv($seconds, 2592000)],
            default => ['years', intdiv($seconds, 31557600)],
        };

        return $this->translator->trans('ds.relative_time.'.$key, ['%count%' => $count]);
    }
}
