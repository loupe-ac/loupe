<?php

declare(strict_types=1);

namespace App\Tests\Module\Forge;

use App\Module\Forge\Entity\ForgeRepository;
use App\Module\Forge\Service\InitPullRequestReader;

/** Answers for the `fake-init` forge by repository path, and records each repository that it was asked about. */
final class FakeInitPullRequestReader implements InitPullRequestReader
{
    public const string FORGE = 'fake-init';

    /** @var array<string, bool> */
    public array $answers = [];

    /** @var list<string> */
    public array $asked = [];

    #[\Override]
    public function supports(string $forge): bool
    {
        return self::FORGE === $forge;
    }

    #[\Override]
    public function setsMergeSetting(ForgeRepository $repository): bool
    {
        $this->asked[] = $repository->path;

        return $this->answers[$repository->path] ?? false;
    }
}
