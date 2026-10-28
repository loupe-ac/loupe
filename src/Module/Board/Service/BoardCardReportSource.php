<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Board\Entity\CardEventKind;
use App\Module\Board\Repository\CardEventRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Bridge\Experiment\CardColumn;
use App\Module\Bridge\Experiment\CardReportSourceInterface;
use App\Module\Project\Entity\Project;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(CardReportSourceInterface::class)]
final readonly class BoardCardReportSource implements CardReportSourceInterface
{
    public function __construct(
        private CardRepository $cards,
        private CardEventRepository $cardEvents,
    ) {
    }

    #[\Override]
    public function columnsFor(Project $project, array $cardIds): array
    {
        if ([] === $cardIds) {
            return [];
        }

        $columns = [];
        foreach ($this->cards->findColumnsByIds($project, $cardIds) as $row) {
            $columns[(string) $row['id']] = new CardColumn($row['label'], $row['terminal']);
        }

        return $columns;
    }

    #[\Override]
    public function outcomesOfCards(Project $project, array $cardIds): array
    {
        if ([] === $cardIds) {
            return [];
        }

        $outcomes = [];
        foreach ($this->cardEvents->findKindsOfCards($project, $cardIds, [CardEventKind::Moved]) as $row) {
            $cause = $row['detail']['cause'] ?? null;
            $outcome = \is_array($cause) ? $cause['outcome'] ?? null : null;
            if (\is_string($outcome) && '' !== $outcome) {
                $outcomes[$row['cardId']][$outcome] = $outcome;
            }
        }

        return array_map(static function (array $keys): array {
            $keys = array_values($keys);
            sort($keys, \SORT_STRING);

            return $keys;
        }, $outcomes);
    }

    #[\Override]
    public function typesFor(Project $project, array $cardIds): array
    {
        if ([] === $cardIds) {
            return [];
        }

        $types = [];
        foreach ($this->cards->findTypesByIds($project, $cardIds) as $row) {
            $types[(string) $row['id']] = $row['type'];
        }

        return $types;
    }

    #[\Override]
    public function historyStartFor(Project $project): ?\DateTimeImmutable
    {
        return $this->cardEvents->findFirstOccurredAt($project);
    }
}
