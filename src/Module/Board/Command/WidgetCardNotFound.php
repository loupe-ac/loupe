<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

/** Thrown when the card is not in the project, or the widget did not raise it. */
final class WidgetCardNotFound extends \DomainException
{
}
