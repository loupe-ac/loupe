<?php

declare(strict_types=1);

namespace App\Module\Insights\Service;

use App\Module\Insights\Entity\InsightsSavedGraph;
use App\Module\Insights\Form\SavedGraphFormType;
use App\Module\Insights\Form\SavedGraphRequest;
use App\Module\Project\Entity\Project;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\UX\Turbo\TurboBundle;
use Twig\Environment;

/** The answers that a save on the Metrics tab gives, as Turbo Streams. The page keeps its address and its controls. */
final readonly class MetricsSaveStreamAnswers
{
    public function __construct(
        private SavedGraphCap $cap,
        private FormFactoryInterface $forms,
        private UrlGeneratorInterface $urls,
        private Environment $twig,
    ) {
    }

    /** A refused save: the page stays as it is, and a flash says why. */
    public function refused(string $message): Response
    {
        return $this->stream($this->twig->render('_flash.stream.html.twig', ['severity' => 'error', 'message' => $message]), Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /** A saved graph: the save form follows the new count of graphs, and a new graph gets the link to the Dashboard. */
    public function saved(Project $project, InsightsSavedGraph $graph, SavedGraphRequest $data, bool $isNew, string $message): Response
    {
        $saveForm = $this->forms->create(SavedGraphFormType::class, $data, ['action' => $this->urls->generate('app_project_analytics_save_graph', ['id' => (string) $project->id])]);

        return $this->stream($this->twig->render('@Insights/_metrics_save.stream.html.twig', [
            'project' => $project,
            'graph' => $graph,
            'saveForm' => $saveForm->createView(),
            'capReached' => $this->cap->isReached($project),
            'capLimit' => $this->cap->limit(),
            'saved' => $isNew,
            'severity' => 'success',
            'message' => $message,
        ]));
    }

    private function stream(string $html, int $status = Response::HTTP_OK): Response
    {
        return new Response($html, $status, ['Content-Type' => TurboBundle::STREAM_MEDIA_TYPE]);
    }
}
