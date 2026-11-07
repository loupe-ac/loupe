<?php

declare(strict_types=1);

namespace App\Module\Insights\Service;

use App\Module\Bridge\Metric\MetricGroupKey;
use App\Module\Insights\Entity\PublicSeriesLabelKind;
use App\Module\Insights\Repository\PublicSeriesLabelRepository;
use App\Module\Insights\View\DashboardGraphResult;
use App\Module\Project\Entity\Project;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Replaces the series names of a graph with numbers, so a public page never shows an account or a bridge name. The
 * project keeps one number for each account and bridge, and the number never changes. The labels come from the
 * structured key of each series, because a finished label cannot be split back into its parts.
 */
final readonly class PublicSeriesLabels
{
    public function __construct(
        private EntityManagerInterface $em,
        private PublicSeriesLabelRepository $publicSeriesLabels,
        private TranslatorInterface $translator,
    ) {
    }

    public function apply(Project $project, DashboardGraphResult $result): DashboardGraphResult
    {
        $wanted = [];
        foreach ($result->seriesKeys as $key) {
            foreach (null === $key ? [] : $this->partsOf($key) as [$kind, $name]) {
                $wanted[$kind->value][$name] = true;
            }
        }
        $numbers = $this->numbersFor($project, $wanted);

        $labels = [];
        foreach ($result->labels as $position => $label) {
            $key = $result->seriesKeys[$position] ?? null;
            $labels[] = null === $key ? $label : $this->labelOf($key, $numbers);
        }

        return new DashboardGraphResult($result->computedAt, $result->title, $result->chart, $result->layout, $labels, $result->seriesKeys, $result->current, $result->previous);
    }

    /**
     * @param array<string, array<string, true>> $wanted the keys to number, by kind value
     *
     * @return array<string, array<string, int>> the numbers, by kind value and key
     */
    private function numbersFor(Project $project, array $wanted): array
    {
        $numbers = [];
        $missing = false;
        foreach (PublicSeriesLabelKind::cases() as $kind) {
            $numbers[$kind->value] = $this->publicSeriesLabels->numbersFor($project, $kind);
            $missing = $missing || [] !== array_diff_key($wanted[$kind->value] ?? [], $numbers[$kind->value]);
        }
        if (!$missing) {
            return $numbers;
        }

        return $this->em->wrapInTransaction(function () use ($project, $wanted): array {
            $this->em->lock($project, LockMode::PESSIMISTIC_WRITE);
            $numbers = [];
            foreach (PublicSeriesLabelKind::cases() as $kind) {
                $held = $this->publicSeriesLabels->numbersFor($project, $kind);
                $next = [] === $held ? 1 : max($held) + 1;
                foreach (array_keys($wanted[$kind->value] ?? []) as $name) {
                    if (!isset($held[$name])) {
                        $this->publicSeriesLabels->insertIfAbsent($project, $kind, (string) $name, $next);
                        ++$next;
                    }
                }
                $numbers[$kind->value] = $this->publicSeriesLabels->numbersFor($project, $kind);
            }

            return $numbers;
        });
    }

    /** @return list<array{PublicSeriesLabelKind, string}> the bridge first, then the account */
    private function partsOf(MetricGroupKey $key): array
    {
        $parts = [];
        if (null !== $key->bridgeId) {
            $parts[] = [PublicSeriesLabelKind::Bridge, $key->bridgeId];
        }
        if (null !== $key->account) {
            $parts[] = [PublicSeriesLabelKind::Account, $key->account];
        }

        return $parts;
    }

    /** @param array<string, array<string, int>> $numbers */
    private function labelOf(MetricGroupKey $key, array $numbers): string
    {
        $bridge = null === $key->bridgeId ? null : $numbers[PublicSeriesLabelKind::Bridge->value][$key->bridgeId] ?? throw new \LogicException('A bridge of a series has a number.');
        $account = null === $key->account ? null : $numbers[PublicSeriesLabelKind::Account->value][$key->account] ?? throw new \LogicException('An account of a series has a number.');

        if (null !== $bridge && null !== $account) {
            return trim($this->translator->trans('analytics.public.series.bridge_account', ['%bridge%' => $bridge, '%account%' => $account, '%window%' => $key->window ?? '']));
        }
        if (null !== $bridge) {
            return $this->translator->trans('analytics.public.series.bridge', ['%number%' => $bridge]);
        }
        if (null !== $account) {
            return $this->translator->trans('analytics.public.series.account', ['%number%' => $account]);
        }

        throw new \LogicException('A series key names a bridge or an account.');
    }
}
