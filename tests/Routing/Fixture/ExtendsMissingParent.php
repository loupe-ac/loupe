<?php

declare(strict_types=1);

namespace App\Tests\Routing\Fixture;

// The parent class does not exist, as when a dev package is left out of the image.
#[\Attribute]
final class ExtendsMissingParent extends Absent\MissingBase
{
}
