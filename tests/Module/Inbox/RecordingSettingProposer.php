<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox;

use App\Module\Forge\Entity\ForgeRepository;
use App\Module\Forge\Service\PullRequestWriteFailed;
use App\Module\Forge\Service\RepositorySettingProposer;

final class RecordingSettingProposer implements RepositorySettingProposer
{
    /** @var list<array{ForgeRepository, string, string, string, string}> */
    public array $calls = [];

    public function __construct(
        private readonly string $url = '',
        private readonly ?PullRequestWriteFailed $failure = null,
    ) {
    }

    #[\Override]
    public function supports(string $forge): bool
    {
        return 'github' === $forge;
    }

    #[\Override]
    public function propose(ForgeRepository $repository, string $key, string $value, string $title, string $body): string
    {
        $this->calls[] = [$repository, $key, $value, $title, $body];

        return $this->failure ? throw $this->failure : $this->url;
    }
}
