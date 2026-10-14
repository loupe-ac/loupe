<?php

declare(strict_types=1);

namespace App\Module\Inbox\Form;

use App\Module\Inbox\Entity\InboxAction;
use Symfony\Component\Form\AbstractType;

/**
 * A real form rather than a CSRF attribute, for the reason of MarkInboxItemDoneFormType: the owner
 * keeps the page open, so a stale token must show an error in place rather than a 403.
 *
 * @extends AbstractType<array<string, mixed>>
 */
class CancelInboxActionFormType extends AbstractType
{
    public static function nameFor(InboxAction $action): string
    {
        return 'inbox_action_cancel_'.$action->id;
    }
}
