<?php

declare(strict_types=1);

namespace App\Tests\Module\Admin\Service\Dev;

use App\Module\Admin\Service\Dev\DevFeatureFlagSeeder;
use App\Tests\DevSeed\SeedContextFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Ubermuda\FeatureFlagsBundle\Entity\FeatureFlag;
use Ubermuda\FeatureFlagsBundle\Enum\FeatureFlagType;
use Ubermuda\FeatureFlagsBundle\Repository\FeatureFlagRepository;

final class DevFeatureFlagSeederTest extends KernelTestCase
{
    private const string FLAG = 'inbox.enabled';

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function test_it_creates_a_missing_row_on_with_the_tag(): void
    {
        $this->setRow(null);
        $this->runSeeder();

        $flag = $this->flag();
        self::assertTrue($flag->value);
        self::assertSame(['dev-seed'], $flag->tags);
    }

    public function test_it_turns_on_an_untagged_row_and_adds_the_tag(): void
    {
        $this->setRow(new FeatureFlag(self::FLAG, FeatureFlagType::Bool, false));
        $this->runSeeder();

        $flag = $this->flag();
        self::assertTrue($flag->value);
        self::assertSame(['dev-seed'], $flag->tags);
    }

    public function test_it_keeps_the_other_tags_of_an_untagged_row(): void
    {
        $row = new FeatureFlag(self::FLAG, FeatureFlagType::Bool, false);
        $row->tags = ['beta'];
        $this->setRow($row);
        $this->runSeeder();

        self::assertSame(['beta', 'dev-seed'], $this->flag()->tags);
    }

    public function test_it_leaves_a_tagged_row_a_person_switched_off(): void
    {
        $row = new FeatureFlag(self::FLAG, FeatureFlagType::Bool, false);
        $row->tags = ['dev-seed'];
        $this->setRow($row);
        $this->runSeeder();

        self::assertFalse($this->flag()->value);
    }

    public function test_it_writes_nothing_when_the_context_keeps_the_defaults(): void
    {
        $this->setRow(null);
        $this->runSeeder(keepFlagDefaults: true);

        self::assertNull($this->repository()->findOneBy(['name' => self::FLAG]));
    }

    private function runSeeder(bool $keepFlagDefaults = false): void
    {
        self::getContainer()->get(DevFeatureFlagSeeder::class)->seed(SeedContextFactory::create(self::getContainer(), $keepFlagDefaults));
        $this->em()->clear();
    }

    private function setRow(?FeatureFlag $row): void
    {
        $existing = $this->repository()->findOneBy(['name' => self::FLAG]);
        if (null !== $existing) {
            $this->em()->remove($existing);
            $this->em()->flush();
        }
        if (null !== $row) {
            $this->em()->persist($row);
            $this->em()->flush();
        }
        $this->em()->clear();
    }

    private function flag(): FeatureFlag
    {
        return $this->repository()->findOneBy(['name' => self::FLAG]) ?? self::fail('The flag row is missing.');
    }

    private function repository(): FeatureFlagRepository
    {
        return self::getContainer()->get(FeatureFlagRepository::class);
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
