<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

/**
 * Thrown when the workflow cannot carry out the choice an agent stated for a child card. The message is fit for an agent.
 *
 * A refusal that the client can correct by changing its request carries a field code, which the public API answers as a field error.
 * A refusal with no field code comes from the workflow and stays a conflict.
 */
final class ChildDesignRefused extends \DomainException
{
    public const string REQUIRED = 'child_design_required';
    public const string NO_PARENT = 'child_design_no_parent';
    public const string NOT_DECLARED = 'child_design_not_declared';
    public const string WITH_STATUS = 'child_design_with_status';
    public const string NO_INHERIT = 'child_design_no_inherit';

    /** @param ?string $fieldCode one of the constants, or null for a refusal that the request cannot correct */
    public function __construct(
        string $message,
        public readonly ?string $fieldCode = null,
    ) {
        parent::__construct($message);
    }
}
