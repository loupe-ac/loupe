<?php

declare(strict_types=1);

namespace App\Module\Insights\Twig;

use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** The "Updated 4 min ago" line of the Dashboard and the share page: minutes under an hour, hours under a day, then days. */
final class DashboardAgeExtension extends AbstractExtension
{
    private const array FORMS = ['minutes', 'hour', 'hours', 'day', 'days'];

    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('dashboard_age_text', $this->text(...)),
            new TwigFunction('dashboard_age_texts', $this->texts(...)),
        ];
    }

    public function text(int $seconds): string
    {
        $minutes = intdiv(max(0, $seconds), 60);
        [$form, $count] = match (true) {
            $minutes < 60 => ['minutes', $minutes],
            $minutes < 1440 => [1 === intdiv($minutes, 60) ? 'hour' : 'hours', intdiv($minutes, 60)],
            default => [1 === intdiv($minutes, 1440) ? 'day' : 'days', intdiv($minutes, 1440)],
        };

        return $this->translator->trans('analytics.dashboard.updated.'.$form, ['%count%' => $count]);
    }

    /**
     * The sentences that the age controller fills in, with %count% left in place.
     *
     * @return array<string, string>
     */
    public function texts(): array
    {
        $texts = [];
        foreach (self::FORMS as $form) {
            $texts[$form] = $this->translator->trans('analytics.dashboard.updated.'.$form, ['%count%' => '%count%']);
        }

        return $texts;
    }
}
