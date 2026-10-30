<?php

declare(strict_types=1);

namespace App\Module\Workflow\Service;

use App\Module\Workflow\Contract\CardValueKeys;
use App\Module\Workflow\Contract\TemplateValueKey;
use App\Module\Workflow\Template\ShippedTemplates;
use App\Module\Workflow\Template\TemplateMissing;
use App\Module\Workflow\Template\TemplateParser;
use App\Module\Workflow\Template\TemplateSource;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Service\ResetInterface;

/** Reads the value keys from the stored template copy. A project bound to no template gets the keys of the Simple template. */
#[AsAlias(CardValueKeys::class)]
final class TemplateCardValueKeys implements CardValueKeys, ResetInterface
{
    private const string UNBOUND_TEMPLATE = 'simple';

    /** @var array<string, list<TemplateValueKey>> */
    private array $keys = [];

    public function __construct(
        private readonly TemplateSource $templates,
        private readonly ShippedTemplates $shippedTemplates,
        private readonly TemplateParser $parser,
    ) {
    }

    #[\Override]
    public function forProject(Uuid $projectId): array
    {
        return $this->keys[$projectId->toRfc4122()] ??= $this->template($projectId);
    }

    #[\Override]
    public function reset(): void
    {
        $this->keys = [];
    }

    /** @return list<TemplateValueKey> */
    private function template(Uuid $projectId): array
    {
        try {
            return $this->templates->forProject($projectId)->values;
        } catch (TemplateMissing) {
            return $this->parser->parse($this->shippedTemplates->source(self::UNBOUND_TEMPLATE))->values;
        }
    }
}
