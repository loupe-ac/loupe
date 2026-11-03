<?php

declare(strict_types=1);

namespace App\Module\OAuth\Command\Console;

use App\Module\OAuth\Command\SendPendingApiKeyCreatedEmailsCommand;
use App\Module\OAuth\Command\SendPendingApiKeyCreatedEmailsHandler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** The manual backstop for the hourly retry sweep. */
#[AsCommand(
    name: 'app:oauth:send-pending-api-key-created-emails',
    description: 'Mail the owners of API keys whose created mail never reached the queue.',
)]
final class SendPendingApiKeyCreatedEmailsConsoleCommand extends Command
{
    public function __construct(
        private readonly SendPendingApiKeyCreatedEmailsHandler $sendEmails,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $sent = ($this->sendEmails)(new SendPendingApiKeyCreatedEmailsCommand());

        new SymfonyStyle($input, $output)->success(\sprintf('Queued %d API key created mail(s).', $sent));

        return Command::SUCCESS;
    }
}
