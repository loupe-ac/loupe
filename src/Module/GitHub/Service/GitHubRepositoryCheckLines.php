<?php

declare(strict_types=1);

namespace App\Module\GitHub\Service;

use App\Module\Forge\Entity\ForgeRepositoryCheck;
use App\Module\Forge\Service\RepositorySuggestionCatalog;
use App\Module\Forge\Settings\RepositorySettings;
use App\Module\GitHub\View\RepositoryCheckLine;
use App\Module\Workflow\Contract\SuggestionMark;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Turns the stored items of a check into the lines of the Repositories page.
 * A line names a value only when the stored settings hold it, and says less otherwise.
 */
final readonly class GitHubRepositoryCheckLines
{
    private const string KEY_PREFIX = 'github.repositories.check.';
    private const string LOUPE_CHECK_PREFIX = 'Loupe / ';
    private const string RULE_PULL_REQUEST = 'pull_request';

    /** The rule types whose rulesets a branch protection line names. */
    private const array PROTECTION_RULES = ['non_fast_forward', 'deletion'];

    private const int MAX_LISTED = 5;

    /** The suggestion key that each gap code belongs to. */
    private const array GAP_KEYS = [
        RepositorySuggestionCatalog::GAP_NO_PULL_REQUEST_RULE => 'approval-gate',
        RepositorySuggestionCatalog::GAP_APPROVAL_CHECK_NOT_REQUIRED => 'approval-gate',
        RepositorySuggestionCatalog::GAP_APPROVAL_CHECK_UNPINNED => 'approval-gate',
        RepositorySuggestionCatalog::GAP_REVIEW_REQUIRED => 'approval-gate',
        RepositorySuggestionCatalog::GAP_NO_CI_CHECK => 'ci-checks',
        RepositorySuggestionCatalog::GAP_FORCE_PUSH_ALLOWED => 'branch-protection',
        RepositorySuggestionCatalog::GAP_DELETION_ALLOWED => 'branch-protection',
        RepositorySuggestionCatalog::GAP_NO_MERGE_METHOD => 'merge-method',
        RepositorySuggestionCatalog::GAP_CLASSIC_PROTECTION => ForgeRepositoryCheck::KEY_CLASSIC_PROTECTION,
    ];

    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    /** @return list<RepositoryCheckLine> */
    public function forCheck(ForgeRepositoryCheck $check): array
    {
        $settings = $this->settings($check);
        $branch = $settings->defaultBranch ?? $this->translator->trans(self::KEY_PREFIX.'default_branch');

        $lines = [];
        foreach ($check->items as $item) {
            if (ForgeRepositoryCheck::STATE_FITS === $item['state']) {
                $lines[] = $this->fitsLine($item['key'], $settings, $branch);
                continue;
            }

            $modifier = SuggestionMark::Blocking->value === $item['mark'] ? 'failed' : 'pending';
            $state = 'failed' === $modifier ? 'blocking' : 'warning';
            foreach ($item['gaps'] as $gap) {
                $lines[] = $this->gapLine($item['key'], $gap, $modifier, $state, $settings, $branch);
            }
        }

        return $lines;
    }

    private function fitsLine(string $key, ?RepositorySettings $settings, string $branch): RepositoryCheckLine
    {
        $prefix = self::KEY_PREFIX.$key.'.fits';
        $titleParameters = ['%branch%' => $branch];
        $detailKey = $prefix.'.detail';
        $detailParameters = ['%branch%' => $branch];

        if ('ci-checks' === $key) {
            $names = $this->ciChecks($settings);
            if ([] === $names) {
                $detailKey = $prefix.'.detail_unnamed';
            }
            $detailParameters = ['%checks%' => implode(', ', $names)];
        } elseif ('branch-protection' === $key) {
            $names = $this->rulesetNames($settings, self::PROTECTION_RULES);
            if ([] === $names) {
                $detailKey = $prefix.'.detail_unnamed';
            }
            $detailParameters = ['%rulesets%' => implode(', ', $names), '%branch%' => $branch];
        } elseif ('merge-method' === $key) {
            $method = $settings?->usableMergeMethods()[0] ?? null;
            if (null === $method) {
                $detailKey = $prefix.'.detail_unnamed';
            }
            $detailParameters = ['%method%' => null === $method ? '' : $this->translator->trans(self::KEY_PREFIX.'method.'.$method)];
        }

        return new RepositoryCheckLine('ok', self::KEY_PREFIX.'state.fits', $prefix.'.title', $titleParameters, $detailKey, $detailParameters);
    }

    private function gapLine(string $key, string $gap, string $modifier, string $state, ?RepositorySettings $settings, string $branch): RepositoryCheckLine
    {
        $stateKey = self::KEY_PREFIX.'state.'.$state;
        if ((self::GAP_KEYS[$gap] ?? null) !== $key) {
            return new RepositoryCheckLine($modifier, $stateKey, self::KEY_PREFIX.'unknown.title', [], self::KEY_PREFIX.'unknown.detail', []);
        }

        $prefix = self::KEY_PREFIX.$key.'.'.$gap;
        $detailKey = $prefix.'.detail';
        $detailParameters = ['%branch%' => $branch];

        if (RepositorySuggestionCatalog::GAP_REVIEW_REQUIRED === $gap) {
            $names = $this->rulesetNames($settings, [self::RULE_PULL_REQUEST]);
            if ([] !== $names) {
                $detailKey = $prefix.'.detail_named';
                $detailParameters['%rulesets%'] = implode(', ', $names);
            }
        } elseif (RepositorySuggestionCatalog::GAP_NO_CI_CHECK === $gap) {
            $names = $settings->recentChecks ?? [];
            if ([] !== $names) {
                $detailKey = $prefix.'.detail_seen';
                $detailParameters['%checks%'] = implode(', ', \array_slice($names, 0, self::MAX_LISTED));
            }
        }

        return new RepositoryCheckLine($modifier, $stateKey, $prefix.'.title', ['%branch%' => $branch], $detailKey, $detailParameters, $gap);
    }

    /** @return list<string> */
    private function ciChecks(?RepositorySettings $settings): array
    {
        $names = array_values(array_filter(
            $settings->rules->requiredChecks ?? [],
            static fn (string $check): bool => !str_starts_with($check, self::LOUPE_CHECK_PREFIX),
        ));

        return \array_slice($names, 0, self::MAX_LISTED);
    }

    /**
     * @param list<string> $ruleTypes
     *
     * @return list<string> the names of the rulesets that hold one of the rule types, each once
     */
    private function rulesetNames(?RepositorySettings $settings, array $ruleTypes): array
    {
        $names = [];
        foreach ($settings->rulesets ?? [] as $ruleset) {
            if (null !== $ruleset->name && [] !== array_intersect($ruleTypes, $ruleset->rules)) {
                $names[$ruleset->name] = true;
            }
        }

        return \array_slice(array_map(strval(...), array_keys($names)), 0, self::MAX_LISTED);
    }

    private function settings(ForgeRepositoryCheck $check): ?RepositorySettings
    {
        if (null === $check->settings) {
            return null;
        }

        try {
            return RepositorySettings::fromArray($check->settings);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }
}
