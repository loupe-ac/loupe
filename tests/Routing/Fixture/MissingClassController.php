<?php

declare(strict_types=1);

namespace App\Tests\Routing\Fixture;

#[Absent\NotInstalled]
final class MissingClassController
{
    public function __invoke(): void
    {
    }
}
