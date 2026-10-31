<?php

declare(strict_types=1);

namespace App\Module\Insights\Form;

use App\Module\Insights\Entity\InsightsSavedGraph;
use App\Module\Insights\Entity\SavedGraphWidth;
use Symfony\Component\Validator\Constraints as Assert;

class GraphWidthRequest
{
    public function __construct(
        #[Assert\NotNull]
        public ?SavedGraphWidth $width = null,
    ) {
    }

    public static function fromGraph(InsightsSavedGraph $graph): self
    {
        return new self($graph->width);
    }
}
