<?php

declare(strict_types=1);

namespace App\Module\Account\Entity;

enum WaitlistJoinSource: string
{
    case Form = 'form';
    case SocialLogin = 'social-login';
}
