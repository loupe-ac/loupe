<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

/** Reads a pull request URL. Board implements it. */
interface PullRequestUrls
{
    /** Null when the URL names no repository or no number, or its forge is not one the app knows. */
    public function keyOf(string $url): ?PullRequestKey;
}
