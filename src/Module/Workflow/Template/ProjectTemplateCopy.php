<?php

declare(strict_types=1);

namespace App\Module\Workflow\Template;

use App\Module\Workflow\Repository\WorkflowBindingRepository;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Service\ResetInterface;

/** Reads the template copy a project stored when it was bound, with the app rules after its own. It keeps each project's template until the services reset. */
#[AsAlias(TemplateSource::class)]
final class ProjectTemplateCopy implements TemplateSource, ResetInterface
{
    /** @var array<string, Template> */
    private array $templates = [];

    public function __construct(
        private readonly WorkflowBindingRepository $workflowBindings,
        private readonly TemplateParser $parser,
        private readonly AppRules $appRules,
    ) {
    }

    #[\Override]
    public function forProject(Uuid $projectId): Template
    {
        $key = $projectId->toRfc4122();
        if (isset($this->templates[$key])) {
            return $this->templates[$key];
        }
        $binding = $this->workflowBindings->findOneByProjectId($projectId) ?? throw new TemplateMissing($projectId);

        return $this->templates[$key] = $this->appRules->appendTo($this->parser->parseStored($binding->definition));
    }

    /**
     * Reads the copy from the database again, and leaves the kept one alone.
     *
     * @throws TemplateMissing when the project is bound to no template
     */
    public function forProjectFresh(Uuid $projectId): Template
    {
        $definition = $this->workflowBindings->findDefinitionFresh($projectId) ?? throw new TemplateMissing($projectId);

        return $this->appRules->appendTo($this->parser->parseStored($definition));
    }

    #[\Override]
    public function reset(): void
    {
        $this->templates = [];
    }
}
