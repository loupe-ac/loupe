<?php

declare(strict_types=1);

namespace App\Command;

use App\Module\Account\Entity\User;
use App\Module\Account\Repository\UserRepository;
use App\Module\Artifact\Service\Dev\DevArtifactSeeder;
use App\Module\Inbox\Service\Dev\DevCodexRunSeeder;
use App\Module\Inbox\Service\Dev\DevExperimentSeeder;
use App\Module\Inbox\Service\Dev\DevMetricsCardSeeder;
use App\Module\Insights\Service\Dev\DevAnalysesSeeder;
use App\Module\Insights\Service\Dev\DevMetricsSeeder;
use App\Module\Project\Command\EnsureHarnessProjectCommand;
use App\Module\Project\Command\EnsureHarnessProjectHandler;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Populates an empty development database with the minimum needed to log in
 * and look at the app.
 *
 * This exists for per-worktree databases, which start empty: without a user
 * there is nothing to see. The annotation widget names this project rather than
 * carrying a credential, so the command prints the project id for you to copy
 * into SITE_REVIEW_WIDGET_PROJECT.
 *
 * Lives in the root namespace rather than a module because it spans two of
 * them (Account and Project), and modules must not depend on each other.
 */
#[AsCommand(
    name: 'app:dev:seed',
    description: 'Seed an empty dev database with a verified user and a project.',
)]
#[When('dev')]
final class SeedDevDataCommand extends Command
{
    private const string EMAIL = 'dev@loupe.test';
    private const string ADMIN_EMAIL = 'admin@loupe.test';
    private const string PASSWORD = 'password';
    private const string PROJECT_NAME = 'Dev Project';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
        private readonly EnsureHarnessProjectHandler $ensureProject,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly DevExperimentSeeder $experiment,
        private readonly DevCodexRunSeeder $codexRuns,
        private readonly DevAnalysesSeeder $analyses,
        private readonly DevMetricsSeeder $metrics,
        private readonly DevMetricsCardSeeder $metricsCards,
        private readonly DevArtifactSeeder $artifacts,

        #[Autowire(param: 'app.terms.version')]
        private readonly string $termsVersion,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Idempotent: bootstrap re-runs on every worktree entry, and re-seeding
        // must not create a second user.
        $user = $this->seedUser(self::EMAIL, 'Dev User');

        // A second account rather than ROLE_ADMIN on the first: toggling one
        // user's role to compare the admin and member views is what makes a dev
        // database drift, and it costs nothing to keep both logged in at once.
        $this->seedUser(self::ADMIN_EMAIL, 'Admin User', ['ROLE_ADMIN']);

        $project = ($this->ensureProject)(new EnsureHarnessProjectCommand($user, self::PROJECT_NAME));
        $this->experiment->seed($project);
        // The goal panel has three states and two problems. The merge rate of the two models overlaps, and the template has no pull request history to give hours to merge.
        $this->experiment->seed($project, 'implement-merge-rate', ['goal' => ['metric' => 'merge-rate', 'better' => 'higher']]);
        $this->experiment->seed($project, 'implement-merge-time', ['goal' => ['metric' => 'hours-to-merge', 'better' => 'lower']]);
        $this->experiment->seed($project, 'implement-goal-typo', ['goal' => ['metric' => 'hours-to-mrege', 'better' => 'lower']]);
        $this->experiment->seed($project, 'implement-no-direction', ['goal' => ['metric' => 'hours-to-merge']]);
        // The Simple template declares no fix-rounds: a declared fix-rounds guard is unknown and shows a note, and a default one drops with none.
        $known = ['cost', 'merge-rate', 'duration', 'input-tokens'];
        $this->experiment->seed($project, 'implement-unknown-guard', ['guards' => ['merge-rate', 'fix-rounds']], $known);
        $this->experiment->seed($project, 'implement-simple-default', null, $known);
        // The template metrics in the Comparison table, with the pull request history that hours to merge reads.
        $this->experiment->seed($project, 'implement-template-metrics', ['goal' => ['metric' => 'hours-to-merge', 'better' => 'lower']], ['cost', 'merge-rate', 'hours-to-merge']);
        $this->codexRuns->seed($project);
        $this->analyses->seed($project, DevExperimentSeeder::EXPERIMENT);
        // After the analyses, whose bucket rules sort the tool time of the demo runs.
        $this->metrics->seed($project, fn (\DateTimeImmutable $from, \DateTimeImmutable $to): array => $this->metricsCards->cardFor($project, $from, $to));
        $this->artifacts->seed($project);

        // Printed for a human to copy into SITE_REVIEW_WIDGET_PROJECT. No
        // caller parses it, so the prefix is for readers only.
        $output->writeln('SITE_REVIEW_WIDGET_PROJECT='.$project->id);

        $io->success(sprintf(
            'Seeded %s and %s (password: %s).',
            self::EMAIL,
            self::ADMIN_EMAIL,
            self::PASSWORD,
        ));

        return Command::SUCCESS;
    }

    /**
     * Idempotent: bootstrap re-runs on every worktree entry, so a second call
     * must find the existing row rather than collide with its unique email.
     *
     * @param non-empty-string $email
     * @param list<string>     $roles
     */
    private function seedUser(string $email, string $fullName, array $roles = []): User
    {
        $user = $this->users->findOneBy(['email' => $email]);
        if ($user instanceof User) {
            // Re-apply the roles rather than trusting the row: an account
            // demoted while testing would otherwise keep being reported as
            // seeded while the documented credentials could not reach /admin.
            // Appended, not assigned — the row may carry roles of its own.
            $missing = array_diff($roles, $user->roles);
            if ([] !== $missing) {
                $user->roles = array_values(array_unique([...$user->roles, ...$roles]));
                $this->em->flush();
            }

            return $user;
        }

        $user = new User(fullName: $fullName, email: $email);
        $user->password = $this->passwordHasher->hashPassword($user, self::PASSWORD);
        $user->roles = $roles;
        // Verified up front — there is no inbox to click through, and an
        // unverified user cannot reach the pages worth looking at.
        $user->emailVerifiedAt = new \DateTimeImmutable();
        // Same reasoning as the verification above: a seeded login exists to
        // land on the app, not on an acceptance interstitial.
        $user->termsAcceptedAt = new \DateTimeImmutable();
        $user->termsVersion = $this->termsVersion;
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
