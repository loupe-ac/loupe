<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Board\Command\WidgetCardStartView;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\ColumnStarts;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Shapes the card start answers of the widget routes, so both name moves the same way. */
final readonly class WidgetCardStartAnswer
{
    public function __construct(
        private ColumnStarts $starts,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @param list<BoardColumn> $moves
     *
     * @return list<array{id: string, name: string, hint: ?string}>
     */
    public function moves(Project $project, array $moves): array
    {
        return array_map(
            fn (BoardColumn $column): array => [
                'id' => (string) $column->id,
                'name' => $this->translator->trans($column->label),
                'hint' => $this->starts->hint($project->requireId(), $column->ref()),
            ],
            $moves,
        );
    }

    public function columnName(WidgetCardStartView $view): string
    {
        return $this->translator->trans($view->card->column->label);
    }

    public function startsWork(WidgetCardStartView $view): bool
    {
        return $this->starts->startsWork($view->card->project->requireId(), $view->card->column->ref());
    }
}
