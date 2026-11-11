<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

/** Where ReorderCardsHandler puts the block, among the cards of the column that are not in it. */
enum ReorderPlacement: string
{
    case Top = 'top';
    case Bottom = 'bottom';
    case Before = 'before';
    case After = 'after';
}
