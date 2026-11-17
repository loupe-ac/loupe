<?php

declare(strict_types=1);

namespace App\Module\Account\Command;

use App\Exception\DomainErrors;
use App\Module\Account\Service\InstallAccessGuard;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;

final readonly class UnlockInstallHandler
{
    public function __construct(
        private InstallAccessGuard $installAccessGuard,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(UnlockInstallCommand $command): void
    {
        if (!$this->installAccessGuard->unlock($command->session, $command->token)) {
            $this->auditor->record('account.install_unlock', AuditOutcome::Refused);

            throw new DomainErrors(['token' => 'account.install.unlock.error.invalid']);
        }

        $this->auditor->record('account.install_unlock', AuditOutcome::Success);
    }
}
