<?php

declare(strict_types=1);

namespace App\Module\Insights\Twig;

use App\Module\Insights\Entity\InsightsSavedGraph;
use App\Module\Insights\Form\ShareGraphRequest;
use App\Module\Insights\Service\DashboardGraphForms;
use App\Module\Insights\View\DashboardGraphFormViews;
use App\Module\Insights\View\GraphShareView;
use Symfony\Component\Form\FormView;
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
            new TwigFunction('dashboard_graph_share_form', $this->shareForm(...)),
        ];
    }

    /** The switch of the share dialog. It is disabled while a reason blocks the share. */
    public function shareForm(InsightsSavedGraph $graph, GraphShareView $share): FormView
    {
        return $this->forms->share($graph, ShareGraphRequest::fromState($share->isEnabled()), null !== $share->block)->createView();
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
