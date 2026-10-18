<?php

declare(strict_types=1);

namespace App\Module\GitHub\Form;

class ChangeGitIdentityManagedRequest
{
    public function __construct(
        public bool $managed = true,
    ) {
    }
}
