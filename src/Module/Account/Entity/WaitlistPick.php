<?php

declare(strict_types=1);

namespace App\Module\Account\Entity;

enum WaitlistPick: string
{
    case GitLab = 'gitlab';
    case Bitbucket = 'bitbucket';
    case Gitea = 'gitea';
    case Forgejo = 'forgejo';
    case GitHubEnterprise = 'github-enterprise';
    case Cursor = 'cursor';
    case GeminiCli = 'gemini-cli';
    case OpenCode = 'opencode';
    case Aider = 'aider';
    case Pi = 'pi';
}
