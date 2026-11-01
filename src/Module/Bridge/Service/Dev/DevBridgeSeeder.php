<?php

declare(strict_types=1);

namespace App\Module\Bridge\Service\Dev;

use App\DevSeed\DevSeederInterface;
use App\DevSeed\DevSeedId;
use App\DevSeed\SeedContext;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Entity\BridgeAccountReading;
use App\Module\Bridge\Repository\BridgeAccountReadingRepository;
use App\Module\Bridge\Repository\BridgeRepository;
use App\Module\Project\Repository\ProjectRepository;
use App\Module\Project\Service\Dev\DevProjectSeeder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\Uid\Uuid;

/**
 * Two bridges of the dev user follow "Dev Project": one that reported a moment
 * ago, with account usage on the Agents page and the live runs, and one that went
 * quiet. The quiet one carries the id that the 60 days of demo runs name, and the
 * metrics seeder deletes every run of that bridge it does not own. Each day adds
 * one reading per usage window, so the bars never age into an unknown state.
 */
#[When('dev')]
#[When('test')]
final readonly class DevBridgeSeeder implements DevSeederInterface
{
    public const string KEY = 'bridges';
    public const string MAIN_BRIDGE = '01a19300-0000-7000-8000-00000000de01';
    public const string HISTORY_BRIDGE = '01a19300-0000-7000-8000-00000000de00';

    private const string CLI_VERSION = '1.4.0';

    /** @var list<array{name: string, harness: string, state: 'ready'|'failing', reason: ?string, used: bool, kinds: list<string>, stopAt: int}> */
    private const array MAIN_ACCOUNTS = [
        ['name' => 'claude-main', 'harness' => 'claude-code', 'state' => 'ready', 'reason' => null, 'used' => true, 'kinds' => ['implement', 'review'], 'stopAt' => 95],
        ['name' => 'codex-main', 'harness' => 'codex', 'state' => 'ready', 'reason' => null, 'used' => true, 'kinds' => ['review'], 'stopAt' => 90],
        ['name' => 'claude-spare', 'harness' => 'claude-code', 'state' => 'failing', 'reason' => 'The login expired.', 'used' => false, 'kinds' => [], 'stopAt' => 95],
    ];

    /** @var array<string, array<string, float>> account => window => percent used */
    private const array USAGE = [
        'claude-main' => ['session' => 42.0, 'week' => 71.0],
        'codex-main' => ['session' => 96.0, 'week' => 38.0],
    ];

    public function __construct(
        private EntityManagerInterface $em,
        private BridgeRepository $bridges,
        private BridgeAccountReadingRepository $bridgeAccountReadings,
        private ProjectRepository $projects,
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
        return [DevProjectSeeder::KEY];
    }

    #[\Override]
    public function seed(SeedContext $context): void
    {
        $project = $this->projects->find(DevProjectSeeder::devProjectId())
            ?? throw new \LogicException('The dev project does not exist.');
        $owner = $context->devUser();
        $projectId = (string) $project->id;

        $main = $this->bridges->findOneByOwnerAndId($owner, Uuid::fromString(self::MAIN_BRIDGE));
        if (!$main instanceof Bridge) {
            $main = new Bridge($owner, Uuid::fromString(self::MAIN_BRIDGE), [$projectId], self::CLI_VERSION, $context->now);
            $main->accounts = self::MAIN_ACCOUNTS;
            $this->em->persist($main);
        }
        $main->lastSeenAt = $context->now;
        $main->accountsReportedAt = $context->now;

        $history = $this->bridges->findOneByOwnerAndId($owner, Uuid::fromString(self::HISTORY_BRIDGE));
        if (!$history instanceof Bridge) {
            $history = new Bridge($owner, Uuid::fromString(self::HISTORY_BRIDGE), [$projectId], self::CLI_VERSION, $context->at(6 * 3600));
            $history->accounts = [self::MAIN_ACCOUNTS[0]];
            $history->accountsReportedAt = $context->at(6 * 3600);
            $this->em->persist($history);
        }
        $this->em->flush();

        $this->seedReadings($context, $main);
    }

    /** One reading per window and day. Each window resets later than the clock, so the bar shows its percentage. */
    private function seedReadings(SeedContext $context, Bridge $bridge): void
    {
        $day = $context->now->format('Y-m-d');
        $resets = ['session' => $context->now->modify('+3 hours'), 'week' => $context->now->modify('+4 days')];

        foreach (self::USAGE as $account => $windows) {
            foreach ($windows as $window => $percent) {
                $id = DevSeedId::of(\sprintf('bridge/account-reading/%s/%s/%s', $account, $window, $day));
                if ($this->bridgeAccountReadings->find($id) instanceof BridgeAccountReading) {
                    continue;
                }
                $this->em->persist(new BridgeAccountReading($id, $bridge, $account, $window, $percent, $resets[$window], false, 'dev-seed', $context->now));
            }
        }
        $this->em->flush();
    }
}
