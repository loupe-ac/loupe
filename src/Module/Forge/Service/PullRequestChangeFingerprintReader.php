<?php

declare(strict_types=1);

namespace App\Module\Forge\Service;

use App\Module\Forge\Entity\ForgePullRequest;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/** Asks one forge for a fingerprint of the change of a pull request head. A forge module implements it. */
#[AutoconfigureTag('app.pull_request_change_fingerprint_reader')]
interface PullRequestChangeFingerprintReader
{
    /** @param string $forge the forge's slug, such as `github` */
    public function supports(string $forge): bool;

    /** Null when the forge cannot give the whole change. */
    public function read(ForgePullRequest $pullRequest, string $headSha): ?PullRequestChangeFingerprint;
}
