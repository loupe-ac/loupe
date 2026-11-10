<?php

declare(strict_types=1);

namespace App\Module\Account\Service\Dev;

use App\DevSeed\DevSeederInterface;
use App\DevSeed\DevSeedId;
use App\DevSeed\SeedContext;
use App\Module\Account\Entity\WaitlistBlockPattern;
use App\Module\Account\Entity\WaitlistEntry;
use App\Module\Account\Entity\WaitlistPick;
use App\Module\Account\Repository\WaitlistBlockPatternRepository;
use App\Module\Account\Repository\WaitlistEntryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\When;

/**
 * Waitlist entries and two block patterns, so the admin waitlist page shows
 * rows, pick counts, the patterns and two hidden entries.
 */
#[When('dev')]
#[When('test')]
final readonly class DevWaitlistSeeder implements DevSeederInterface
{
    public const string KEY = 'waitlist';

    /** @var array<string, array{non-empty-string, list<WaitlistPick>, int}> key to address, picks and days since joining */
    private const array ENTRIES = [
        'ada' => ['ada@waitlist.example', [WaitlistPick::GitLab, WaitlistPick::Cursor], 9],
        'grace' => ['grace@waitlist.example', [WaitlistPick::Pi], 6],
        'linus' => ['linus@waitlist.example', [], 3],
        'blocked-domain' => ['temp42@mailinator.com', [WaitlistPick::Aider], 2],
        'blocked-prefix' => ['spambot7@gmail.com', [], 1],
    ];

    /** @var array<string, non-empty-string> */
    private const array PATTERNS = [
        'mailinator' => '*@mailinator.com',
        'spam-gmail' => 'spam*@gmail.com',
    ];

    public function __construct(
        private EntityManagerInterface $em,
        private WaitlistEntryRepository $waitlistEntries,
        private WaitlistBlockPatternRepository $waitlistBlockPatterns,
    ) {
    }

    public static function entryId(string $key): string
    {
        return (string) DevSeedId::of('account/waitlist-entry/'.$key);
    }

    public static function patternId(string $key): string
    {
        return (string) DevSeedId::of('account/waitlist-block-pattern/'.$key);
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
        foreach (self::ENTRIES as $key => [$email, $picks, $days]) {
            if (null !== $this->waitlistEntries->findOneByEmail($email)) {
                continue;
            }
            $entry = new WaitlistEntry($email, $context->now->modify(\sprintf('-%d days', $days)));
            $entry->mergePicks($picks, null);
            $context->ids->assign($entry, DevSeedId::of('account/waitlist-entry/'.$key));
            $this->em->persist($entry);
        }

        foreach (self::PATTERNS as $key => $glob) {
            if (null !== $this->waitlistBlockPatterns->findOneByPattern($glob)) {
                continue;
            }
            $pattern = new WaitlistBlockPattern($glob, $context->at(60));
            $context->ids->assign($pattern, DevSeedId::of('account/waitlist-block-pattern/'.$key));
            $this->em->persist($pattern);
        }

        $this->em->flush();
    }
}
