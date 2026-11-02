<?php

declare(strict_types=1);

namespace App\Module\OpenRouter\Service\Dev;

use App\DevSeed\DevSeederInterface;
use App\DevSeed\DevSeedId;
use App\DevSeed\SeedContext;
use App\Module\OpenRouter\Entity\OpenRouterKey;
use App\Module\OpenRouter\Repository\OpenRouterKeyRepository;
use App\Module\Project\Repository\ProjectRepository;
use App\Module\Project\Service\Dev\DevOpenRouterProjectSeeder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\Uid\Uuid;

/**
 * One project key with a fake secret, so the key page of the OpenRouter project
 * shows a key. It writes no user key, because that key would apply to every
 * project of the user. The seed makes no call to OpenRouter.
 */
#[When('dev')]
#[When('test')]
final readonly class DevOpenRouterKeySeeder implements DevSeederInterface
{
    public const string KEY = 'openrouter-key';
    public const string SECRET = 'sk-or-v1-dev-seed-00000000000000000000a3f9';

    public function __construct(
        private EntityManagerInterface $em,
        private OpenRouterKeyRepository $openRouterKeys,
        private ProjectRepository $projects,
    ) {
    }

    public static function keyId(): Uuid
    {
        return DevSeedId::of('openrouter/key/openrouter-project');
    }

    #[\Override]
    public function key(): string
    {
        return self::KEY;
    }

    #[\Override]
    public function dependsOn(): array
    {
        return [DevOpenRouterProjectSeeder::KEY];
    }

    #[\Override]
    public function seed(SeedContext $context): void
    {
        if ($this->openRouterKeys->find(self::keyId()) instanceof OpenRouterKey) {
            return;
        }

        $project = $this->projects->find(DevOpenRouterProjectSeeder::projectId())
            ?? throw new \LogicException('The OpenRouter project does not exist.');
        if ($this->openRouterKeys->findOneBy(['project' => $project]) instanceof OpenRouterKey) {
            return;
        }

        $key = new OpenRouterKey(self::SECRET, project: $project, createdAt: $context->at(3600));
        $key->lastReadAt = $context->at(600);
        $context->ids->assign($key, self::keyId());
        $this->em->persist($key);
        $this->em->flush();
    }
}
