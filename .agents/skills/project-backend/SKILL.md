---
name: project-backend
description: "Use when working on PHP code under `src/`: forms, DTOs, Doctrine entities, controllers, commands or flash messages, or when changing a gamache rule or its configuration."
---

# Backend: forms, DTOs, Doctrine, controllers, flash messages

## Module boundaries

Modules may import each other. `phparkitect.php` forbids the dependency directions that would close a cycle between modules, and its `because` text says why for each one. Run `just arkitect`. Do not suppress a reported violation. Redesign instead.

These patterns cover a cross-module need that a forbidden direction blocks:

- Put a shared abstraction in the owning module. If module Y extends a base class from module X, add an abstract property hook or interface method to the base in module X. Each subtype implements it in its own module. Callers in module X read the abstract member and never learn the concrete type.
- Use events for a side effect that crosses a boundary. Dispatch a domain event from the originating module and handle it in the receiving module.
- Put a truly generic shared value object, such as a `Slug`, in the root namespace `src/`.

A module that feeds facts to the workflow engine imports only `App\Module\Workflow\Contract`. Read `references/workflow-facts.md` first.

## Read models

A structured read model consumed by several controllers and templates is an immutable `final readonly` value object with query methods. Do not return an `array{a: …, b: …}` shape.

Promote the array to a value object when it grows past about 2 keys, or when templates start per-element lookups such as `grid.checked[row.id ~ ':' ~ user.id] is defined`. A `Builder` or `Factory` service builds the value object. The value object exposes intent-named methods, such as `isChecked($rowId, $user)` and `participantsFor($rowId)`, which absorb the template's index-poking. An array shape spreads its key construction across every consumer, and only a PHPDoc enforces it.

## Forms and DTOs

A Symfony form (DTO plus FormType) binds all user input for HTML and Turbo endpoints. Never hand-parse it from the `Request`. This includes Turbo, AJAX and stream endpoints that render UI. There is no "it is just a small POST" exception. Do not write `$request->request->get('x')` with ad-hoc `is_numeric` or `trim` validation in a controller. Create a `FooRequest` DTO with constraints and a `FooFormType`, then call `createForm()`, `handleRequest()` and `isSubmitted() && isValid()`.

Two raw-request reads stay acceptable: a CSRF token on a hand-rolled fieldless form, and a dev-only or test-only controller. A fieldless POST action may use either that hand-rolled shape or a real form, so read `references/csrf.md` before you choose. JSON API endpoints are also an exception, because they use `#[MapRequestPayload]`. See "API controllers" below.

- Forms must not bind directly to Doctrine entities.
- Create a DTO with a `Request` suffix, such as `FooRequest`, next to the form file.
- DTOs use constructor-promoted properties with sensible defaults.
- A create-time default value lives in the form DTO, and the entity default does not decide it. When an entity is created through the command and handler flow, the handler sets the field from the command (`$entity->x = $command->x`). The entity's property default is then dead on that path, and a change to it alone does nothing. The real levers are the `*Request` DTO property default, which drives both the pre-filled form and the submitted value, and any handler that calls `new Entity(...)` without setting the field, such as an import or seed handler. Change all of these together, and keep the entity default consistent with the DTO.
- Give a DTO a static named constructor, such as `FooRequest::fromFoo($entity)`, only when an edit flow pre-populates the form. An unused static constructor is dead code.
- When an update DTO has the same fields and constraints as its create counterpart, extend it: `class UpdateFooRequest extends CreateFooRequest {}`. PHP attribute inheritance on promoted properties works correctly with the Symfony Validator. Duplication means a future constraint change must be applied twice.
- Validation constraints live on the DTO. The PHPStan `form.inlineConstraints` rule reports a `constraints` option in any `buildForm()`, with or without a `data_class`.
- Property constraints such as `#[NotBlank]` and `#[Length]` go on individual promoted properties.
- A class-level constraint, which needs the whole DTO or a related object, goes as `#[MyConstraint]` on the class. The validator receives the whole DTO as `$value` and must declare `#[\Override] public function getTargets(): string { return self::CLASS_CONSTRAINT; }`. Name the constraint after what it enforces, and never after the mechanism.
- Controllers pass `new FooRequest()`, or `FooRequest::fromFoo($entity)` for an edit form, to `createForm()`. They then map the DTO back onto the entity after a successful submission.
- `#[UniqueEntity]` on an entity does not fire when the form's `data_class` is a DTO, because validation runs on the DTO. Check uniqueness in the handler and throw `DomainErrors`, as "DomainErrors, the command-to-controller bridge" says. The gamache rule `ControllerNoDirectStateAccessRule` refuses a repository query in a controller.
- When a DTO property is nullable but cannot be null after validation, do not use `assert()`. Write `if (null === $data->prop) { $form->addError(new FormError(...)); } else { ... }` so the user gets a form error.
- Every `AbstractType` subclass declares `@extends AbstractType<DataClass>`, or `@extends AbstractType<array<string, mixed>>` for a form with no data class. PHPStan and the IDE then infer the return type of `$form->getData()`.
- When a template loop needs one form per item, such as a delete button per row, do not build the forms in the controller and pass an array. Add a Twig function in the module's `Twig/*Extension.php` and call it in the template.
- When such a per-row form is also submitted and re-rendered, create it with `FormFactoryInterface::createNamed('<prefix>_'.$id, …)`. The rendered `id` and `name` attributes then do not collide across rows. The receiving controller must rebuild the form with the same name before `handleRequest()`. Extract the name to a shared `public static` helper so both sides agree. Pass the bound `FormView` back into that row's component or partial to surface validation errors.
- `form_rest()` renders all unrendered fields, including a `CollectionType` field. To place a collection's submitted values at a specific position, such as inside a Stimulus controller div, render them there with a `{% for field in form.collection %}` loop. Once rendered, `form_rest` does not re-render them.
- `CollectionType` errors bubble to the root form by default. Render them once with `form_errors(rootForm)` at the top of the form. Do not also call `form_errors(field)` for the `CollectionType` field, because that double-renders the error. Do not set `error_bubbling: false` unless you want field-level display and have verified that `form_rest` does not render them a second time.
- `AbstractType::getBlockPrefix()` converts camelCase to snake_case word by word, so a compound word becomes two fragments. `ImportGitHubRepoFormType` strips `Type`, then becomes `import_git_hub_repo_form`, and never `import_github_repo_form`. An abbreviation such as `GitHub`, `OAuth` or `API` in a class name becomes two snake_case words. If you remove an explicit `getBlockPrefix()` override, audit every test that submits form fields by name. Change those strings to the derived prefix. The symptom of a stale field-name string is `InvalidArgumentException: Unreachable field` at test runtime.

## Doctrine

- Never edit a migration directly. Update the entity and let `just migrate-diff` generate the migration. One exception follows.
- When you remove or modify a column on a table that the current branch also first created, and that is not yet in `main`, edit the create-migration directly to the final schema. Do not generate an ALTER migration for a table that no deployed database has seen.
- Replace the `return '';` that `just migrate-diff` scaffolds in `getDescription()` with a one-line summary, such as `'Create poll tables'`.
- Use `#[ORM\OneToOne]` for an exclusive has-one relationship, and never `#[ORM\ManyToOne]`. `OneToOne` implies a `UNIQUE` constraint on the FK column, so remove a redundant manual `#[ORM\UniqueConstraint(columns: ['...'])]`. Verify with `just migrate-diff`. "No changes detected" confirms the schema is consistent.
- Doctrine entities use property hooks, no getters and no setters.
- Entities are always valid. All data required at construction time is a constructor-promoted parameter. A lifecycle field set after construction, such as `$usedAt` or `$lastLoginAt`, stays a regular nullable property.
- Entity properties are plain `public`. Use no asymmetric visibility except on `$id`, which is `public private(set) ?Uuid $id = null`. Doctrine sets it after `flush()`, and a caller must never assign it. Asymmetric visibility on plain-data properties adds friction in tests and handlers with no real encapsulation benefit.
- Constructor promotion is safe for Doctrine entities.
- `new \DateTimeImmutable()` is a valid default constructor parameter value, so use it for a timestamp field such as `$createdAt`. A static method call, such as `Ulid::generate()`, is not a valid default and must run in the constructor body.
- Property hooks can be inlined on promoted properties.
- Entities must not contain logic that needs a service, such as slugging or URL generation. A computed property that depends on a service belongs in that service, and never in a virtual property hook.
- Several handlers or services may compute the same derived value from an entity's own fields with no service dependency, such as a canonical path or a storage key. Add a `public static function computeFoo(...): string` to the entity for it. Make it static, because callers often hold only raw identifiers. This gives one source of truth and prevents drift.
- Repositories handle persistence only: queries, existence checks and writes. Logic that needs a non-persistence service, such as a generator, slugger or encoder, belongs in an Action or a dedicated service.
- Inject the concrete repository class in the constructor. You may inject `EntityManagerInterface` for `flush()`, but every read goes through an injected repository.
- Entities use ULID primary keys by default. Use an integer id only where a sequence is semantically appropriate.
- Converting a string column to a backed enum needs no migration, because the column already stores the backing values. Keep `length:` and add `enumType:`:
  ```php
  // Before
  #[ORM\Column(length: 20)]
  public readonly string $status,

  // After — keep length:, add enumType:
  #[ORM\Column(length: 20, enumType: FooStatus::class)]
  public readonly FooStatus $status,
  ```
  Doctrine stores and reads the enum's `->value` in the same varchar column. Verify with `just migrate-diff`. "No changes detected" means no migration is needed. A proposed `ALTER` means the backing values or the column definition drifted, so resolve that first. PHPStan level 8 then enforces correct usage at every instantiation site, and a string literal in the constructor becomes a type error.
- Doctrine auto-creates an index for every `ManyToOne` join column and nothing else. It creates no index for a non-FK filter or sort column, such as a status enum, a `type` or a token hash. It creates none for a composite such as `(tenant_id, status, created_at)` either. Before you add an index, verify what exists against the live database with `SELECT … FROM pg_indexes WHERE tablename = …`. Declare a missing index with `#[ORM\Index]` on the entity and generate the migration. Add a composite index for a hot query that filters and sorts together, such as a tenant-scoped list ordered by `created_at`.

## Controllers

- Name a controller `<Action><Entity>Controller`, such as `CreateFooController`, `ShowFooController` and `ListFooController`.
- Do not extract a single-use private response-builder helper. Inline it into `__invoke`. This applies to a private method that exists only to assemble and return a `Response`, such as a render-or-redirect, a stream-or-redirect or a JSON wrapper, when one or two places in the same controller call it. Converge the exit paths into a single tail. For example, collapse two error branches into one `$errorMessage` variable followed by one redirect-fallback and render block. This applies to thin response wrappers only. A single-use private method that computes a value through real logic, such as building an error string from `$form->getErrors()`, may stay.
- Async tasks use Symfony Messenger with a Doctrine transport. Message classes live in `Messenger/` within the module, for example `src/Module/Account/Messenger/GenerateDataExportMessage.php` in namespace `App\Module\Account\Messenger`. The routing entry in `config/packages/messenger.yaml` must match the PHP namespace exactly. gamache enforces this.
- To re-render a page with an invalid form from another controller, pass the FormView as a request attribute to `forward()`: `$this->forward(ShowFooController::class, array_merge($params, ['myForm' => $form->createView()]))`. In the receiving controller, retrieve it with `$this->getInjectedFormView($request, 'myForm')` and fall back to a fresh form: `$form = $this->getInjectedFormView($request, 'myForm') ?? $this->createForm(...)->createView();`. Always pair this with a 422 status code. See the `project-frontend` skill.

`AppController` lives in `src/Controller/AppController.php` and has two helpers:

- `renderFormResponse(string $view, FormInterface $form, array $extra = []): Response` sets HTTP 422 on an invalid submit. Do not chain `->setStatusCode(...)` instead.
- `getInjectedFormView(Request $request, string $key): ?FormView` retrieves a `FormView` forwarded through a request attribute.

Use `$this->getUser()` to retrieve the authenticated user. Do not inject `Symfony\Bundle\SecurityBundle\Security` into a controller only to call `getUser()`.

For PHPUnit patterns, including WebTestCase mocking, controller integration tests and mock discipline, see `project-testing`.

## Pre-delivery gate

Run the gate in `working-with-prs` before you mark a PHP task done. Never suppress an error with `@phpstan-ignore` without a comment that explains why.

Keep the explicit excludes and the zero-match throw in `.php-cs-fixer.dist.php`. With `ignoreVCSIgnored(true)`, `just cs` matches no file in a worktree and passes with no fix.

## Property access in all PHP classes

Use `public private(set)` only when external mutation would cause a real problem, such as an immutable identity like `$id`. Do not use a `{ get => $this->prop; }` property hook as a visibility workaround, because that is a public property with extra steps. Property hooks are for computed or validated values only. An interface-required method, such as `getPassword()`, `getRoles()` or `getUserIdentifier()`, stays a method.

## Code quality targets

The project runs PHP 8.5+, PHPStan level 8, the Symfony coding standard through CS Fixer, and Rector for modernization.

Use PHP 8.5 features actively: the pipe operator `|>` for sequential transformations, property hooks, and `new` in initializers. Do not write PHP 8.0-style code when a cleaner 8.5 construct exists. An arrow function on the right-hand side of `|>` needs parentheses: `$x |> (fn ($v) => transform($v)) |> nextFn(...)`.

Add `#[\Override]` to a method that implements an interface method. `just rector` adds it only to a parent-class override.

## PHPStan annotations and narrowing

`@var` is permitted only to narrow a type that PHPStan cannot infer, such as a union after a `getRepository()` call. Never use it for type checking. Use an explicit guard.

When a variable cannot be null after validation but PHPStan cannot prove it, do not use `assert()` blindly. Handle it explicitly with a `FormError`, an early return or a thrown exception.

To pass a `?string` DTO value where a `non-empty-string` is required, write `$dto->field ?: throw new \LogicException('field required after validation')`. PHPStan reads the `?:` truthy check as `non-empty-string` narrowing. Do not use a `(string)` cast or `@phpstan-ignore` to silence it.

Be careful with `?:` on a field whose value can legitimately be `"0"`, such as a name or a label. `?:` treats the string `"0"` as falsy, and Symfony's `NotBlank` accepts `"0"`. Such a value passes validation and then throws a 500 in the handler. For such a field, write an explicit guard instead: `if (null === $x || '' === $x) { throw … }`. PHPStan narrows to `non-empty-string` the same way.

PHPStan level 8 reports `nullsafe.neverNull` when it can prove the operand is non-null. Take `$rawSlug = $request?->attributes->get('slug'); if (!is_string($rawSlug)) { return; }`. After it, `$request` is transitively non-null, because a null `$request` would produce `null` and fail `is_string()`. Use `$request->` after such a guard, and never `$request?->`.

When `#[IsGranted(...)]` gates a controller and its voter guarantees a fact, resolve the value and assert the invariant with `?? throw new \LogicException('<what the gate guarantees>')`. An example fact is that the current user is a member of the subject. Do not branch on null and degrade with no signal. Passing a value that cannot be null as nullable into a template or handler is misleading. The `?? throw` documents the guarantee and fails loudly if the gate changes.

## Flash messages

- For a simple flash message, call `->addFlash('<type>', '<flash message>')`.
- `base.html.twig` shows the types `success`, `error`, `warning` and `info`. For a message that one page shows in its own place, use a type of its own, such as `->addFlash('bridge-pause', $message)`. Read it in that template with `app.flashes('bridge-pause')`.

## Domain observability

When code makes a domain decision that no audit record holds, log it at `info` level with a structured context array. Use `warning` for an unexpected but recoverable situation.

Log these events:

- Access denied by a business-rule gate, at `info`, with the relevant IDs and the rule that fired.
- A controlled skip or redirect driven by state, at `info`, with the reason.

A handler records a create, update or delete through `Auditor`, and does not log it again. Read "Auditing a domain event" in `project-command-handler`.

Inject `LoggerInterface` through the constructor and call `$this->logger->info('event.name', ['key' => $value])`. This is the same in a controller and in a service. `AppController` has no logger helper.

Name the event `<module>.<outcome>`. It has exactly two segments: the module first, then one snake_case outcome phrase. For example, `foo.created` or `foo.bar_access_denied`. Always include entity IDs in the context, plus any flag or count that explains the decision.

## DomainErrors, the command-to-controller bridge

Read "DomainErrors, the domain validation failure channel" and "Handler composition" in `project-command-handler`. They hold the catch block, the uniqueness and precondition checks in the handler, and the `in_array` fallback.

`{{ form_errors(form) }}` renders only the errors attached to the form root through `$form->addError()`. It does not show an error mapped onto a child field through `$form->get('fieldName')->addError(...)`, including one added by the `DomainErrors` catch block. For any `HiddenType` field that can receive a `DomainErrors` error, add `{{ form_errors(form.fieldName) }}` after `{{ form_widget(form.fieldName) }}` in the template.

## Command and handler pattern

Invoke the `project-command-handler` skill before you write a command or a handler.

## API controllers for JSON endpoints

A controller that consumes or produces JSON for a machine client, such as a widget, an SPA or a third party, is an API controller. It follows different conventions from a UI controller.

- The gamache `route.apiConsistency` rule ties the `/api/` path, the `api_` route name and the `Controller\Api` namespace together. Keep an existing public path stable after you move the class, because external callers, CORS subscribers and firewall `access_control` rules key on the path.
- Bind input with `#[MapRequestPayload]` on a request DTO parameter. Symfony deserializes the JSON body, runs the validator, and returns 422 on failure.
  ```php
  public function __invoke(#[MapRequestPayload] SubmitBatchRequest $payload): JsonResponse
  ```
- The DTO lives beside the controller, in the same `Controller/Api/` directory and namespace. Do not put it in a separate `Dto/` or `Form/` directory. Name the top-level payload `<Action>Request` to mirror its controller, so `SubmitBatchController` takes `SubmitBatchRequest`. This is convention only, because the gamache `dto.requestSuffix` rule enforces the suffix only for DTOs in a `Form/` namespace.
- A nested DTO is not a request. A collection item or sub-object inside the payload exists so the Serializer hydrates a typed element and `#[Assert\Valid]` can cascade per item. It has no controller, so do not give it a `*Request` suffix, which wrongly implies a top-level payload. Name it for what it holds, such as `SiteReviewCommentInput`. It lives beside the payload.
- The DTO is a plain class with constructor-promoted public properties and validation constraints. An `#[Assert\NotBlank]` property must be `?string`, which the gamache `dto.notBlankNotNullable` rule enforces. Narrow it at the boundary where you map to the command, with `$dto->field ?? ''`, or `?: throw` for a `non-empty-string`. A nested collection needs `#[Assert\Valid]` to cascade, plus a PHPDoc `@param list<ItemInput> $items` so the Serializer knows the element class. Use `#[Assert\Count(min: 1)]` to reject an empty collection with a 422.
- Normalise a payload field on the DTO, and never in the controller. A controller that carries a `private function fooOf(FooRequest $payload)` has put a rule about the wire shape in the wrong class. Give the DTO a method instead, so the rule is testable with no kernel and every caller gets the same answer. This does not conflict with the public-property rule above, because such a method transforms the value instead of exposing it.
- A DTO method may share its property's name. `AddCommentRequest` has both a promoted `public ?string $context` and a `context()` method that trims it and maps a blank value to null. Neither `#[MapRequestPayload]` nor the validator reads the method as a second source for the field. The Serializer hydrates through the constructor, and the validator reads a property constraint by reflection. Prove it with a test that drives a real value, a blank one and an absent one through the endpoint. Do not reason about the Serializer instead.
- Path-scoped subscribers and firewall rules handle CORS and auth, and the controller does not. See the firewall notes in `project-authz` and the per-endpoint CORS subscriber pattern. The 401, 403 and preflight paths run before the controller, so `#[MapRequestPayload]` never sees an unauthenticated request.

## Route conventions

Spell every `{placeholder}` in a `#[Route]` attribute out in full: `{organizationSlug}`, never `{orgSlug}`. Update `path()` calls in templates to match.

When a route parameter corresponds to an entity, declare the mapping in the route path with `{param:variable}` notation. Type-hint the controller parameter as the entity class. Never inject a repository into a controller only to do a slug lookup. Invoke the `symfony-entity-route-mapping` skill before you write or change an entity-resolving route. It covers `#[MapEntity]` forms, the `{param:alias}` request-attribute rename, the raw-string semantics of `expr` variables, and parent-scoped multi-entity lookups for cross-tenant safety.

Use `{slug:org}` for the org route parameter, and not `{organizationSlug}`, unless the route already has another `slug` parameter. When two entities with the same field name appear in one route, keep the full descriptive name for the second entity's parameter.

Entity context belongs in the URL path, and never in the query string. When a page operates in the context of an entity, that context is a path parameter. A controller may serve two structural contexts, such as "import a new project" and "attach a repo to an existing project". Add a second `#[Route]` attribute for the variant, and do not switch on a query parameter.

## Enum backing values

Use no abbreviations. Spell every word out in full, for example `case Implementation = 'implementation'`, and never `'impl'`. Backing values are persisted in the database, so a later rename needs a data migration.

For authorization conventions, including Voters and `#[IsGranted]`, see `project-authz`.

## Dependency injection

Tag a service with `#[AutoconfigureTag]` on the interface, and consume it with `#[AutowireIterator('app.my_tag')]`.

```php
#[AutoconfigureTag('app.my_tag')]
interface MyInterface { ... }
```

Read an environment variable with `#[Autowire(env: '...')]` on the constructor parameter. Read `references/env-vars.md` before you add a variable or choose the shape of its value.

```php
#[Autowire(env: 'int:GITHUB_APP_ID')]
private readonly int $appId,
```

Read a container parameter with `#[Autowire(param: 'app.mailer.from_address')]`. The goal is zero explicit service definitions in `services.yaml` beyond the `App\:` resource block.

A string constant that represents a project-wide configuration choice belongs in `config/services.yaml` under `parameters:` with the `app.` prefix. Examples are a path prefix, a branch prefix, a base directory or a sender name. Inject it with `#[Autowire(param: 'app.param_name')]`. Do not hardcode a string that a different deployment might want configured. Ask whether someone who forked and rebranded this app would need to change the string. If yes, it is a parameter.

When an interface has exactly one concrete implementation that should be autowired everywhere the interface is type-hinted, put `#[AsAlias(InterfaceName::class)]` on the concrete class. No `services.yaml` entry is needed.

```php
#[AsAlias(ApiKeyResolver::class)]
final readonly class ConversationApiKeyResolver implements ApiKeyResolver { ... }
```

Use `#[AutoconfigureTag]` with `#[AutowireIterator]` only when several implementations must co-exist, such as a strategy collection.

A utility that depends on a configuration value, an env var or a container parameter, is an injectable service, and never a static helper. Inject the value once in the constructor, and do not pass it to every static method. Callers then inject one service instead of each re-wiring the same configuration. A static helper with configuration parameters spreads `#[Autowire]` across the codebase and makes unit testing harder.

```php
// ✓ — service owns the config; callers inject one dependency
final readonly class TopicBuilder
{
    public function __construct(
        #[Autowire(param: 'app.url')]
        private string $appUrl,
    ) {}

    public function forConversation(Uuid $id): string
    {
        return rtrim($this->appUrl, '/').'/conversations/'.(string) $id;
    }
}
```

## Nullability: production decides, never the tests

Tests must never dictate what is nullable. Whether a constructor parameter is nullable is a domain question, "is `null` a valid runtime state?", and production code answers it. A test that cannot supply a required collaborator has a test problem. Solve it in the test with a stub, or a no-op behind an interface. Never relax the production signature to `?Type = null`.

- If a dependency is always present in real use, make it required and update every construction site.
- A `LoggerInterface` is never nullable, because `NullLogger` exists.
- A heavy collaborator that a lightweight test cannot build gets a required interface plus a no-op implementation, and never a nullable parameter.
- Do not default to `null` merely to avoid touching call sites.

## Custom static-analysis rules

Custom PHPStan, PHP CS Fixer, Rector and TwigCsFixer rules live in the `ubermuda/gamache` package, in `vendor/ubermuda/gamache/src/`, consumed as `dev-main`. They are not in this repo. When a check fires for something that is not in the standard PHPStan, Rector or TwigCsFixer docs, look there first. The rule class names in the error output match the class names in that package. Add a new rule in the gamache repo. Run `just cs` and `just phpstan` to exercise all of them.

gamache rules enforce many route, API binding, form, DTO, controller and `services.yaml` conventions that this skill does not repeat. Their error message states the fix, so read it.

Read `references/gamache.md` before you conclude that gamache has no rule for something, or before you change a rule's configuration. gamache has five layers in five tools, and `just gamache` runs only the `src/Check/` layer.

## Event listeners

Use `#[AsEventListener]` on a single-event listener, in preference to `EventSubscriberInterface`.

```php
#[AsEventListener]
final readonly class SendWelcomeEmailOnUserRegistered
{
    public function __invoke(UserRegistered $event): void { ... }
}
```

A module-scoped listener lives in `src/Module/X/EventListener/`. Use the root `src/EventSubscriber/` only for an infrastructure-level framework listener, such as profiler control or coverage collection. Name a single-event listener `{Action}On{EventName}`, such as `SendWelcomeEmailOnUserRegistered`.

For a cross-module side effect, dispatch a domain event from the originating module and handle it in a listener in the receiving module. That listener imports the event class from the originating module. That import is acceptable, because events are the intended public API. The listener must not import an entity or a service from the originating module beyond the event class.

## Service/ for shared module logic

When more than one handler or controller in a module needs the same business logic, extract it to `src/Module/*/Service/`. A `Service/` class has a single responsibility, is injected through the constructor, and is autowired automatically.

For example, each `*EmailSender`, such as `VerificationEmailSender`, builds and dispatches a transactional email for several controllers.

Do not create a `Service/` class for logic called from one place. Keep it in the handler or controller where it lives. Duplication sets the extraction threshold, and size does not.

## Topics in references/

Read the file before you work on the topic.

- `references/csrf.md` before you write any fieldless POST action, a hand-rolled form with a manual `_csrf_token`, or a hand-rolled POST to a Form-component endpoint. It carries the rule that picks between the `#[CsrfToken]` attribute and a real Symfony form. A literal token ID gives a 403 in production while the tests pass.
- `references/scheduled-jobs.md` before you add a recurring job. Use `#[AsCronTask]` on a task class in `src/Module/*/Scheduler/`. Never add a schedule provider.
- `references/email.md` before you send, build or test an email. Delivery is asynchronous, the sender address is a container parameter, and tests use `assertQueuedEmailCount()`.
- `references/admin-listing.md` before you add or change an admin list, edit, delete or toggle action. The list must preserve filter and sort state through `returnTo`.
- `references/session.md` before you add a route that a Stimulus controller, a Turbo frame reload or a live update calls in the background, or code that writes the session. Mark such a route with `ReadOnlyAwareSessionHandler::READ_ONLY`. An unmarked GET keeps its session write, which can overwrite a concurrent POST. A marked route drops every session write.
- `references/http-and-concurrency.md` before you call an external service through `HttpClientInterface`, or write a read-check-write handler that a concurrent request could race.
- `references/workflow-facts.md` before a module feeds facts, a condition or a re-evaluation listener to the workflow engine. Import only `App\Module\Workflow\Contract`.
- `references/env-vars.md` before you add an environment variable. Never put JSON in one. A new key reaches seven places, and `DeploymentConfigParityCheck` reports one that `docs/reference/environment.md` lacks.
