<?php

declare(strict_types=1);

namespace App\Tests\Routing\Fixture;

#[FineAttribute]
final class GoodController
{
    public function __invoke(): void
    {
    }
}
