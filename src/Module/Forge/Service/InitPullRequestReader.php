<?php

declare(strict_types=1);

namespace App\Module\Forge\Service;

use App\Module\Forge\Entity\ForgeRepository;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Tells whether an open pull request from the branch of `loupe init` sets the merge setting in `.loupe.yaml`.
 * A forge module implements it.
 */
#[AutoconfigureTag('app.init_pull_request_reader')]
interface InitPullRequestReader
{
    public const string BRANCH = 'loupe/init';

    /** @param string $forge the forge's slug, such as `github` */
    public function supports(string $forge): bool;

    /** False on any forge failure, so the caller asks as it does with no init pull request. */
    public function setsMergeSetting(ForgeRepository $repository): bool;
}
