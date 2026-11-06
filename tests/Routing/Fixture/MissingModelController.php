<?php

declare(strict_types=1);

namespace App\Tests\Routing\Fixture;

#[NeedsMissingClass]
final class MissingModelController
{
    public function __invoke(): void
    {
    }
}
