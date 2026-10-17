<?php

declare(strict_types=1);

namespace App\Module\AgentReview\Entity;

/** How a fix round answered one finding of a document review. */
enum DocumentReviewAnswer: string
{
    case Fixed = 'fixed';
    case NotChanged = 'not-changed';
    case ToOwner = 'to-owner';
}
