<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

/** A fact provider whose answer can change with no write the database sees, such as a reply of an outside service. */
interface OutsideFacts
{
    /**
     * @return string a SELECT of one column of card ids, with no parameters. The marked sweep adds these cards on every tick.
     */
    public function alwaysSweptCardsSql(): string;
}
