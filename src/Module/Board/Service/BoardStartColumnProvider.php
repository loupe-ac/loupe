<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Repository\BoardColumnRepository;
use App\Module\Project\Entity\Project;
use App\Module\SiteReview\StartColumn\StartColumn;
use App\Module\SiteReview\StartColumn\StartColumnProviderInterface;
use App\Module\Workflow\Contract\ColumnStarts;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Offers the Backlog and each column the move guard allows from it, and nothing to a preview token. */
final readonly class BoardStartColumnProvider implements StartColumnProviderInterface
{
    public function __construct(
        private BoardColumnRepository $boardColumns,
        private WidgetStartOptions $options,
        private WidgetSignIn $signIn,
        private ColumnStarts $starts,
        private TranslatorInterface $translator,
    ) {
    }

    #[\Override]
    public function forCurrentRequest(Project $project): array
    {
        if ($this->signIn->isPreviewToken()) {
            return [];
        }
        $all = $this->boardColumns->findForProject($project);
        $backlog = array_find($all, static fn (BoardColumn $column): bool => $column->backlog);
        if (null === $backlog) {
            return [];
        }

        return array_map(
            fn (BoardColumn $column): StartColumn => new StartColumn(
                (string) $column->id,
                $this->translator->trans($column->label),
                $column->backlog ? null : $this->starts->hint($project->requireId(), $column->ref()),
            ),
            [$backlog, ...$this->options->allowedAmong($project, $all)],
        );
    }
}
