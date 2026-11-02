<?php

declare(strict_types=1);

namespace App\Module\OAuth\Service\Dev;

use App\DevSeed\DevSeederInterface;
use App\DevSeed\DevSeedId;
use App\DevSeed\SeedContext;
use App\Module\Account\Entity\User;
use App\Module\Account\Repository\UserRepository;
use App\Module\Account\Service\Dev\DevAccountSeeder;
use App\Module\OAuth\Entity\ApiKey;
use App\Module\OAuth\Repository\ApiKeyRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\Uid\Uuid;

/**
 * The states of the API keys page: an account with three live keys and an
 * account with one ended key. The empty twin is an account with no keys.
 */
#[When('dev')]
#[When('test')]
final readonly class DevApiKeySeeder implements DevSeederInterface
{
    public const string KEY = 'api-keys';

    public function __construct(
        private EntityManagerInterface $em,
        private ApiKeyRepository $apiKeys,
        private UserRepository $users,
    ) {
    }

    public static function keyId(string $slug): Uuid
    {
        return DevSeedId::of('oauth/api-key/'.$slug);
    }

    #[\Override]
    public function key(): string
    {
        return self::KEY;
    }

    #[\Override]
    public function dependsOn(): array
    {
        return [DevAccountSeeder::KEY];
    }

    #[\Override]
    public function seed(SeedContext $context): void
    {
        $owner = $this->owner(DevAccountSeeder::KEYS_USER);
        $this->seedKey($context, $owner, 'ci-deploy', 'CI deploy', '+82 days', $context->at(7200));
        $this->seedKey($context, $owner, 'metrics-dashboard', 'Metrics dashboard', null, $context->at(86400));
        $this->seedKey($context, $owner, 'old-script', 'Old script', '+5 days', null);

        $this->seedKey($context, $this->owner(DevAccountSeeder::KEYS_EXPIRED_USER), 'ended-script', 'Ended script', '-3 days', $context->at(864000));

        $this->em->flush();
    }

    private function owner(string $seedKey): User
    {
        return $this->users->find(DevSeedId::of($seedKey))
            ?? throw new \LogicException(\sprintf('The account %s does not exist.', $seedKey));
    }

    private function seedKey(SeedContext $context, User $owner, string $slug, string $name, ?string $expiresIn, ?\DateTimeImmutable $lastUsedAt): void
    {
        $id = self::keyId($slug);
        if ($this->apiKeys->find($id) instanceof ApiKey) {
            return;
        }

        $secret = ApiKey::PREFIX.hash('sha256', 'dev-seed/'.$slug);
        $key = new ApiKey(
            $owner,
            $name,
            ApiKey::hashOf($secret),
            ApiKey::displayStartOf($secret),
            null === $expiresIn ? null : $context->now->modify($expiresIn),
            $context->at(2592000),
        );
        $key->lastUsedAt = $lastUsedAt;
        // The expiry sweep would otherwise queue a mail on every seeded database.
        $key->expiryNoticeSentAt = $context->now;
        $context->ids->assign($key, $id);
        $this->em->persist($key);
    }
}
