<?php

declare(strict_types=1);

use App\Module\DesignSystem\Catalog;
use App\Module\DesignSystem\ComponentEntry;
use Gamache\Check\CommentBudgetCheck;
use Gamache\Check\DeploymentConfigParityCheck;
use Gamache\Check\DesignSystemAdoptionCheck;
use Gamache\Check\DesignSystemComponent;
use Gamache\Check\DesignSystemPattern;
use Gamache\Check\DesignSystemPatternCheck;
use Gamache\Check\FormTypeTranslationKeysCheck;
use Gamache\Check\MessengerRoutingCheck;
use Gamache\Check\NoArbitraryValuesCheck;
use Gamache\Check\NoTodosCheck;
use Gamache\Check\PageTitleBrandNameCheck;
use Gamache\Check\SelfContainedCommentsCheck;
use Gamache\Check\ServicesYamlCheck;
use Gamache\Check\ServiceTagNamesCheck;
use Gamache\Check\Severity;
use Gamache\Check\SkillReferenceCheck;
use Gamache\Check\TranslationCheck;
use Gamache\Check\TranslationParityCheck;
use Gamache\Check\TurboStreamTargetsCheck;
use Gamache\Check\XlfPluralizationCheck;
use Gamache\Config\GamacheConfig;
use PhpCsFixer\FixerConfiguration\FixerOptionBuilder;
use PhpCsFixer\FixerDefinition\FixerDefinition;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * The adoption check reads the catalog, so a new component is one catalog entry.
 * An old class an entry replaces is checked with the same own template.
 *
 * @param list<ComponentEntry> $entries
 *
 * @return list<DesignSystemComponent>
 */
function designSystemComponents(array $entries): array
{
    $components = [];
    foreach ($entries as $entry) {
        if ($entry->classOnly || null === $entry->template) {
            continue;
        }
        $own = 'templates/'.$entry->template;
        $components[] = new DesignSystemComponent($entry->name, $entry->rootClass, $own, $entry->enforced);
        foreach ($entry->legacyClasses as $legacy) {
            $components[] = new DesignSystemComponent($entry->name, $legacy, $own, $entry->legacyEnforced);
        }
    }

    return $components;
}

return (new GamacheConfig())->registerChecks([
    new ServicesYamlCheck(),
    new ServiceTagNamesCheck(),
    new MessengerRoutingCheck(),
    new NoArbitraryValuesCheck(),
    new NoTodosCheck(),
    new SelfContainedCommentsCheck(),
    new SkillReferenceCheck(
        patterns: ['.claude/skills/*/SKILL.md', 'AGENTS.md', '.loupe/lifecycle.md'],
        /*
         * Replaces the check's defaults rather than adding to them, so this
         * restates them and appends the top-level directories this project has
         * that they do not cover. `src/` and `tests/` stay out: skills cite
         * paths there to illustrate naming, not to point at real files.
         */
        pathPrefixes: [
            'assets/',
            'bin/',
            'cli/',
            'config/',
            'docker/',
            'docs/',
            'e2e/',
            'migrations/',
            'public/',
            'templates/',
            'terraform/',
            'translations/',
        ],
    ),
    new DeploymentConfigParityCheck(
        moduleProvidedEnvKeys: [
            /*
             * base_env in terraform-digitalocean-symfony-app, at the ref
             * terraform/main.tf pins. Only some of the module's arguments become
             * environment variables, so this cannot be derived from the argument
             * list — re-read the module when changing the pin.
             */
            'APP_ENCRYPTION_KEY',
            'APP_ENV',
            'APP_SECRET',
            'APP_SHARE_DIR',
            'DATABASE_URL',
            'DEFAULT_URI',
            'MAILER_DSN',
            'MERCURE_JWT_SECRET',
            'MERCURE_PUBLIC_URL',
            'MERCURE_URL',
            'MESSENGER_TRANSPORT_DSN',
        ],
        ignoredAppEnvKeys: [
            // Compose reads this one itself; it never reaches the app.
            'COMPOSE_PROJECT_NAME',
            // The committed .env value is the production answer, and matches the
            // module's own default.
            'MESSENGER_TRANSPORT_DSN',
            // The Sentry bundle's own default for `release`, seen only in the
            // generated config/reference.php. Unset, events carry no release.
            'SENTRY_RELEASE',
            // Development tooling: the IDE link, Symfony's own proxy and sendfile
            // overrides, the PHPUnit database suffix, the dump server and the
            // per-worktree database suffix, and the CI switch for the profiler.
            'PROFILER_COLLECT',
            'SYMFONY_IDE',
            'SYMFONY_TRUSTED_PROXIES',
            'SYMFONY_TRUST_X_SENDFILE_TYPE_HEADER',
            'TEST_TOKEN',
            'VAR_DUMPER_SERVER',
            'WORKTREE_DB_SUFFIX',
        ],
        documentationPath: 'docs/reference/environment.md',
        undocumentedEnvKeys: [
            // environment.md deliberately delegates the whole family to
            // docs/extending/object-storage.md, and says so where it stops
            // listing them. Every one of these is named there.
            'EXPORT_STORAGE_BUCKET',
            'EXPORT_STORAGE_ENDPOINT',
            'EXPORT_STORAGE_KEY',
            'EXPORT_STORAGE_PREFIX',
            'EXPORT_STORAGE_REGION',
            'EXPORT_STORAGE_SECRET',
            'EXPORT_STORAGE_USE_PATH_STYLE',
        ],
    ),
    new CommentBudgetCheck(
        /*
         * The justfile and the compose topologies join the default Symfony
         * layout, because they carry comment prose. `.env` stays out: its
         * comments are deployer documentation, and its header is Flex-managed,
         * so `composer recipes:update` restores what a trim removes.
         */
        patterns: [
            'src/**/*.php',
            'tests/**/*.php',
            'config/**/*.yaml',
            'templates/**/*.twig',
            'assets/**/*.js',
            'assets/**/*.css',
            'e2e/**/*.ts',
            'justfile',
            'compose.yaml',
            'docker/compose/prod.yaml',
        ],
        // Binding rather than advisory: an advisory check's green result says
        // nothing, and three over-budget blocks once shipped through three
        // consecutive green gates here. `@comment-budget-ignore` marks the
        // blocks that have earned their length.
        severity: Severity::Error,
    ),
    new DesignSystemAdoptionCheck(
        components: designSystemComponents((new Catalog())->entries()),
        // The split view draws the workspace frame itself when a page gives it a width.
        ignoredPaths: ['templates/components/Ds/SplitView.html.twig'],
    ),
    new DesignSystemPatternCheck(
        patterns: [
            new DesignSystemPattern(
                'Ds:RelativeTime',
                '/\|\s*relative_time\b/',
                'relative_time filter call',
                'templates/components/Ds/RelativeTime.html.twig',
                enforced: true,
            ),
            new DesignSystemPattern(
                'Ds:ActionForm',
                '~<form\b(?=(?:\{\{(?:[^}]|\}(?!\}))*+\}\}|[^>{]|\{(?!\{))*?\bmethod\s*=\s*["\']post["\'])(?:\{\{(?:[^}]|\}(?!\}))*+\}\}|[^>{]|\{(?!\{))*+>\s*<input\b[^>]*\bname\s*=\s*["\']_csrf_token["\'][^>]*>\s*<(button|twig:Ds:Button)\b(?:\{\{(?:[^}]|\}(?!\}))*+\}\}|[^>{]|\{(?!\{))*+>(?:(?!<(?:button|twig:Ds:Button)\b).)*?</\1>\s*</form>~is',
                'one-button form',
                'templates/components/Ds/ActionForm.html.twig',
                enforced: true,
            ),
        ],
        ignoredPaths: [
            'templates/Module/Account/admin',
            'templates/Module/Billing/admin',
        ],
    ),
    new FormTypeTranslationKeysCheck(),
    new TurboStreamTargetsCheck(),
    new XlfPluralizationCheck(),
    new PageTitleBrandNameCheck(),
    new TranslationParityCheck(),
    new TranslationCheck(
        /*
         * Strings passed to these constructors/methods are not user-facing prose.
         * Add entries as 'Namespace\Class::method' (method call) or
         * 'Namespace\Class' (constructor call).
         */
        ignoredCallSites: [
            // Symfony controller factory methods
            AbstractController::class.'::createNotFoundException',
            AbstractController::class.'::createAccessDeniedException',

            // PHP CS Fixer tool-framework strings (fixer descriptions and option builders)
            FixerDefinition::class,
            FixerOptionBuilder::class,

            // Rector tool-framework strings (rule descriptions)
            RuleDefinition::class,
        ],
        ignoreExceptionClasses: true,
        /*
         * Files whose FQCN matches are skipped entirely — these namespaces are
         * guaranteed to contain no user-facing prose.
         * Supports * (single segment) and ** (any depth).
         */
        ignoredSourceNamespaces: [
            'App\\**\\Command\\*',    // command handlers and console commands
            'App\\**\\Repository\\*', // Doctrine repositories (DQL/SQL only)
        ],
        /*
         * String arguments to PHP attributes whose class FQCN matches are skipped.
         * Covers framework config strings that are never user-facing prose.
         */
        safeAttributeNamespaces: [
            'Symfony\\Bridge\\Doctrine\\Attribute\\*',   // #[MapEntity(expr: '...')]
            'Doctrine\\ORM\\Mapping\\*',                 // #[JoinColumn(onDelete: 'SET NULL')]
            'Symfony\\Component\\Routing\\Attribute\\*', // #[Route(...)]
            'Symfony\\Component\\Console\\Attribute\\*', // #[AsCommand(description: '...')]
        ],
        /*
         * Twig filter/function names whose string arguments are never translatable.
         */
        safeTwigFunctions: [
            'date', // e.g. {{ value|date('M j, Y') }}
        ],
    ),
]);
