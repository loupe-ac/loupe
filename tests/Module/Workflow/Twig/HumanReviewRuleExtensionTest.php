<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Twig;

use App\Module\Workflow\Expression\AllOf;
use App\Module\Workflow\Template\ActionCall;
use App\Module\Workflow\Template\Rule;
use App\Module\Workflow\Template\Template;
use App\Module\Workflow\Template\TemplateMissing;
use App\Module\Workflow\Template\TemplateSource;
use App\Module\Workflow\Twig\HumanReviewRuleExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class HumanReviewRuleExtensionTest extends TestCase
{
    public function test_it_finds_the_rule_that_approves_a_pull_request(): void
    {
        $extension = $this->extension($this->rule('other', 'merge'), $this->rule('approve-it', 'approval-check', state: 'pass'));

        self::assertSame('approve-it', $extension->ruleId(Uuid::v4()));
    }

    public function test_the_approving_rule_wins_over_the_rule_that_requires_a_person(): void
    {
        $extension = $this->extension($this->rule('hold', null, 'require-human-review'), $this->rule('approve-it', 'approval-check', state: 'pass'));

        self::assertSame('approve-it', $extension->ruleId(Uuid::v4()));
    }

    public function test_it_falls_back_to_the_rule_that_requires_a_person(): void
    {
        $extension = $this->extension($this->rule('other', 'merge'), $this->rule('hold', null, 'require-human-review'));

        self::assertSame('hold', $extension->ruleId(Uuid::v4()));
    }

    public function test_it_returns_the_first_of_two_matching_rules(): void
    {
        $extension = $this->extension($this->rule('first', 'approval-check', state: 'pass'), $this->rule('second', 'approval-check', state: 'pass'));

        self::assertSame('first', $extension->ruleId(Uuid::v4()));
    }

    public function test_a_rule_that_revokes_the_approval_check_does_not_approve(): void
    {
        $extension = $this->extension($this->rule('revoke-it', 'approval-check', state: 'revoke'));

        self::assertNull($extension->ruleId(Uuid::v4()));
    }

    public function test_it_returns_null_when_no_rule_matches(): void
    {
        $extension = $this->extension($this->rule('other', 'merge'));

        self::assertNull($extension->ruleId(Uuid::v4()));
    }

    public function test_it_returns_null_for_a_project_with_no_template_copy(): void
    {
        $projectId = Uuid::v4();
        $source = new readonly class($projectId) implements TemplateSource {
            public function __construct(
                private Uuid $projectId,
            ) {
            }

            #[\Override]
            public function forProject(Uuid $projectId): Template
            {
                throw new TemplateMissing($this->projectId);
            }
        };

        self::assertNull(new HumanReviewRuleExtension($source)->ruleId($projectId));
    }

    private function rule(string $id, ?string $write, string $action = 'forge-write', ?string $state = null): Rule
    {
        $params = null === $write ? [] : ['write' => $write];
        if (null !== $state) {
            $params['state'] = $state;
        }

        return new Rule($id, null, new AllOf([]), [new ActionCall($action, $params)]);
    }

    private function extension(Rule ...$rules): HumanReviewRuleExtension
    {
        $template = new Template('test', 1, [], array_values($rules), [], [], [], 'feature');
        $source = new readonly class($template) implements TemplateSource {
            public function __construct(
                private Template $template,
            ) {
            }

            #[\Override]
            public function forProject(Uuid $projectId): Template
            {
                return $this->template;
            }
        };

        return new HumanReviewRuleExtension($source);
    }
}
