<?php

declare(strict_types=1);

namespace App\Tests\Module\GitHub\Service;

use App\Module\Account\Entity\User;
use App\Module\Forge\Entity\ForgeRepository;
use App\Module\Forge\Entity\ForgeRepositoryCheck;
use App\Module\Forge\Entity\ForgeRepositorySource;
use App\Module\Forge\Service\RepositorySuggestionCatalog as Catalog;
use App\Module\Forge\Settings\RepositoryRules;
use App\Module\Forge\Settings\RepositoryRuleset;
use App\Module\Forge\Settings\RepositorySettings;
use App\Module\GitHub\Service\GitHubRepositoryGuide;
use App\Module\GitHub\Service\GitHubRulesetFile;
use App\Module\GitHub\View\GuideLink;
use App\Module\GitHub\View\GuideStep;
use App\Module\GitHub\View\RepositoryGuide;
use App\Module\Project\Entity\Project;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

final class GitHubRepositoryGuideTest extends TestCase
{
    private const string G = 'github.repositories.guide.';

    /** @return iterable<string, array{string}> */
    public static function importGaps(): iterable
    {
        yield 'no pull request rule' => [Catalog::GAP_NO_PULL_REQUEST_RULE];
        yield 'approval check not required' => [Catalog::GAP_APPROVAL_CHECK_NOT_REQUIRED];
        yield 'approval check unpinned' => [Catalog::GAP_APPROVAL_CHECK_UNPINNED];
    }

    #[DataProvider('importGaps')]
    public function test_a_gap_that_a_missing_rule_causes_gets_the_import_step_with_the_two_links(string $gap): void
    {
        $guide = $this->guide([$this->item('approval-gate', [$gap])]);

        $step = $this->step($guide, GuideStep::IMPORT);
        self::assertSame(self::G.'import.intro', $step->texts[0]->key);
        self::assertSame(self::G.'import.path', $step->texts[1]->key);
        self::assertCount(2, $step->links);
        self::assertSame(self::G.'link.download', $step->links[0]->labelKey);
        self::assertStringEndsWith('/loupe-ruleset.json', $step->links[0]->href);
        self::assertFalse($step->links[0]->external);
        self::assertSame(self::G.'link.rules', $step->links[1]->labelKey);
        self::assertSame('https://github.com/acme/app/settings/rules', $step->links[1]->href);
        self::assertTrue($step->links[1]->external);
    }

    public function test_the_import_step_is_followed_by_the_step_that_checks_the_source_app(): void
    {
        $guide = $this->guide([$this->item('approval-gate', [Catalog::GAP_NO_PULL_REQUEST_RULE])]);

        self::assertSame([GuideStep::IMPORT, GuideStep::VERIFY_SOURCE], array_map(static fn (GuideStep $step): string => $step->kind, $guide->steps));
        self::assertSame(self::G.'verify_source.text', $guide->steps[1]->texts[0]->key);
    }

    public function test_an_unknown_app_id_is_said_in_the_import_step(): void
    {
        $guide = $this->guide([$this->item('approval-gate', [Catalog::GAP_NO_PULL_REQUEST_RULE])], appId: null);

        self::assertContains(self::G.'import.no_app', $this->textKeys($this->step($guide, GuideStep::IMPORT)));
    }

    public function test_a_known_app_id_adds_no_such_sentence(): void
    {
        $guide = $this->guide([$this->item('approval-gate', [Catalog::GAP_NO_PULL_REQUEST_RULE])]);

        self::assertNotContains(self::G.'import.no_app', $this->textKeys($this->step($guide, GuideStep::IMPORT)));
    }

    public function test_two_import_gaps_give_one_import_step(): void
    {
        $guide = $this->guide([$this->item('approval-gate', [Catalog::GAP_NO_PULL_REQUEST_RULE, Catalog::GAP_APPROVAL_CHECK_NOT_REQUIRED])]);

        self::assertCount(1, $this->steps($guide, GuideStep::IMPORT));
    }

    public function test_the_import_step_names_the_checks_and_the_protection_that_the_file_adds(): void
    {
        $guide = $this->guide([
            $this->item('approval-gate', [Catalog::GAP_NO_PULL_REQUEST_RULE]),
            $this->item('ci-checks', [Catalog::GAP_NO_CI_CHECK]),
            $this->item('branch-protection', [Catalog::GAP_FORCE_PUSH_ALLOWED, Catalog::GAP_DELETION_ALLOWED]),
        ], recent: ['lint', 'phpunit']);

        $step = $this->step($guide, GuideStep::IMPORT);
        $keys = $this->textKeys($step);
        self::assertContains(self::G.'import.checks', $keys);
        self::assertContains(self::G.'import.protection', $keys);
        $checks = array_values(array_filter($step->texts, static fn ($text): bool => self::G.'import.checks' === $text->key));
        self::assertSame('lint, phpunit', $checks[0]->parameters['%checks%']);
        self::assertSame([], $this->steps($guide, GuideStep::CI_CHECKS), 'the file already adds the checks');
        self::assertSame([], $this->steps($guide, GuideStep::PROTECTION), 'the file already adds the protection');
    }

    public function test_a_review_rule_of_a_repository_ruleset_links_the_page_of_that_ruleset(): void
    {
        $guide = $this->guide(
            [$this->item('approval-gate', [Catalog::GAP_REVIEW_REQUIRED])],
            rulesets: [new RepositoryRuleset(12, 'main protection', 'Repository', 'acme/app', ['pull_request'])],
        );

        $step = $this->step($guide, GuideStep::REVIEW);
        self::assertSame(self::G.'review.repository', $step->texts[0]->key);
        self::assertSame('main protection', $step->texts[0]->parameters['%ruleset%']);
        self::assertSame('https://github.com/acme/app/rules/12', $step->links[0]->href);
        self::assertSame(self::G.'link.ruleset', $step->links[0]->labelKey);
        self::assertSame(['%ruleset%' => 'main protection'], $step->links[0]->labelParameters);
    }

    public function test_a_review_rule_of_an_organization_ruleset_names_the_organization_and_says_who_can_change_it(): void
    {
        $guide = $this->guide(
            [$this->item('approval-gate', [Catalog::GAP_REVIEW_REQUIRED])],
            rulesets: [new RepositoryRuleset(7, 'Org defaults', 'Organization', 'acme-org', ['pull_request'])],
        );

        $step = $this->step($guide, GuideStep::REVIEW);
        self::assertSame(self::G.'review.organization', $step->texts[0]->key);
        self::assertSame(['%ruleset%' => 'Org defaults', '%organization%' => 'acme-org'], $step->texts[0]->parameters);
        self::assertSame('https://github.com/organizations/acme-org/settings/rules/7', $step->links[0]->href);
    }

    public function test_each_ruleset_with_a_pull_request_rule_gets_its_own_step_once(): void
    {
        $guide = $this->guide(
            [$this->item('approval-gate', [Catalog::GAP_REVIEW_REQUIRED])],
            rulesets: [
                new RepositoryRuleset(1, 'one', 'Repository', 'acme/app', ['pull_request']),
                new RepositoryRuleset(1, 'one', 'Repository', 'acme/app', ['pull_request']),
                new RepositoryRuleset(2, 'two', 'Organization', 'acme-org', ['pull_request', 'deletion']),
                new RepositoryRuleset(3, 'tags', 'Repository', 'acme/app', ['deletion']),
            ],
        );

        self::assertCount(2, $this->steps($guide, GuideStep::REVIEW));
    }

    public function test_a_review_gap_with_no_known_ruleset_links_the_list_of_rulesets(): void
    {
        $guide = $this->guide([$this->item('approval-gate', [Catalog::GAP_REVIEW_REQUIRED])]);

        $step = $this->step($guide, GuideStep::REVIEW);
        self::assertSame(self::G.'review.fallback', $step->texts[0]->key);
        self::assertSame('https://github.com/acme/app/settings/rules', $step->links[0]->href);
    }

    public function test_a_hidden_ruleset_name_gets_a_neutral_name_and_still_links_by_id(): void
    {
        $guide = $this->guide(
            [$this->item('approval-gate', [Catalog::GAP_REVIEW_REQUIRED])],
            rulesets: [new RepositoryRuleset(5, null, 'Repository', 'acme/app', ['pull_request'])],
        );

        $step = $this->step($guide, GuideStep::REVIEW);
        self::assertSame(self::G.'ruleset_unnamed', $step->texts[0]->parameters['%ruleset%']);
        self::assertSame('https://github.com/acme/app/rules/5', $step->links[0]->href);
    }

    public function test_an_unpinned_check_also_sends_the_owner_to_each_ruleset_that_requires_checks(): void
    {
        $guide = $this->guide(
            [$this->item('approval-gate', [Catalog::GAP_APPROVAL_CHECK_UNPINNED])],
            rulesets: [
                new RepositoryRuleset(8, 'checks', 'Repository', 'acme/app', ['required_status_checks']),
                new RepositoryRuleset(9, 'org checks', 'Organization', 'acme-org', ['required_status_checks']),
            ],
        );

        $steps = $this->steps($guide, GuideStep::UNPINNED);
        self::assertCount(2, $steps);
        self::assertSame(self::G.'unpinned.repository', $steps[0]->texts[0]->key);
        self::assertSame('https://github.com/acme/app/rules/8', $steps[0]->links[0]->href);
        self::assertSame(self::G.'unpinned.organization', $steps[1]->texts[0]->key);
        self::assertSame('https://github.com/organizations/acme-org/settings/rules/9', $steps[1]->links[0]->href);
        self::assertCount(1, $this->steps($guide, GuideStep::IMPORT));
    }

    public function test_a_missing_ci_check_names_the_recent_checks_when_no_import_covers_it(): void
    {
        $guide = $this->guide([$this->item('ci-checks', [Catalog::GAP_NO_CI_CHECK])], recent: ['lint', 'e2e']);

        $step = $this->step($guide, GuideStep::CI_CHECKS);
        self::assertSame(self::G.'ci_checks.named', $step->texts[0]->key);
        self::assertSame('lint, e2e', $step->texts[0]->parameters['%checks%']);
        self::assertSame('https://github.com/acme/app/settings/rules', $step->links[0]->href);
    }

    public function test_a_missing_ci_check_with_no_recent_check_says_so(): void
    {
        $guide = $this->guide([$this->item('ci-checks', [Catalog::GAP_NO_CI_CHECK])]);

        self::assertSame(self::G.'ci_checks.unnamed', $this->step($guide, GuideStep::CI_CHECKS)->texts[0]->key);
    }

    public function test_a_missing_ci_check_keeps_its_step_when_the_file_has_no_check_to_add(): void
    {
        $guide = $this->guide([
            $this->item('approval-gate', [Catalog::GAP_NO_PULL_REQUEST_RULE]),
            $this->item('ci-checks', [Catalog::GAP_NO_CI_CHECK]),
        ]);

        self::assertCount(1, $this->steps($guide, GuideStep::CI_CHECKS));
    }

    public function test_force_push_and_deletion_give_one_protection_step(): void
    {
        $guide = $this->guide([$this->item('branch-protection', [Catalog::GAP_FORCE_PUSH_ALLOWED, Catalog::GAP_DELETION_ALLOWED])]);

        $steps = $this->steps($guide, GuideStep::PROTECTION);
        self::assertCount(1, $steps);
        self::assertSame(self::G.'protection.text', $steps[0]->texts[0]->key);
        self::assertSame(['%branch%' => 'main'], $steps[0]->texts[0]->parameters);
        self::assertSame('https://github.com/acme/app/settings/rules', $steps[0]->links[0]->href);
    }

    public function test_no_merge_method_links_the_merge_settings_and_writes_the_click_path(): void
    {
        $guide = $this->guide([$this->item('merge-method', [Catalog::GAP_NO_MERGE_METHOD])]);

        $step = $this->step($guide, GuideStep::MERGE_METHOD);
        self::assertSame([self::G.'merge_method.text', self::G.'merge_method.path'], $this->textKeys($step));
        self::assertSame('https://github.com/acme/app/settings#merge-button-settings', $step->links[0]->href);
    }

    public function test_a_rule_that_limits_the_merge_methods_is_named(): void
    {
        $guide = $this->guide([$this->item('merge-method', [Catalog::GAP_NO_MERGE_METHOD])], allowedMethods: ['rebase']);

        $step = $this->step($guide, GuideStep::MERGE_METHOD);
        self::assertContains(self::G.'merge_method.rules', $this->textKeys($step));
        self::assertSame('rebase', $step->texts[2]->parameters['%methods%']);
    }

    public function test_classic_protection_gets_a_link_and_no_step(): void
    {
        $guide = $this->guide([$this->item(ForgeRepositoryCheck::KEY_CLASSIC_PROTECTION, [Catalog::GAP_CLASSIC_PROTECTION], 'warning')]);

        self::assertFalse($guide->hasSteps());
        self::assertInstanceOf(GuideLink::class, $guide->classicProtectionLink);
        self::assertSame('https://github.com/acme/app/settings/branches', $guide->classicProtectionLink->href);
        self::assertSame(self::G.'link.branches', $guide->classicProtectionLink->labelKey);
    }

    public function test_a_check_that_fits_has_no_step_and_no_link(): void
    {
        $guide = $this->guide([$this->item('approval-gate', []), $this->item('merge-method', [])]);

        self::assertFalse($guide->hasSteps());
        self::assertNull($guide->classicProtectionLink);
    }

    public function test_every_link_comes_from_the_repository_path_and_never_from_a_source_field_of_github(): void
    {
        $guide = $this->guide(
            [$this->item('approval-gate', [Catalog::GAP_REVIEW_REQUIRED])],
            rulesets: [new RepositoryRuleset(12, 'x', 'Repository', 'evil.example/phish', ['pull_request'])],
        );

        self::assertSame('https://github.com/acme/app/rules/12', $this->step($guide, GuideStep::REVIEW)->links[0]->href);
    }

    public function test_an_organization_name_that_is_no_organization_name_gets_no_link(): void
    {
        $guide = $this->guide(
            [$this->item('approval-gate', [Catalog::GAP_REVIEW_REQUIRED])],
            rulesets: [new RepositoryRuleset(12, 'x', 'Organization', 'evil.example/phish?x=1', ['pull_request'])],
        );

        $step = $this->step($guide, GuideStep::REVIEW);
        self::assertSame(self::G.'review.organization', $step->texts[0]->key);
        self::assertSame([], $step->links);
    }

    public function test_a_repository_path_with_odd_characters_is_encoded_in_each_segment(): void
    {
        $guide = $this->guide([$this->item('branch-protection', [Catalog::GAP_FORCE_PUSH_ALLOWED])], path: 'ac me/a?b#c');

        self::assertSame('https://github.com/ac%20me/a%3Fb%23c/settings/rules', $this->step($guide, GuideStep::PROTECTION)->links[0]->href);
    }

    public function test_a_check_with_no_stored_settings_still_gives_the_steps_that_need_none(): void
    {
        $guide = $this->guide([$this->item('merge-method', [Catalog::GAP_NO_MERGE_METHOD])], settings: false);

        self::assertCount(1, $this->steps($guide, GuideStep::MERGE_METHOD));
    }

    public function test_a_gap_code_that_no_step_knows_is_skipped(): void
    {
        $guide = $this->guide([$this->item('approval-gate', ['a_gap_of_a_newer_release'])]);

        self::assertFalse($guide->hasSteps());
    }

    /**
     * @param list<array{key: string, mark: string, state: string, gaps: list<string>, parameters: array<string, mixed>}> $items
     * @param list<string>                                                                                                $recent
     * @param list<RepositoryRuleset>                                                                                     $rulesets
     * @param list<string>|null                                                                                           $allowedMethods
     */
    private function guide(array $items, array $recent = [], array $rulesets = [], ?int $appId = 15368, ?array $allowedMethods = null, string $path = 'acme/app', bool $settings = true): RepositoryGuide
    {
        $project = new Project(new User(fullName: 'Riley', email: 'guide@example.com', password: 'x'), 'guide');
        $this->identify($project);
        $repository = new ForgeRepository($project, 'github', '1', $path, ForgeRepositorySource::Hook);
        $this->identify($repository);
        $check = new ForgeRepositoryCheck($repository, $project);
        $check->items = $items;
        $value = new RepositorySettings('main', ['squash'], $appId, new RepositoryRules(true, false, $allowedMethods, [], [], false, false), $rulesets, $recent, false);
        $check->settings = $settings ? json_decode(json_encode($value, \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR) : null;

        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturnCallback(static fn (string $name, array $parameters): string => '/projects/'.$parameters['id'].'/repositories/'.$parameters['repositoryId'].'/loupe-ruleset.json');
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        return new GitHubRepositoryGuide($urls, $translator, new GitHubRulesetFile())->forCheck($check);
    }

    private function identify(object $entity): void
    {
        new \ReflectionProperty($entity, 'id')->setValue($entity, Uuid::v7());
    }

    /**
     * @param list<string> $gaps
     *
     * @return array{key: string, mark: string, state: string, gaps: list<string>, parameters: array<string, mixed>}
     */
    private function item(string $key, array $gaps, string $mark = 'blocking'): array
    {
        return ['key' => $key, 'mark' => $mark, 'state' => [] === $gaps ? 'fits' : 'gap', 'gaps' => $gaps, 'parameters' => []];
    }

    /** @return list<GuideStep> */
    private function steps(RepositoryGuide $guide, string $kind): array
    {
        return array_values(array_filter($guide->steps, static fn (GuideStep $step): bool => $kind === $step->kind));
    }

    private function step(RepositoryGuide $guide, string $kind): GuideStep
    {
        $steps = $this->steps($guide, $kind);
        self::assertNotEmpty($steps, 'The guide has a '.$kind.' step.');

        return $steps[0];
    }

    /** @return list<string> */
    private function textKeys(GuideStep $step): array
    {
        return array_map(static fn ($text): string => $text->key, $step->texts);
    }
}
