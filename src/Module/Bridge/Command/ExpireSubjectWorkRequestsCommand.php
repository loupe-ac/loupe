<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

/** Expires every open request about a subject other than a card that waited too long, or that fails because no bridge can take it. It carries no data. */
final readonly class ExpireSubjectWorkRequestsCommand
{
}
