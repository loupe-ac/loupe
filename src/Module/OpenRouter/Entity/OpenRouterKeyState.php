<?php

declare(strict_types=1);

namespace App\Module\OpenRouter\Entity;

enum OpenRouterKeyState: string
{
    case Working = 'working';
    case Refused = 'refused';
}
