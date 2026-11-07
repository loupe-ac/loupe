<?php

declare(strict_types=1);

namespace App\Module\Insights\Form;

class ShareGraphRequest
{
    public function __construct(
        public bool $enabled = false,
    ) {
    }

    public static function fromState(bool $enabled): self
    {
        return new self($enabled);
    }
}
