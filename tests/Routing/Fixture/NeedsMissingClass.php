<?php

declare(strict_types=1);

namespace App\Tests\Routing\Fixture;

#[\Attribute]
final class NeedsMissingClass
{
    public function __construct()
    {
        new Absent\MissingModel();
    }
}
