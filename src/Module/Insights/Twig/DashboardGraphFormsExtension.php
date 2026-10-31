<?php

declare(strict_types=1);

namespace App\Module\Insights\Twig;

use App\Module\Insights\Entity\InsightsSavedGraph;
use App\Module\Insights\Service\DashboardGraphForms;
use App\Module\Insights\View\DashboardGraphFormViews;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** One set of forms for each tile of the Dashboard. */
final class DashboardGraphFormsExtension extends AbstractExtension
{
    public function __construct(
        private readonly DashboardGraphForms $forms,
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('dashboard_graph_forms', $this->graphForms(...)),
        ];
    }

    public function graphForms(InsightsSavedGraph $graph): DashboardGraphFormViews
    {
        return new DashboardGraphFormViews(
            $this->forms->rename($graph)->createView(),
            $this->forms->width($graph)->createView(),
            $this->forms->legend($graph)->createView(),
            $this->forms->drag($graph)->createView(),
        );
    }
}
