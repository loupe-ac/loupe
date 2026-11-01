<?php

declare(strict_types=1);

namespace App\Module\Insights\Form;

use App\Module\Insights\Entity\InsightsSavedGraph;
use Symfony\Component\Validator\Constraints as Assert;

class RenameGraphRequest
{
    public function __construct(
        #[Assert\Length(max: InsightsSavedGraph::MAX_NAME_LENGTH)]
        #[Assert\NotBlank]
        public ?string $name = null,
    ) {
    }

    public static function fromGraph(InsightsSavedGraph $graph): self
    {
        return new self($graph->name);
    }
}
