<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Board\Entity\PullRequestNotice;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Entity\PullRequestState;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Translation\TranslatorInterface;

/** The Markdown a stale approval notice posts on the pull request. */
final readonly class StaleApprovalNoticeBody implements PullRequestNoticeBody
{
    private const string KEY_PREFIX = 'stale-approval:';

    public function __construct(
        private TranslatorInterface $translator,

        #[Autowire(param: 'kernel.default_locale')]
        private string $locale,
    ) {
    }

    public static function key(string $headSha): string
    {
        return self::KEY_PREFIX.$headSha;
    }

    #[\Override]
    public function supports(string $noticeKey): bool
    {
        return str_starts_with($noticeKey, self::KEY_PREFIX);
    }

    /** Delivery is async, so a new head, a new approval or a merge can outdate the notice before it posts. */
    #[\Override]
    public function stillHolds(PullRequestNotice $notice, ForgePullRequest $pullRequest): bool
    {
        return PullRequestState::Open === $pullRequest->state
            && null !== $pullRequest->headSha
            && self::key($pullRequest->headSha) === $notice->noticeKey
            && $pullRequest->uncoveredSha === $pullRequest->headSha
            && $pullRequest->approvalIsStale();
    }

    #[\Override]
    public function of(PullRequestNotice $notice): string
    {
        if (!$this->supports($notice->noticeKey)) {
            throw new \LogicException('Not a stale approval notice: '.$notice->noticeKey);
        }
        $sha = substr($notice->noticeKey, \strlen(self::KEY_PREFIX), 7);

        return $this->translator->trans('board.stale_approval_notice.body', ['%sha%' => $sha], 'messages', $this->locale);
    }
}
