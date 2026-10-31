<?php

declare(strict_types=1);

namespace App\Module\Insights\Form;

use App\Module\Insights\Entity\InsightsSavedGraph;
use Symfony\Component\Validator\Constraints as Assert;

class GraphLegendRequest
{
    public function __construct(
        #[Assert\NotNull]
        public ?bool $show = null,
    ) {
    }

    public static function fromGraph(InsightsSavedGraph $graph): self
    {
        return new self($graph->showLegend);
    }
}
