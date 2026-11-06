<?php

declare(strict_types=1);

namespace App\Tests\Routing\Fixture;

#[ExtendsMissingParent]
final class MissingParentController
{
    public function __invoke(): void
    {
    }
}
