<?php

declare(strict_types=1);

namespace App\Module\Insights\Service;

use App\Module\Insights\Entity\InsightsSavedGraph;
use App\Module\Insights\Form\DragGraphFormType;
use App\Module\Insights\Form\DragGraphRequest;
use App\Module\Insights\Form\GraphLegendFormType;
use App\Module\Insights\Form\GraphLegendRequest;
use App\Module\Insights\Form\GraphWidthFormType;
use App\Module\Insights\Form\GraphWidthRequest;
use App\Module\Insights\Form\RenameGraphFormType;
use App\Module\Insights\Form\RenameGraphRequest;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The forms of one tile. Each form has its own name, so the tiles of a page share no DOM id,
 * and the controller that receives a post builds the same form under the same name.
 */
final readonly class DashboardGraphForms
{
    public function __construct(
        private FormFactoryInterface $forms,
        private UrlGeneratorInterface $urls,
    ) {
    }

    /** @return FormInterface<RenameGraphRequest> */
    public function rename(InsightsSavedGraph $graph, ?RenameGraphRequest $data = null): FormInterface
    {
        return $this->forms->createNamed('rename_graph_'.$graph->id, RenameGraphFormType::class, $data ?? RenameGraphRequest::fromGraph($graph), ['action' => $this->url('rename', $graph)]);
    }

    /** @return FormInterface<GraphWidthRequest> */
    public function width(InsightsSavedGraph $graph, ?GraphWidthRequest $data = null): FormInterface
    {
        return $this->forms->createNamed('graph_width_'.$graph->id, GraphWidthFormType::class, $data ?? GraphWidthRequest::fromGraph($graph), ['action' => $this->url('width', $graph)]);
    }

    /** @return FormInterface<GraphLegendRequest> */
    public function legend(InsightsSavedGraph $graph, ?GraphLegendRequest $data = null): FormInterface
    {
        return $this->forms->createNamed('graph_legend_'.$graph->id, GraphLegendFormType::class, $data ?? GraphLegendRequest::fromGraph($graph), ['action' => $this->url('legend', $graph)]);
    }

    /** @return FormInterface<DragGraphRequest> */
    public function drag(InsightsSavedGraph $graph, ?DragGraphRequest $data = null): FormInterface
    {
        return $this->forms->createNamed('drag_graph_'.$graph->id, DragGraphFormType::class, $data ?? new DragGraphRequest(), ['action' => $this->url('drag', $graph)]);
    }

    private function url(string $action, InsightsSavedGraph $graph): string
    {
        return $this->urls->generate('app_project_analytics_dashboard_graph_'.$action, ['id' => (string) $graph->project->requireId(), 'graphId' => (string) $graph->id]);
    }
}
