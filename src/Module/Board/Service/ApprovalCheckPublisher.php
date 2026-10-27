<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Forge\Entity\ForgePullRequest;

final class ApprovalCheckPublisher
{
    public const string NAME = ForgePullRequest::APPROVAL_CHECK_NAME;
}
