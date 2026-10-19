<?php

declare(strict_types=1);

namespace App\Module\Workflow\Service;

use App\Module\Workflow\Contract\DesignReviewRoundLimit;
use App\Module\Workflow\Template\TemplateMissing;
use App\Module\Workflow\Template\TemplateSource;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Uid\Uuid;

/** Reads the limit from the stored template copy. A project bound to no template has no limit. */
#[AsAlias(DesignReviewRoundLimit::class)]
final readonly class TemplateDesignReviewRoundLimit implements DesignReviewRoundLimit
{
    public function __construct(
        private TemplateSource $source,
    ) {
    }

    #[\Override]
    public function of(Uuid $projectId, array $tags): ?int
    {
        try {
            return $this->source->forProject($projectId)->reviewRoundLimit($tags);
        } catch (TemplateMissing) {
            return null;
        }
    }
}
