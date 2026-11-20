<?php

declare(strict_types=1);

namespace App\Module\Insights\Service;

use App\Module\Bridge\Metric\Metric;
use App\Module\Insights\Command\ListReportRowsCommand;
use App\Module\Insights\Command\ListReportRowsHandler;
use App\Module\Insights\Entity\Proposal;
use App\Module\Project\Entity\Project;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\UX\Turbo\TurboBundle;
use Twig\Environment;

/** The answer that an action on a proposal of the Reports tab gives: the row of its analysis as a Turbo Stream, and a message. */
final readonly class ReportStreamAnswers
{
    public function __construct(
        private ListReportRowsHandler $listRows,
        private Environment $twig,
    ) {
    }

    public function row(Project $project, Proposal $proposal, Request $request, string $severity, ?string $message, ?FormView $refusedDismissForm = null, int $status = Response::HTTP_OK): Response
    {
        $analysisId = $proposal->analysis->id ?? throw new \LogicException('A kept analysis has an id.');
        $html = $this->twig->render('@Insights/_report_row.stream.html.twig', [
            'project' => $project,
            'analysisId' => (string) $analysisId,
            'row' => ($this->listRows)(new ListReportRowsCommand($project, [$analysisId]))->rows[0] ?? null,
            'page' => max(1, $request->query->getInt('page', 1)),
            'costMetric' => Metric::Cost->valueType(),
            'refusedDismissForm' => $refusedDismissForm,
            'severity' => $severity,
            'message' => $message,
        ]);

        return new Response($html, $status, ['Content-Type' => TurboBundle::STREAM_MEDIA_TYPE]);
    }
}
