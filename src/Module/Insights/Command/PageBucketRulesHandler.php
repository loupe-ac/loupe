<?php

declare(strict_types=1);

namespace App\Module\Insights\Command;

use App\Api\Pagination\Cursor;
use App\Api\Pagination\Page;
use App\Module\Insights\Repository\InsightsBucketRuleRepository;
use App\Module\Insights\Service\PositionCursor;

/** Reads a page of the bucket rules of a project in the order they apply. A project holds at most 50 rules, so the page is cut from that list. */
final readonly class PageBucketRulesHandler
{
    public function __construct(
        private InsightsBucketRuleRepository $insightsBucketRules,
    ) {
    }

    /** @return Page<BucketRuleRow> */
    public function __invoke(PageBucketRulesCommand $command): Page
    {
        $after = null !== $command->page->cursor ? PositionCursor::positionOf($command->page->cursor) : null;

        $rows = [];
        foreach ($this->insightsBucketRules->findOrdered($command->project) as $place => $rule) {
            if (null === $after || PositionCursor::isAfter($rule->position, $rule->id ?? throw new \LogicException('A stored rule has an id.'), $after[0], $after[1])) {
                $rows[] = new BucketRuleRow($rule, $place);
            }
        }

        return Page::fromRows(
            \array_slice($rows, 0, $command->page->fetchSize()),
            $command->page,
            static fn (BucketRuleRow $row): Cursor => PositionCursor::after($row->rule->position, $row->rule->id ?? throw new \LogicException('A stored rule has an id.')),
        );
    }
}
