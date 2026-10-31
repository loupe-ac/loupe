<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Template;

use App\Module\Workflow\Contract\CardValueType;
use App\Module\Workflow\Contract\CountFilter;
use App\Module\Workflow\Contract\MetricType;
use App\Module\Workflow\Contract\RepositorySuggestion;
use App\Module\Workflow\Contract\RepositorySuggestionKey;
use App\Module\Workflow\Contract\SuggestionMark;
use App\Module\Workflow\Contract\TemplateMetric;
use App\Module\Workflow\Contract\TemplateOutcome;
use App\Module\Workflow\Contract\TemplateValueKey;
use App\Module\Workflow\Template\AppRules;
use App\Module\Workflow\Template\InvalidTemplate;
use App\Module\Workflow\Template\Rule;
use App\Module\Workflow\Template\RuleOrigin;
use App\Module\Workflow\Template\ShippedTemplates;
use App\Module\Workflow\Template\Template;
use App\Module\Workflow\Template\TemplateParser;
use App\Module\Workflow\Template\WorkFailurePolicy;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;

final class AppRulesTest extends KernelTestCase
{
    public const string FIXTURE = __DIR__.'/Fixtures/app-rules';

    private ?string $directory = null;

    #[\Override]
    protected function tearDown(): void
    {
        if (null !== $this->directory) {
            new Filesystem()->remove($this->directory);
        }
        parent::tearDown();
    }

    public function test_the_shipped_file_holds_the_discovery_site_review_and_agent_review_rules(): void
    {
        $rules = $this->shippedAppRules()->rules();

        self::assertSame(['discovery', 'discovery-revise', 'discovery-approved', 'post-widget-review', 'sync-site-review-check', 'sync-agent-review-check', 'comment-agent-review'], array_map(static fn (Rule $rule): string => $rule->id, $rules));
        self::assertSame(RuleOrigin::App, $rules[0]->origin);
        self::assertSame('@backlog', $rules[0]->slot);
        self::assertSame(['kind' => 'discovery', 'onTimeout' => 'expire', 'onNoTaker' => 'fail', 'prompt' => 'discovery'], $rules[0]->lead->params);
        self::assertSame('@backlog', $rules[1]->slot);
        self::assertSame(['kind' => 'discovery-revise', 'onTimeout' => 'expire', 'onNoTaker' => 'fail', 'prompt' => 'discovery-revise'], $rules[1]->lead->params);
        self::assertSame('@backlog', $rules[2]->slot);
        self::assertSame(['to' => '@terminal'], $rules[2]->lead->params);
        self::assertNull($rules[3]->slot);
        self::assertSame(['write' => 'post-review'], $rules[3]->lead->params);
        self::assertNull($rules[4]->slot);
        self::assertSame(['write' => 'site-review-check'], $rules[4]->lead->params);
        self::assertNull($rules[5]->slot);
        self::assertSame(['write' => 'agent-review-check'], $rules[5]->lead->params);
        self::assertNull($rules[6]->slot);
        self::assertSame(['write' => 'comment', 'comment' => 'agent-review'], $rules[6]->lead->params);
        self::assertSame(file_get_contents(\dirname(__DIR__, 4).'/config/workflows/app/prompts/discovery.md'), $this->shippedAppRules()->prompt('discovery'));
        self::assertSame(file_get_contents(\dirname(__DIR__, 4).'/config/workflows/app/prompts/discovery-revise.md'), $this->shippedAppRules()->prompt('discovery-revise'));
    }

    public function test_the_shipped_file_names_the_slot_of_the_proposal_cards(): void
    {
        self::assertSame('next', $this->shippedAppRules()->proposalSlot());
    }

    public function test_a_file_with_no_proposal_slot_names_none(): void
    {
        self::assertNull(new AppRules($this->parser(), self::FIXTURE)->proposalSlot());
    }

    public function test_the_shipped_file_holds_the_analysis_request(): void
    {
        $requests = $this->shippedAppRules()->requests();

        self::assertCount(1, $requests);
        self::assertSame(['insights.analysis', 'analysis', 'analysis'], [$requests[0]->id, $requests[0]->kind, $requests[0]->prompt]);
        self::assertCount(2, $requests[0]->checks);
        self::assertSame(file_get_contents(\dirname(__DIR__, 4).'/config/workflows/app/prompts/analysis.md'), $this->shippedAppRules()->prompt('analysis'));
    }

    public function test_a_request_prompt_with_no_file_is_refused(): void
    {
        $directory = $this->directory(<<<'YAML'
            rules: []
            requests:
                - { id: app-report, kind: report, prompt: report, checks: [A skill] }
            YAML);

        try {
            new AppRules($this->parser(), $directory)->requests();
            self::fail('A missing prompt file must throw.');
        } catch (InvalidTemplate $e) {
            self::assertSame(['requests (app-report): prompt "report" has no file prompts/report.md'], $e->errors);
        }
    }

    public function test_a_request_that_shares_an_id_with_a_rule_is_refused(): void
    {
        $directory = $this->directory(<<<'YAML'
            rules:
                - { id: app-tidy, when: { all: [] }, then: { release: { reason: tidy } } }
            requests:
                - { id: app-tidy, kind: report, prompt: report, checks: [A skill] }
            YAML);
        new Filesystem()->dumpFile($directory.'/prompts/report.md', "Report.\n");

        try {
            new AppRules($this->parser(), $directory)->requests();
            self::fail('A shared id must throw.');
        } catch (InvalidTemplate $e) {
            self::assertSame(['requests (app-tidy): a rule has the same id'], $e->errors);
        }
    }

    public function test_the_shipped_app_rules_join_every_shipped_template(): void
    {
        $shipped = $this->service(ShippedTemplates::class);
        foreach ($shipped->keys() as $key) {
            $template = $this->parser()->parse($shipped->source($key));

            $joined = $this->shippedAppRules()->appendTo($template);

            self::assertEquals([...$template->rules, ...$this->shippedAppRules()->rules()], $joined->rules);
            self::assertEquals($template->onWorkFailed, $joined->onWorkFailed);
        }
    }

    public function test_a_rule_with_a_prompt_resolves_the_text_of_its_file(): void
    {
        $appRules = new AppRules($this->parser(), self::FIXTURE);

        self::assertSame(['app-groom', 'app-tidy'], array_map(static fn (Rule $rule): string => $rule->id, $appRules->rules()));
        self::assertSame(RuleOrigin::App, $appRules->rules()[0]->origin);
        self::assertSame("Groom the card.\n", $appRules->prompt('groom-card'));
    }

    public function test_a_prompt_that_no_rule_names_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new AppRules($this->parser(), self::FIXTURE)->prompt('other');
    }

    public function test_the_app_rules_come_after_the_template_rules(): void
    {
        $template = $this->parser()->parse($this->service(ShippedTemplates::class)->source('simple'));

        $joined = new AppRules($this->parser(), self::FIXTURE)->appendTo($template);

        self::assertSame(['merged', 'teardown', 'app-groom', 'app-tidy'], array_map(static fn (Rule $rule): string => $rule->id, $joined->rules));
        self::assertSame($template->slots, $joined->slots);
        self::assertSame($template->key, $joined->key);
    }

    public function test_the_app_rules_keep_every_other_field_of_the_template(): void
    {
        $parsed = $this->parser()->parse($this->service(ShippedTemplates::class)->source('simple'));
        $template = new Template(...[
            ...get_object_vars($parsed),
            'onWorkFailed' => new WorkFailurePolicy(['timeout'], 1, [5]),
            'childChoices' => ['inherit' => []],
            'epicBranch' => 'epic/{number}',
            'agentReviewFailingSeverities' => ['important', 'nit'],
            'approvalCoversConflictResolution' => false,
            'inbox' => ['paused'],
            'outcomes' => [new TemplateOutcome('merged', true)],
            'metrics' => [new TemplateMetric('fix-rounds-2', 'Fix rounds', 'The fix rounds.', MetricType::Count, new CountFilter(role: 'fix'))],
            'repositorySettings' => [new RepositorySuggestion(RepositorySuggestionKey::CiChecks, SuggestionMark::Warning)],
            'values' => [new TemplateValueKey('interactive', CardValueType::Bool, 'a.label', 'a.caption', 'a.help', 'a.history')],
        ]);
        foreach (new \ReflectionMethod(Template::class, '__construct')->getParameters() as $parameter) {
            if ($parameter->isDefaultValueAvailable()) {
                self::assertNotSame($parameter->getDefaultValue(), $template->{$parameter->getName()}, \sprintf('Give "%s" a value that is not its default, so the copy is checked.', $parameter->getName()));
            }
        }

        $joined = new AppRules($this->parser(), self::FIXTURE)->appendTo($template);

        self::assertEquals([...get_object_vars($template), 'rules' => $joined->rules], get_object_vars($joined));
    }

    public function test_a_named_prompt_with_no_file_is_refused(): void
    {
        $directory = $this->directory(<<<'YAML'
            rules:
                - { id: app-groom, when: { all: [] }, then: { request: { kind: groom, prompt: groom-card } } }
            YAML);

        try {
            new AppRules($this->parser(), $directory)->rules();
            self::fail('A missing prompt file must throw.');
        } catch (InvalidTemplate $e) {
            self::assertSame(['rules (app-groom): prompt "groom-card" has no file prompts/groom-card.md'], $e->errors);
        }
    }

    public function test_an_app_rule_that_shares_an_id_with_a_template_rule_is_refused(): void
    {
        $directory = $this->directory(<<<'YAML'
            rules:
                - { id: teardown, when: { all: [] }, then: { release: { reason: tidy } } }
            YAML);
        $template = $this->parser()->parse($this->service(ShippedTemplates::class)->source('simple'));

        try {
            new AppRules($this->parser(), $directory)->appendTo($template);
            self::fail('A shared rule id must throw.');
        } catch (InvalidTemplate $e) {
            self::assertSame(['rules (teardown): the template "simple" has a rule with the same id'], $e->errors);
        }
    }

    private function shippedAppRules(): AppRules
    {
        return $this->service(AppRules::class);
    }

    private function parser(): TemplateParser
    {
        return $this->service(TemplateParser::class);
    }

    private function directory(string $rules): string
    {
        $this->directory = sys_get_temp_dir().'/app-rules-'.bin2hex(random_bytes(6));
        new Filesystem()->dumpFile($this->directory.'/rules.yaml', $rules);

        return $this->directory;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function service(string $class): object
    {
        $service = self::getContainer()->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }
}
