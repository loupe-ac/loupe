<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Board\Entity\Card;

/** Queues the pull request comment that sums up an agent review. The AgentReview module implements it. */
interface AgentReviewComment
{
    /** Answers whether it stored a notice. */
    public function queue(Card $card): bool;
}
