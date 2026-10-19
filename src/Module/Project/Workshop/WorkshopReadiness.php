<?php

declare(strict_types=1);

namespace App\Module\Project\Workshop;

/** The readiness guide the Workshop shows until the owner hides it. */
final readonly class WorkshopReadiness
{
    public function __construct(
        /** @var list<WorkshopReadinessRow> */
        public array $rows,
        /** The card of the latest discovery run. Its open run does not end the quiet Workshop. */
        public ?int $discoveryCardNumber = null,
    ) {
    }

    public function row(string $key): ?WorkshopReadinessRow
    {
        return array_find($this->rows, static fn (WorkshopReadinessRow $row): bool => $key === $row->key);
    }

    /** Only the required rows count. */
    public function doneCount(): int
    {
        return \count(array_filter($this->rows, static fn (WorkshopReadinessRow $row): bool => $row->required() && $row->done));
    }

    public function total(): int
    {
        return \count(array_filter($this->rows, static fn (WorkshopReadinessRow $row): bool => $row->required()));
    }

    /**
     * The rows by section, in the order of the guide. A section with no row is left out.
     *
     * @return array<string, list<WorkshopReadinessRow>>
     */
    public function groups(): array
    {
        $groups = [];
        foreach ([WorkshopReadinessRow::GROUP_DISCOVERY, WorkshopReadinessRow::GROUP_PULL_REQUESTS, WorkshopReadinessRow::GROUP_OPTIONAL] as $group) {
            $rows = array_values(array_filter($this->rows, static fn (WorkshopReadinessRow $row): bool => $group === $row->group));
            if ([] !== $rows) {
                $groups[$group] = $rows;
            }
        }

        return $groups;
    }
}
