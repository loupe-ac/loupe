<?php

declare(strict_types=1);

namespace App\Module\GitHub\Service;

use App\Module\Forge\Entity\ForgeRepositoryCheck;
use App\Module\Forge\Service\RepositorySuggestionCatalog as Catalog;
use App\Module\Forge\Settings\RepositoryRuleset;
use App\Module\Forge\Settings\RepositorySettings;
use App\Module\GitHub\View\GuideLink;
use App\Module\GitHub\View\GuideStep;
use App\Module\GitHub\View\GuideText;
use App\Module\GitHub\View\RepositoryGuide;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Writes the "Fix it on GitHub" steps of a stored check. Every link comes from
 * the repository path, the organization name and the ruleset id. No address
 * that GitHub sent ever becomes a link.
 */
final readonly class GitHubRepositoryGuide
{
    private const string PREFIX = 'github.repositories.guide.';
    private const string HOST = 'https://github.com/';
    private const string ORGANIZATION_NAME = '/^[A-Za-z0-9][A-Za-z0-9-]{0,38}$/';
    private const string RULE_PULL_REQUEST = 'pull_request';
    private const string RULE_REQUIRED_CHECKS = 'required_status_checks';

    private const array IMPORT_GAPS = [
        Catalog::GAP_NO_PULL_REQUEST_RULE,
        Catalog::GAP_APPROVAL_CHECK_NOT_REQUIRED,
        Catalog::GAP_APPROVAL_CHECK_UNPINNED,
    ];

    public function __construct(
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
        private GitHubRulesetFile $rulesetFile,
    ) {
    }

    public function forCheck(ForgeRepositoryCheck $check): RepositoryGuide
    {
        $settings = $this->settings($check);
        $repositoryPath = $this->repositoryPage($check->repository->path);
        $keys = array_values(array_unique(array_map(static fn (array $item): string => $item['key'], $check->items)));
        $gaps = [];
        foreach ($check->items as $item) {
            if (ForgeRepositoryCheck::STATE_GAP === $item['state']) {
                array_push($gaps, ...$item['gaps']);
            }
        }
        $has = static fn (string ...$codes): bool => [] !== array_intersect($codes, $gaps);
        $branch = null === $settings ? $this->translator->trans('github.repositories.check.default_branch') : $settings->defaultBranch;
        $rulesList = new GuideLink(self::PREFIX.'link.rules', $repositoryPath.'/settings/rules', true);

        $steps = [];
        $importShown = $has(...self::IMPORT_GAPS);
        $checkNames = $this->rulesetFile->checkNames($settings, $keys);
        if ($importShown) {
            $steps[] = $this->importStep($check, $settings, $keys, $checkNames, $rulesList);
            $steps[] = new GuideStep(GuideStep::VERIFY_SOURCE, [new GuideText(self::PREFIX.'verify_source.text')]);
        }
        if ($has(Catalog::GAP_REVIEW_REQUIRED)) {
            array_push($steps, ...$this->reviewSteps($settings, $repositoryPath, $branch, $rulesList));
        }
        if ($has(Catalog::GAP_APPROVAL_CHECK_UNPINNED)) {
            array_push($steps, ...$this->unpinnedSteps($settings, $repositoryPath));
        }
        if ($has(Catalog::GAP_NO_CI_CHECK) && !($importShown && [] !== $checkNames)) {
            $steps[] = [] === $checkNames
                ? new GuideStep(GuideStep::CI_CHECKS, [new GuideText(self::PREFIX.'ci_checks.unnamed', ['%branch%' => $branch]), new GuideText(self::PREFIX.'ci_checks.path')], [$rulesList])
                : new GuideStep(GuideStep::CI_CHECKS, [new GuideText(self::PREFIX.'ci_checks.named', ['%branch%' => $branch, '%checks%' => implode(', ', $checkNames)]), new GuideText(self::PREFIX.'ci_checks.path')], [$rulesList]);
        }
        if ($has(Catalog::GAP_FORCE_PUSH_ALLOWED, Catalog::GAP_DELETION_ALLOWED) && !$importShown) {
            $steps[] = new GuideStep(GuideStep::PROTECTION, [new GuideText(self::PREFIX.'protection.text', ['%branch%' => $branch])], [$rulesList]);
        }
        if ($has(Catalog::GAP_NO_MERGE_METHOD)) {
            $steps[] = $this->mergeMethodStep($settings, $repositoryPath);
        }

        return new RepositoryGuide(
            $steps,
            $has(Catalog::GAP_CLASSIC_PROTECTION) ? new GuideLink(self::PREFIX.'link.branches', $repositoryPath.'/settings/branches', true) : null,
        );
    }

    /**
     * @param list<string> $keys
     * @param list<string> $checkNames
     */
    private function importStep(ForgeRepositoryCheck $check, ?RepositorySettings $settings, array $keys, array $checkNames, GuideLink $rulesList): GuideStep
    {
        $texts = [new GuideText(self::PREFIX.'import.intro'), new GuideText(self::PREFIX.'import.path')];
        if ([] !== $checkNames) {
            $texts[] = new GuideText(self::PREFIX.'import.checks', ['%checks%' => implode(', ', $checkNames)]);
        }
        if (\in_array(GitHubRulesetFile::BRANCH_PROTECTION, $keys, true)) {
            $texts[] = new GuideText(self::PREFIX.'import.protection');
        }
        if (null === $settings?->loupeAppId) {
            $texts[] = new GuideText(self::PREFIX.'import.no_app');
        }

        $download = $this->urls->generate('app_github_repository_ruleset', [
            'id' => (string) $check->project->id,
            'repositoryId' => (string) $check->repository->id,
        ]);

        return new GuideStep(GuideStep::IMPORT, $texts, [new GuideLink(self::PREFIX.'link.download', $download, false), $rulesList]);
    }

    /** @return list<GuideStep> */
    private function reviewSteps(?RepositorySettings $settings, string $repositoryPath, string $branch, GuideLink $rulesList): array
    {
        $steps = [];
        foreach ($this->rulesetsWith($settings, self::RULE_PULL_REQUEST) as $ruleset) {
            $steps[] = $this->rulesetStep(GuideStep::REVIEW, 'review', $ruleset, $repositoryPath);
        }

        return [] === $steps
            ? [new GuideStep(GuideStep::REVIEW, [new GuideText(self::PREFIX.'review.fallback', ['%branch%' => $branch])], [$rulesList])]
            : $steps;
    }

    /** @return list<GuideStep> */
    private function unpinnedSteps(?RepositorySettings $settings, string $repositoryPath): array
    {
        return array_map(
            fn (RepositoryRuleset $ruleset): GuideStep => $this->rulesetStep(GuideStep::UNPINNED, 'unpinned', $ruleset, $repositoryPath),
            $this->rulesetsWith($settings, self::RULE_REQUIRED_CHECKS),
        );
    }

    /** One step for a ruleset: its own page when the repository owns it, and an owner warning when an organization does. */
    private function rulesetStep(string $kind, string $textPrefix, RepositoryRuleset $ruleset, string $repositoryPath): GuideStep
    {
        $name = $ruleset->name ?? $this->translator->trans(self::PREFIX.'ruleset_unnamed');
        if (!$ruleset->isOrganization()) {
            return new GuideStep(
                $kind,
                [new GuideText(self::PREFIX.$textPrefix.'.repository', ['%ruleset%' => $name])],
                [$this->rulesetLink($name, $repositoryPath.'/rules/'.$ruleset->id)],
            );
        }

        $texts = [new GuideText(self::PREFIX.$textPrefix.'.organization', ['%ruleset%' => $name, '%organization%' => $ruleset->source])];
        if (1 !== preg_match(self::ORGANIZATION_NAME, $ruleset->source)) {
            return new GuideStep($kind, $texts);
        }

        return new GuideStep($kind, $texts, [$this->rulesetLink($name, self::HOST.'organizations/'.$ruleset->source.'/settings/rules/'.$ruleset->id)]);
    }

    private function rulesetLink(string $name, string $href): GuideLink
    {
        return new GuideLink(self::PREFIX.'link.ruleset', $href, true, ['%ruleset%' => $name]);
    }

    private function mergeMethodStep(?RepositorySettings $settings, string $repositoryPath): GuideStep
    {
        $texts = [new GuideText(self::PREFIX.'merge_method.text'), new GuideText(self::PREFIX.'merge_method.path')];
        $allowed = $settings?->rules->allowedMergeMethods;
        if (null !== $allowed && [] !== $allowed) {
            $texts[] = new GuideText(self::PREFIX.'merge_method.rules', ['%methods%' => implode(', ', $allowed)]);
        }

        return new GuideStep(GuideStep::MERGE_METHOD, $texts, [new GuideLink(self::PREFIX.'link.merge_settings', $repositoryPath.'/settings#merge-button-settings', true)]);
    }

    /** @return list<RepositoryRuleset> the rulesets that hold a rule of the type, each id once */
    private function rulesetsWith(?RepositorySettings $settings, string $ruleType): array
    {
        $found = [];
        foreach ($settings->rulesets ?? [] as $ruleset) {
            if (\in_array($ruleType, $ruleset->rules, true)) {
                $found[$ruleset->id] = $ruleset;
            }
        }

        return array_values($found);
    }

    private function repositoryPage(string $path): string
    {
        return self::HOST.implode('/', array_map(rawurlencode(...), explode('/', $path)));
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
