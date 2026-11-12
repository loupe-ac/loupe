<?php

declare(strict_types=1);

namespace App\Module\Insights\Controller\Api;

use App\Module\Bridge\Metric\Formula\MetricFormula;
use App\Module\Insights\Entity\InsightsSavedGraph;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * A new saved graph. It reads one metric (`settings.metric` and `settings.statistic`) or a formula
 * (`rows` and `formula`), and not both. A missing `settings` and an explicit null both fail NotNull,
 * so the type stays nullable and the schema does not.
 */
#[OA\Schema(required: ['settings'])]
final class CreateGraphRequest
{
    #[Assert\Length(max: InsightsSavedGraph::MAX_NAME_LENGTH)]
    #[OA\Property(description: 'The name of the tile. A blank name takes the title of the graph.', nullable: true, maxLength: InsightsSavedGraph::MAX_NAME_LENGTH)]
    public ?string $name = null;

    #[Assert\NotNull]
    #[Assert\Valid]
    #[OA\Property(ref: new Model(type: GraphSettingsInput::class))]
    public ?GraphSettingsInput $settings = null;

    /** @var list<GraphRowInput> */
    #[Assert\Count(max: MetricFormula::MAX_ROWS)]
    #[Assert\Valid]
    #[OA\Property(description: 'The lettered rows of a formula graph, up to six. Leave them out for a graph of one metric.', type: 'array', items: new OA\Items(ref: new Model(type: GraphRowInput::class)), maxItems: MetricFormula::MAX_ROWS)]
    public array $rows = [];

    #[Assert\Length(max: InsightsSavedGraph::MAX_FORMULA_LENGTH)]
    #[OA\Property(description: 'The formula over the letters of the rows, such as `A / B`.', nullable: true, maxLength: InsightsSavedGraph::MAX_FORMULA_LENGTH)]
    public ?string $formula = null;

    #[OA\Property(description: '`auto`, `number`, `percent`, `money` or `duration`. A formula graph defaults to `auto`.', nullable: true)]
    public ?string $format = null;

    #[Assert\Callback]
    public function requireUnit(ExecutionContextInterface $context): void
    {
        if (null !== $this->settings && null === $this->settings->unit) {
            $context->buildViolation(new Assert\NotNull()->message)->atPath('settings.unit')->addViolation();
        }
    }

    /** @return array<string, string> the controls that the request names, by name */
    public function settingsMap(): array
    {
        return ($this->settings ?? new GraphSettingsInput())->settings() + GraphRowInput::formulaSettings($this->rows, $this->formula, $this->format);
    }
}
