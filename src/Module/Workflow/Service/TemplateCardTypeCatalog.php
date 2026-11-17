<?php

declare(strict_types=1);

namespace App\Module\Workflow\Service;

use App\Module\Workflow\Contract\CardTypeCatalog;
use App\Module\Workflow\Contract\CardTypes;
use App\Module\Workflow\Repository\WorkflowBindingRepository;
use App\Module\Workflow\Template\ShippedTemplates;
use App\Module\Workflow\Template\TemplateParser;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Service\ResetInterface;

/** Reads the card types from the stored template copy, and nothing else of it. A project bound to no template gets the types of the Simple template. */
#[AsAlias(CardTypeCatalog::class)]
final class TemplateCardTypeCatalog implements CardTypeCatalog, ResetInterface
{
    private const string UNBOUND_TEMPLATE = 'simple';

    /** @var array<string, CardTypes> */
    private array $types = [];

    public function __construct(
        private readonly WorkflowBindingRepository $workflowBindings,
        private readonly ShippedTemplates $shippedTemplates,
        private readonly TemplateParser $parser,
    ) {
    }

    #[\Override]
    public function forProject(Uuid $projectId): CardTypes
    {
        return $this->types[$projectId->toRfc4122()] ??= $this->typesOf($projectId);
    }

    #[\Override]
    public function reset(): void
    {
        $this->types = [];
    }

    private function typesOf(Uuid $projectId): CardTypes
    {
        return $this->parser->parseStoredTypes($this->workflowBindings->findTypesSource($projectId) ?? $this->shippedTemplates->source(self::UNBOUND_TEMPLATE));
    }
}
