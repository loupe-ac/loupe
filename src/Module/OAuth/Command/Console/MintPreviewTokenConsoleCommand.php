<?php

declare(strict_types=1);

namespace App\Module\OAuth\Command\Console;

use App\Exception\DomainErrors;
use App\Module\Account\Repository\UserRepository;
use App\Module\OAuth\Command\MintPreviewTokenCommand;
use App\Module\OAuth\Command\MintPreviewTokenHandler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\Uid\Uuid;

/**
 * Mints a preview token from this database, for a widget branch whose backend
 * is its own worktree. The CLI holds one sign-in, for one instance, so it
 * cannot mint here. #[When('dev')] keeps the command out of production.
 */
#[AsCommand(
    name: 'app:dev:preview-token',
    description: 'Print a preview token for a seeded account and project of this database',
)]
#[When('dev')]
final class MintPreviewTokenConsoleCommand extends Command
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly MintPreviewTokenHandler $mintPreviewToken,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addOption('project', null, InputOption::VALUE_REQUIRED, 'Id or slug of the project')
            ->addOption('label', null, InputOption::VALUE_REQUIRED, 'Name of the preview, such as the worktree name')
            ->addOption('card', null, InputOption::VALUE_REQUIRED, 'Id of a card of this database')
            ->addOption('email', null, InputOption::VALUE_REQUIRED, 'Seeded account that owns the project', 'dev@loupe.test');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $project = (string) $input->getOption('project');
        $label = (string) $input->getOption('label');
        $card = (string) $input->getOption('card');
        if ('' === $project || '' === $label) {
            $io->error('--project and --label are required.');

            return Command::INVALID;
        }
        if ('' !== $card && !Uuid::isValid($card)) {
            $io->error('--card is not a card id.');

            return Command::INVALID;
        }

        $owner = $this->users->findOneByEmail((string) $input->getOption('email'));
        if (null === $owner) {
            $io->error('No account has that email.');

            return Command::FAILURE;
        }

        try {
            $view = ($this->mintPreviewToken)(new MintPreviewTokenCommand($owner, $project, $label, '' !== $card ? Uuid::fromString($card) : null));
        } catch (DomainErrors $e) {
            foreach ($e->errors as $field => $translationKey) {
                $io->error($field.': '.$translationKey);
            }

            return Command::FAILURE;
        }

        $output->writeln($view->token);

        return Command::SUCCESS;
    }
}
