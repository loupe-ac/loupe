<?php

declare(strict_types=1);

namespace App\Module\Billing\Command\Console;

use App\Module\Billing\Command\SendDueBetaWelcomeEmailsCommand;
use App\Module\Billing\Command\SendDueBetaWelcomeEmailsHandler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Runs the welcome email retry once. The scheduler fires it every 15 minutes;
 * this command is the manual backstop and the dev and e2e seam. Safe to run any time.
 */
#[AsCommand(
    name: 'app:billing:send-due-beta-welcome-emails',
    description: 'Send the beta welcome email to every verified tester whose email still waits.',
)]
final class SendDueBetaWelcomeEmailsConsoleCommand extends Command
{
    public function __construct(
        private readonly SendDueBetaWelcomeEmailsHandler $sendDue,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $offered = ($this->sendDue)(new SendDueBetaWelcomeEmailsCommand());

        new SymfonyStyle($input, $output)->success(\sprintf('Checked %d due beta invite(s).', $offered));

        return Command::SUCCESS;
    }
}
