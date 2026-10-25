<?php

declare(strict_types=1);

namespace App\Module\Notification\Command\Console;

use Minishlink\WebPush\VAPID;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:notifications:generate-keys',
    description: 'Print a new VAPID key pair for web push. Stores nothing.',
)]
final class GenerateVapidKeysCommand extends Command
{
    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $keys = VAPID::createVapidKeys();

        $output->writeln('VAPID_PUBLIC_KEY='.$keys['publicKey']);
        $output->writeln('VAPID_PRIVATE_KEY='.$keys['privateKey']);

        return Command::SUCCESS;
    }
}
