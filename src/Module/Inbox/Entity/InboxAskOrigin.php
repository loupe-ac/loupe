<?php

declare(strict_types=1);

namespace App\Module\Inbox\Entity;

enum InboxAskOrigin: string
{
    case Agent = 'agent';
    case Loupe = 'loupe';
    /** A caller of the public API with an API key. The item names the key. */
    case ApiKey = 'api-key';
}
