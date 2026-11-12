<?php

declare(strict_types=1);

namespace App\Module\Insights\Controller\Api;

use App\Api\OpenApi\PartialChangeSchemaProcessor;
use App\Module\Bridge\Metric\Formula\MetricFormula;
use App\Module\Insights\Entity\InsightsSavedGraph;
use App\Module\Insights\Entity\SavedGraphWidth;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A partial change of a saved graph. A field left out stays uninitialized and keeps its value. An explicit
 * null fails the property type, so it answers 422. The graph keeps the settings you leave out, and the whole
 * set must hold together.
 */
#[OA\Schema(description: 'A field you leave out keeps its value.', x: [PartialChangeSchemaProcessor::MARKER => true])]
final class UpdateGraphRequest
{
    #[Assert\Length(max: InsightsSavedGraph::MAX_NAME_LENGTH)]
    #[Assert\Regex('/\S/')]
    #[OA\Property(maxLength: InsightsSavedGraph::MAX_NAME_LENGTH, minLength: 1)]
    public string $name;

    #[Assert\Valid]
    #[OA\Property(ref: new Model(type: GraphSettingsInput::class))]
    public GraphSettingsInput $settings;

    /** @var list<GraphRowInput> */
    #[Assert\Count(min: 1, max: MetricFormula::MAX_ROWS)]
    #[Assert\Valid]
    #[OA\Property(description: 'The lettered rows of a formula graph. They replace all the rows. To turn a one-metric graph into a formula graph, pass both `rows` and `formula`.', type: 'array', items: new OA\Items(ref: new Model(type: GraphRowInput::class)), maxItems: MetricFormula::MAX_ROWS, minItems: 1)]
    public array $rows;

    #[Assert\Length(max: InsightsSavedGraph::MAX_FORMULA_LENGTH)]
    #[OA\Property(description: 'The formula over the letters of the rows, such as `A / B`.', maxLength: InsightsSavedGraph::MAX_FORMULA_LENGTH)]
    public string $formula;

    #[OA\Property(description: '`auto`, `number`, `percent`, `money` or `duration`.')]
    public string $formulaFormat;

    #[OA\Property(description: 'True turns a formula graph back into a graph of one metric. It keeps the first row, and takes `settings.metric` and `settings.statistic` when you pass them. Do not pass it with `rows`, `formula` or `formulaFormat`.')]
    public bool $clearFormula;

    #[OA\Property(description: 'How much of the Dashboard row the graph takes.', enum: ['half', 'full'])]
    public SavedGraphWidth $width;

    #[OA\Property(description: 'Whether the tile shows its legend.')]
    public bool $showLegend;

    #[Assert\PositiveOrZero]
    #[OA\Property(description: 'The place to take among the graphs on the Dashboard, counted from 0 as the graph list counts it. A place past the last puts the graph last. The other graphs keep their order.', minimum: 0)]
    public int $position;

    /** @return array<string, string> the one-metric controls that the request names */
    public function passed(): array
    {
        return isset($this->settings) ? $this->settings->settings() : [];
    }

    /** @return array<string, string> the rows, the formula and the format that the request names */
    public function formulaPassed(): array
    {
        return GraphRowInput::formulaSettings($this->rows ?? [], $this->formula ?? null, $this->formulaFormat ?? null);
    }
}
