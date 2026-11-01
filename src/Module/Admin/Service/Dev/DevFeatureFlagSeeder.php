<?php

declare(strict_types=1);

namespace App\Module\Admin\Service\Dev;

use App\DevSeed\DevSeederInterface;
use App\DevSeed\SeedContext;
use App\Module\Artifact\Install\ArtifactInstallFlags;
use App\Module\Inbox\Install\InboxInstallFlags;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\When;
use Ubermuda\FeatureFlagsBundle\Entity\FeatureFlag;
use Ubermuda\FeatureFlagsBundle\Enum\FeatureFlagType;
use Ubermuda\FeatureFlagsBundle\Repository\FeatureFlagRepository;

/**
 * Switches on the flags that hide a seeded page. The tag records that the seed
 * acted on a row, so a later switch-off by a person stays.
 */
#[When('dev')]
#[When('test')]
final readonly class DevFeatureFlagSeeder implements DevSeederInterface
{
    public const string KEY = 'feature-flags';
    public const string TAG = 'dev-seed';

    public const array FLAGS = [
        InboxInstallFlags::FLAG_INBOX_ENABLED,
        ArtifactInstallFlags::FLAG_ENABLED,
    ];

    public function __construct(
        private FeatureFlagRepository $featureFlags,
        private EntityManagerInterface $em,
    ) {
    }

    #[\Override]
    public function key(): string
    {
        return self::KEY;
    }

    #[\Override]
    public function dependsOn(): array
    {
        return [];
    }

    #[\Override]
    public function seed(SeedContext $context): void
    {
        if ($context->keepFlagDefaults) {
            return;
        }

        foreach (self::FLAGS as $name) {
            $flag = $this->featureFlags->findOneBy(['name' => $name]);
            if (null === $flag) {
                $flag = new FeatureFlag($name, FeatureFlagType::Bool, true);
                $flag->tags = [self::TAG];
                $this->em->persist($flag);
            } elseif (!\in_array(self::TAG, $flag->tags, true)) {
                $flag->type = FeatureFlagType::Bool;
                $flag->value = true;
                $flag->tags = [...$flag->tags, self::TAG];
            }
        }
        $this->em->flush();
    }
}
