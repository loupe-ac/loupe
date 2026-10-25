<?php

declare(strict_types=1);

namespace App\Module\Inbox\Messenger;

/** Asks for a push to the owner of a project about a new ask or notice. */
final readonly class NotifyInboxOwner
{
    public function __construct(
        /** An ask id or an item id, as the kind says. RFC 4122. */
        public string $subjectId,
        public NotifyInboxOwnerKind $kind,
    ) {
    }
}
