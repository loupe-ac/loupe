<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

use Symfony\Component\Uid\Uuid;

/** Lets a module test a condition of the workflow for a worker that wants to wait for it. */
interface WaitConditions
{
    /** What the template of the project allows a wait. */
    public function settingsOf(Uuid $projectId): WaitSettings;

    /**
     * Tests one condition of one card. The key is a condition of the registry or a marker key of the template.
     *
     * @param array<string, mixed> $params         the parameters the worker gave
     * @param ?string              $pullRequestUrl the pull request the worker names, which a condition that reads no pull request ignores
     */
    public function check(Uuid $cardId, string $key, array $params, ?string $pullRequestUrl): WaitConditionCheck;
}
