<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Board\Entity\Forge;
use App\Module\Workflow\Contract\PullRequestKey;
use App\Module\Workflow\Contract\PullRequestUrls;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(PullRequestUrls::class)]
final readonly class BoardPullRequestUrls implements PullRequestUrls
{
    public function __construct(
        private PullRequestUrlResolver $resolver,
    ) {
    }

    #[\Override]
    public function keyOf(string $url): ?PullRequestKey
    {
        $ref = $this->resolver->resolve(trim($url));
        if (Forge::Other === $ref->forge || null === $ref->repository || null === $ref->number) {
            return null;
        }

        return new PullRequestKey($ref->forge->value, mb_strtolower($ref->repository), $ref->number);
    }
}
