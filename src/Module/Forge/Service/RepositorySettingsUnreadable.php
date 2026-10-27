<?php

declare(strict_types=1);

namespace App\Module\Forge\Service;

final class RepositorySettingsUnreadable extends \RuntimeException
{
    /** @param string $reason a short code for the log and the page, such as `no_installation` */
    public function __construct(
        public readonly string $reason,
        ?\Throwable $previous = null,
    ) {
        parent::__construct('The repository settings cannot be read: '.$reason, 0, $previous);
    }
}
