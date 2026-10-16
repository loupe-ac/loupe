<?php

declare(strict_types=1);

namespace App\Module\Bridge\Twig;

use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Project\Entity\Project;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** The open runs of a project, for the pill of the Activity link in the sidebar. */
final class ActivityNavExtension extends AbstractExtension
{
    public function __construct(
        private readonly WorkerRunRepository $workerRuns,
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [new TwigFunction('project_open_run_count', $this->workerRuns->countOpenOfProject(...))];
    }
}
