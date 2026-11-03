<?php

declare(strict_types=1);

namespace App\Module\OAuth\Command\Console;

use App\Module\OAuth\Command\SendApiKeyExpiryNoticesCommand;
use App\Module\OAuth\Command\SendApiKeyExpiryNoticesHandler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** The manual backstop for the hourly notice sweep. */
#[AsCommand(
    name: 'app:oauth:send-api-key-expiry-notices',
    description: 'Mail the owners of API keys that end within 7 days.',
)]
final class SendApiKeyExpiryNoticesConsoleCommand extends Command
{
    public function __construct(
        private readonly SendApiKeyExpiryNoticesHandler $sendNotices,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $sent = ($this->sendNotices)(new SendApiKeyExpiryNoticesCommand());

        new SymfonyStyle($input, $output)->success(\sprintf('Queued %d API key expiry notice(s).', $sent));

        return Command::SUCCESS;
    }
}
