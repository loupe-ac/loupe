<?php

declare(strict_types=1);

namespace App\Module\Insights\Mcp;

use App\Exception\DomainErrors;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\Service\ToolCallCollectionSettings;
use App\Module\Insights\Command\ReportAnalysisHandler;
use App\Module\Insights\Command\UpdateAnalyticsSettingsHandler;
use App\Module\Insights\Entity\InsightsBucketRule;
use App\Module\Insights\Entity\Proposal;
use App\Module\Insights\Service\BucketRuleWriter;
use App\Module\Insights\Service\SavedGraphCap;
use App\Module\Insights\Service\SavedGraphSettingsResolver;
use Mcp\Exception\ToolCallException;

/** Renders a handler's DomainErrors as the message an agent reads. An unmapped key gives a generic message, never the key. */
final readonly class InsightsToolErrorMessages
{
    public const string UNMAPPED = 'The request was rejected. The error has been logged.';

    /** @param array<string, string> $arguments the tool argument that each handler field stands for, when the names differ */
    public function forAgent(DomainErrors $errors, array $arguments = []): ToolCallException
    {
        $lines = [];
        foreach ($errors->errors as $field => $key) {
            if (SavedGraphCap::ERROR_KEY === $key) {
                $lines[] = \sprintf('The Dashboard holds the most graphs it can, %d. Remove a graph with graph_remove first.', $errors->parameters[$field]['%limit%'] ?? 0);
                continue;
            }
            $lines[] = \sprintf('%s: %s', $arguments[$field] ?? $field, self::sentence($key));
        }

        return new ToolCallException(implode("\n", $lines), previous: $errors);
    }

    private static function sentence(string $key): string
    {
        return match ($key) {
            ReportAnalysisHandler::UNKNOWN_ANALYSIS => 'No analysis of this project has this id. Pass the analysis id from your work request.',
            ReportAnalysisHandler::FINISHED => 'The analysis is already done or failed, so it takes no report.',
            ReportAnalysisHandler::UNKNOWN_DOCUMENT => 'The document must be a document of this project. Create the report with document_create first.',
            ReportAnalysisHandler::TOO_MANY_PROPOSALS => \sprintf('Pass at most %d proposals.', ReportAnalysisHandler::MAX_PROPOSALS),
            ReportAnalysisHandler::INVALID_KIND => 'A proposal kind is card or bucket-rule.',
            ReportAnalysisHandler::TITLE_BLANK => 'A proposal title must not be blank.',
            ReportAnalysisHandler::TITLE_TOO_LONG => \sprintf('A proposal title must be at most %d characters.', Proposal::MAX_TITLE_LENGTH),
            ReportAnalysisHandler::BUCKET_RULE_INVALID => 'A bucket-rule proposal needs a payload with pattern, a glob of at most 120 characters, and bucket, a name of 1 to 64 characters: lower case letters, digits, underscore and hyphen.',
            ReportAnalysisHandler::BODY_BLANK => 'A proposal body must not be blank.',
            ReportAnalysisHandler::SAVING_TOO_LONG => \sprintf('An estimated saving must be at most %d characters.', Proposal::MAX_ESTIMATED_SAVING_LENGTH),
            UpdateAnalyticsSettingsHandler::INVALID_MODEL => 'A model is one word of at most 64 characters, such as sonnet or opus.',
            UpdateAnalyticsSettingsHandler::INVALID_EFFORT => \sprintf('Use one of: %s.', implode(', ', WorkRequest::EFFORTS)),
            UpdateAnalyticsSettingsHandler::INVALID_SUBCOMMAND_PROGRAMS => 'A program name is 1 to 40 characters of letters, digits and . _ + -.',
            UpdateAnalyticsSettingsHandler::TOO_MANY_SUBCOMMAND_PROGRAMS => \sprintf('Pass at most %d programs.', ToolCallCollectionSettings::MAX_PROJECT_PROGRAMS),
            BucketRuleWriter::PATTERN_BLANK => 'A pattern must not be blank.',
            BucketRuleWriter::PATTERN_TOO_LONG => \sprintf('A pattern must be at most %d characters.', InsightsBucketRule::MAX_PATTERN_LENGTH),
            BucketRuleWriter::BUCKET_INVALID => \sprintf('A bucket name is 1 to %d characters: lower case letters, digits, underscore and hyphen.', InsightsBucketRule::MAX_BUCKET_LENGTH),
            BucketRuleWriter::LIMIT_REACHED => \sprintf('A project holds at most %d rules. Delete a rule with bucket_rule_delete first.', InsightsBucketRule::MAX_PER_PROJECT),
            SavedGraphSettingsResolver::CHART_LOCKED_PREFIX.'needs_several_lines' => 'This chart type needs several lines to add up. Group the graph first, or pick another chart type.',
            SavedGraphSettingsResolver::CHART_LOCKED_PREFIX.'statistic_does_not_add' => 'This chart type needs a sum, a count or a mean of a metric that adds up. Change the statistic, or pick another chart type.',
            SavedGraphSettingsResolver::CHART_LOCKED_PREFIX.'rate_does_not_add' => 'The rates of several lines never add up to a whole. Pick another chart type.',
            default => self::UNMAPPED,
        };
    }
}
