<?php

declare(strict_types=1);

namespace App\Module\Board\Controller\Api;

use Symfony\Component\Validator\Constraints as Assert;

/** The card a note creates. The note gives it its title. */
final class NewCardInput
{
    public function __construct(
        /** The column the new card starts in. Null starts it in Backlog. */
        #[Assert\Uuid]
        public ?string $startColumnId = null,
    ) {
    }
}
