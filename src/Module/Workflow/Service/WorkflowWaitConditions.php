<?php

declare(strict_types=1);

namespace App\Module\Workflow\Service;

use App\Module\Workflow\Condition\Conditions;
use App\Module\Workflow\Contract\CardDirectory;
use App\Module\Workflow\Contract\EngineFact;
use App\Module\Workflow\Contract\Facts;
use App\Module\Workflow\Contract\PullRequestFacts;
use App\Module\Workflow\Contract\PullRequestState;
use App\Module\Workflow\Contract\PullRequestUrls;
use App\Module\Workflow\Contract\WaitConditionCheck;
use App\Module\Workflow\Contract\WaitConditionRefusal;
use App\Module\Workflow\Contract\WaitConditions;
use App\Module\Workflow\Contract\WaitSettings;
use App\Module\Workflow\Expression\Expression;
use App\Module\Workflow\Template\Template;
use App\Module\Workflow\Template\TemplateMarker;
use App\Module\Workflow\Template\TemplateParser;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Uid\Uuid;

#[AsAlias(WaitConditions::class)]
final readonly class WorkflowWaitConditions implements WaitConditions
{
    public function __construct(
        private CardDirectory $cards,
        private ProjectTemplates $templates,
        private Conditions $conditions,
        private TemplateParser $parser,
        private FactsBuilder $factsBuilder,
        private PullRequestUrls $pullRequestUrls,
        private ClockInterface $clock,
    ) {
    }

    #[\Override]
    public function settingsOf(Uuid $projectId): WaitSettings
    {
        return $this->templates->of($projectId)->waits;
    }

    #[\Override]
    public function check(Uuid $cardId, string $key, array $params, ?string $pullRequestUrl): WaitConditionCheck
    {
        $card = $this->cards->find($cardId) ?? throw new \LogicException('A wait names a stored card.');
        $template = $this->templates->of($card->projectId);

        $expression = $this->expressionOf($template, $key, $params);
        if ($expression instanceof WaitConditionCheck) {
            return $expression;
        }

        $facts = $this->factsBuilder->build($card, $this->clock->now());
        if (null !== $expression->unreadable($facts)) {
            return new WaitConditionCheck(WaitConditionRefusal::Unreadable, \sprintf('Loupe cannot read the condition "%s" now. Try again later.', $key), params: $params);
        }

        $bound = null;
        if (array_any($expression->reads(), static fn (EngineFact|string $read): bool => $read instanceof EngineFact && $read->bindsPullRequest())) {
            $bound = $this->bind($facts, $key, $params, $pullRequestUrl);
            if ($bound instanceof WaitConditionCheck) {
                return $bound;
            }
        }

        return new WaitConditionCheck(
            isTrue: $expression->evaluate(null === $bound ? $facts : $facts->withPullRequest($bound)),
            pullRequestId: $bound?->id,
            pullRequestToken: null === $bound ? null : self::tokenOf($bound),
            params: $params,
        );
    }

    /** @param array<string, mixed> $params */
    private function expressionOf(Template $template, string $key, array $params): Expression|WaitConditionCheck
    {
        $marker = array_find($template->markers, static fn (TemplateMarker $marker): bool => $marker->key === $key);
        if (null !== $marker) {
            if ([] !== $params) {
                return new WaitConditionCheck(WaitConditionRefusal::InvalidParameters, \sprintf('The marker "%s" takes no parameters.', $key), params: $params);
            }

            return $marker->when;
        }

        if (!$this->conditions->has($key)) {
            return new WaitConditionCheck(WaitConditionRefusal::UnknownCondition, \sprintf('"%s" is not a condition or a marker of the workflow.', $key), params: $params);
        }

        $errors = [];
        $leaf = $this->parser->conditionOf($template, $key, $params, $errors);

        return $leaf ?? new WaitConditionCheck(WaitConditionRefusal::InvalidParameters, implode('; ', $errors), params: $params);
    }

    /** @param array<string, mixed> $params */
    private function bind(Facts $facts, string $key, array $params, ?string $url): PullRequestFacts|WaitConditionCheck
    {
        $open = array_values(array_filter($facts->pullRequests(), static fn (PullRequestFacts $pullRequest): bool => PullRequestState::Open === $pullRequest->state));

        if (null === $url) {
            if (1 === \count($open)) {
                return $open[0];
            }

            return new WaitConditionCheck(
                WaitConditionRefusal::PullRequestRequired,
                [] === $open
                    ? \sprintf('The condition "%s" reads a pull request, and the card links no open pull request.', $key)
                    : \sprintf('The condition "%s" reads a pull request, and the card links %d open pull requests. Name one by its URL: %s.', $key, \count($open), implode(', ', array_map(self::tokenOf(...), $open))),
                params: $params,
            );
        }

        $named = $this->pullRequestUrls->keyOf($url);
        $linked = null === $named ? null : array_find($facts->pullRequests(), static fn (PullRequestFacts $pullRequest): bool => $pullRequest->forge === $named->forge && null !== $pullRequest->repository && mb_strtolower($pullRequest->repository) === $named->repository && $pullRequest->number === $named->number);
        if (null === $linked) {
            return new WaitConditionCheck(WaitConditionRefusal::PullRequestNotLinked, \sprintf('The card does not link the pull request "%s".', $url), params: $params);
        }
        if (PullRequestState::Open !== $linked->state) {
            return new WaitConditionCheck(WaitConditionRefusal::PullRequestNotOpen, \sprintf('The pull request %s is not open.', self::tokenOf($linked)), params: $params);
        }

        return $linked;
    }

    private static function tokenOf(PullRequestFacts $pullRequest): string
    {
        return \sprintf('pr:%s#%d', $pullRequest->repository ?? '', $pullRequest->number ?? 0);
    }
}
