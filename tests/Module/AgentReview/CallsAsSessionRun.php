<?php

declare(strict_types=1);

namespace App\Tests\Module\AgentReview;

use Symfony\Component\Uid\Uuid;

/** For a test that uses DocumentReviewScenario, McpTokenScenario and AgentReviewScenario, and holds $this->project. */
trait CallsAsSessionRun
{
    /** Calls with the run credential of the newest run of the session. A null session sends the project credential with no run and no header. */
    private function callAsSessionRun(?Uuid $session): void
    {
        if (null === $session) {
            $this->actAsMcpTokenBoundTo($this->project);
            $this->sendHeader(null);

            return;
        }
        $this->callAsRun($this->runsBySession[(string) $session] ?? throw new \LogicException('No run was made for the session.'));
    }
}
