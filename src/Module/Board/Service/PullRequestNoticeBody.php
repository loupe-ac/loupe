<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Board\Entity\PullRequestNotice;
use App\Module\Forge\Entity\ForgePullRequest;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/** The Markdown of one kind of pull request notice, picked by the key of the notice. A module implements it for its own notices. */
#[AutoconfigureTag('app.pull_request_notice_body')]
interface PullRequestNoticeBody
{
    public function supports(string $noticeKey): bool;

    /** Delivery is async, so the pull request or the subject of the notice can change before it posts. */
    public function stillHolds(PullRequestNotice $notice, ForgePullRequest $pullRequest): bool;

    /** The text to post, without the hidden marker that the delivery adds. */
    public function of(PullRequestNotice $notice): string;
}
