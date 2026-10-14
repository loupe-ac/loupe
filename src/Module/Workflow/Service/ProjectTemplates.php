<?php

declare(strict_types=1);

namespace App\Module\Workflow\Service;

use App\Module\Workflow\Template\ShippedTemplates;
use App\Module\Workflow\Template\Template;
use App\Module\Workflow\Template\TemplateMissing;
use App\Module\Workflow\Template\TemplateParser;
use App\Module\Workflow\Template\TemplateSource;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Service\ResetInterface;

/** Reads the stored template copy of a project once per request. A project bound to no template gets the Simple template. */
final class ProjectTemplates implements ResetInterface
{
    private const string UNBOUND_TEMPLATE = 'simple';

    /** @var array<string, Template> */
    private array $templates = [];

    public function __construct(
        private readonly TemplateSource $source,
        private readonly ShippedTemplates $shippedTemplates,
        private readonly TemplateParser $parser,
    ) {
    }

    public function of(Uuid $projectId): Template
    {
        return $this->templates[$projectId->toRfc4122()] ??= $this->read($projectId);
    }

    #[\Override]
    public function reset(): void
    {
        $this->templates = [];
    }

    private function read(Uuid $projectId): Template
    {
        try {
            return $this->source->forProject($projectId);
        } catch (TemplateMissing) {
            return $this->parser->parse($this->shippedTemplates->source(self::UNBOUND_TEMPLATE));
        }
    }
}
