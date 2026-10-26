<?php

declare(strict_types=1);

namespace App\Module\Inbox\Service;

use App\Module\Board\Entity\CardPause;
use App\Module\Board\Repository\CardPauseRepository;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Mcp\WorkerRunPayload;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Forge\Repository\ForgePullRequestRepository;
use App\Module\Inbox\Entity\InboxCardWait;
use App\Module\Inbox\Entity\InboxCardWaitType;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\View\CardWaitLine;
use App\Module\Review\Repository\DocumentRepository;
use App\Module\Workflow\Contract\PauseKind;
use App\Module\Workflow\Contract\PauseReasonText;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Builds the translation key, the values and the link for one wait. The key is the label of the marker, and the values are its params. */
final readonly class CardWaitText
{
    /** The label that has a variant with the headline of the run output. */
    private const string RUN_LABEL = 'marker.run_needs_you';

    /** The label of a pause, which has variants that name the reason in words and the run that failed. */
    private const string PAUSE_LABEL = 'marker.paused';

    public function __construct(
        private DocumentRepository $documents,
        private ForgePullRequestRepository $forgePullRequests,
        private WorkerRunRepository $workerRuns,
        private CardPauseRepository $cardPauses,
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
        private PauseReasonText $pauseReasonText,

        #[Autowire(param: 'kernel.default_locale')]
        private string $locale,
    ) {
    }

    /** A stop pause of the card is the action of the run line that caused it, so the page shows it once. */
    public function isFolded(InboxCardWait $wait): bool
    {
        if (null !== $wait->endedAt || InboxCardWaitType::CardPause !== $wait->type || null === $wait->pauseId) {
            return false;
        }
        $kind = $this->cardPauses->find($wait->pauseId)?->kind;

        return \in_array($kind, [PauseKind::WorkStopped, PauseKind::Retries], true)
            && null !== self::openRunWait(array_values($wait->watch->waits->toArray()));
    }

    /**
     * The title of the item: the reason of the wait that matters most, or the card title when no wait is open.
     * An open review wait matters most, so the title names the review that makes the item a Review.
     * The inbox row and the linked panel name the card, so the title leaves the card out.
     *
     * @param list<InboxCardWait> $open
     */
    public function title(array $open, string $cardTitle, int $maxLength): string
    {
        $main = $this->mainWait($open);
        $runTitle = null === $main ? null : $this->runTitle($main);
        if (null !== $runTitle) {
            return mb_substr($runTitle, 0, $maxLength);
        }
        $line = null === $main ? null : $this->line($main, false);
        $reason = null === $line ? '' : $this->translator->trans($line->titleKey(), $line->params, null, $this->locale);

        return mb_substr('' === $reason ? $cardTitle : $reason, 0, $maxLength);
    }

    /** The title that the worker of a run wait sent in its result, or null. */
    private function runTitle(InboxCardWait $wait): ?string
    {
        if (InboxCardWaitType::WorkerRun !== $wait->type || null === $wait->runId) {
            return null;
        }
        $run = $this->workerRuns->findOneByIdAndProjectId($wait->runId->toRfc4122(), (string) $wait->watch->project->id);

        return null === $run ? null : RunSuggestions::title($run);
    }

    /**
     * One sentence for each wait the page shows, with its action.
     *
     * @param list<InboxCardWait> $open
     */
    public function body(array $open): string
    {
        $lines = [];
        foreach ($open as $wait) {
            if ($this->isFolded($wait)) {
                continue;
            }
            $line = $this->line($wait, false);
            $lines[] = rtrim($this->translator->trans($line->key, $line->params, null, $this->locale), '.').'. '.$this->translator->trans($line->actionKey(), $line->params, null, $this->locale).'.';
        }

        return implode("\n", $lines);
    }

    /**
     * The first open wait about a document or a pull request.
     *
     * @param list<InboxCardWait> $open
     */
    public function reviewWait(array $open): ?InboxCardWait
    {
        foreach ($open as $wait) {
            if (null === $wait->endedAt && \in_array($wait->type, [InboxCardWaitType::Document, InboxCardWaitType::PullRequest], true)) {
                return $wait;
            }
        }

        return null;
    }

    /**
     * The item is a Review while a review wait is open, and Waiting otherwise.
     *
     * @param list<InboxCardWait> $open
     */
    public function kind(array $open): InboxItemKind
    {
        return null === $this->reviewWait($open) ? InboxItemKind::Wait : InboxItemKind::Review;
    }

    /**
     * @param list<InboxCardWait> $open
     */
    private function mainWait(array $open): ?InboxCardWait
    {
        return $this->reviewWait($open) ?? self::openRunWait($open) ?? array_first(array_filter($open, fn (InboxCardWait $wait): bool => !$this->isFolded($wait))) ?? null;
    }

    /** @param list<InboxCardWait> $waits */
    private static function openRunWait(array $waits): ?InboxCardWait
    {
        foreach ($waits as $wait) {
            if (null === $wait->endedAt && InboxCardWaitType::WorkerRun === $wait->type) {
                return $wait;
            }
        }

        return null;
    }

    public function line(InboxCardWait $wait, bool $documentLinked): CardWaitLine
    {
        $projectId = $wait->watch->project->id;
        $params = ['%card%' => $wait->watch->cardNumber];
        $href = null;
        $detail = null;
        $key = '' === $wait->label ? 'inbox.wait.fallback.'.self::typePart($wait->type) : $wait->label;
        $titleKey = null;
        $pageKey = null;
        $action = 'inbox.wait.action.'.self::typePart($wait->type);

        switch ($wait->type) {
            case InboxCardWaitType::Document:
                $document = null === $wait->documentId ? null : $this->documents->find($wait->documentId);
                $params['%title%'] = $document->title ?? '';
                $params['%version%'] = $wait->versionNumber ?? 0;
                if (null !== $wait->documentId && null === $document) {
                    $key = 'review.inbox_wait.document_deleted';
                    $titleKey = $key.'.title';
                    $action = $key.'.action';
                    break;
                }
                if (null !== $wait->documentId) {
                    $titleKey = 'inbox.wait.review_title.document';
                }
                if ($documentLinked && null !== $wait->documentId) {
                    $href = $this->urls->generate('app_document_review', ['projectId' => $projectId, 'documentId' => $wait->documentId]);
                }
                break;
            case InboxCardWaitType::PullRequest:
                $pullRequest = null === $wait->pullRequestId ? null : $this->forgePullRequests->find($wait->pullRequestId);
                $params['%number%'] = $pullRequest->number ?? 0;
                $params['%pr_title%'] = $pullRequest->title ?? '';
                $href = null === $pullRequest ? null : \sprintf('https://github.com/%s/pull/%d', $pullRequest->repository, $pullRequest->number);
                break;
            case InboxCardWaitType::WorkerRun:
                $run = null === $wait->runId ? null : $this->workerRuns->findOneByIdAndProjectId($wait->runId->toRfc4122(), (string) $projectId);
                $href = $this->urls->generate('app_board_card', ['projectId' => $projectId, 'cardId' => $wait->watch->cardId]);
                $detail = null === $run ? null : RunOutputText::clean($run->output);
                $params = [...$params, ...RunOutputText::params($run->workKind ?? null, $run->output ?? '')];
                break;
            case InboxCardWaitType::CardPause:
            case InboxCardWaitType::Card:
                $href = $this->urls->generate('app_board_card', ['projectId' => $projectId, 'cardId' => $wait->watch->cardId]);
                break;
        }
        $params = [...$params, ...$wait->params];
        if (InboxCardWaitType::CardPause === $wait->type && self::PAUSE_LABEL === $key) {
            [$key, $params, $detail] = $this->pauseLine($wait, $params);
            $titleKey = self::PAUSE_LABEL.'.reason.title';
        }
        if (InboxCardWaitType::PullRequest === $wait->type) {
            $titleKey = '' === ($params['%pr_title%'] ?? '') ? 'inbox.wait.review_title.pull_request_untitled' : 'inbox.wait.review_title.pull_request';
        }

        if (InboxCardWaitType::WorkerRun === $wait->type && self::RUN_LABEL === $key && '' !== ($params['%headline%'] ?? '')) {
            $pageKey = $key.'.short';
            $key .= '.headline';
            $titleKey = $key.'.title';
        }

        return new CardWaitLine($key, $params, $href, '' === $detail ? null : $detail, $titleKey ?? $key, $action, $pageKey);
    }

    /**
     * The reason of a pause in words and, for a pause that failed work caused, the run that failed last before it.
     *
     * @param array<string, string|int> $params
     *
     * @return array{string, array<string, string|int>, ?string}
     */
    private function pauseLine(InboxCardWait $wait, array $params): array
    {
        $pause = null === $wait->pauseId ? null : $this->cardPauses->find($wait->pauseId);
        $code = $pause->reason ?? (string) ($params['%reason%'] ?? '');
        $reason = '' === $code ? '' : $this->pauseReasonText->forPause($code);
        if ($reason === $code) {
            $reason = $this->translator->trans('inbox.wait.pause_code', ['%code%' => $code], null, $this->locale);
        }
        $params['%reason%'] = rtrim($reason, '.');

        $run = null === $pause ? null : $this->failedRun($wait, $pause);
        if (null === $run) {
            return [self::PAUSE_LABEL.'.reason', $params, null];
        }
        $params = [...$params, ...RunOutputText::params($run->workKind, '')];
        unset($params['%headline%']);
        $params['%failure%'] = rtrim(WorkerRunPayload::reasonOf($run) ?? $run->state->value, '.');
        $href = $this->urls->generate('app_project_worker_runs', ['id' => $wait->watch->project->id, 'search' => $run->id?->toRfc4122()]);
        $link = \sprintf('[%s](%s)', $this->translator->trans('inbox.wait.pause_run_link', [], null, $this->locale), $href);

        return [self::PAUSE_LABEL.'.run', $params, $link];
    }

    /** The run that failed last before the pause, for a pause that a failure of work caused. */
    private function failedRun(InboxCardWait $wait, CardPause $pause): ?WorkerRun
    {
        if (!\in_array($pause->kind, [PauseKind::Retries, PauseKind::WorkStopped], true)) {
            return null;
        }

        return $this->workerRuns->findLatestFailedOfCard($wait->watch->project, $wait->watch->cardId, $pause->createdAt, $pause->ruleId);
    }

    private static function typePart(InboxCardWaitType $type): string
    {
        return str_replace('-', '_', $type->value);
    }
}
