<?php

declare(strict_types=1);

namespace App\Tests\Utils;

final readonly class RowStub
{
    public function __construct(
        public string $id,
    ) {
    }
}
