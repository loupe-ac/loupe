# Environment variables and their processors

Read this before you add an environment variable or choose the shape of its value.

Every environment variable is a string. A processor such as `%env(json:VAR)%` or `%env(csv:VAR)%` converts it. Know the failure mode of the conversion before you choose a shape.

## Do not put JSON in an environment variable

Symfony's dotenv strips the inner quotes, so `{"card":"abc"}` arrives as `{card:abc}`. Nothing raises. The value travels through the application and reaches its column unparseable.

Single quotes around the whole value survive. That does not fix the problem. The operator must then get the quoting right in `.env`, `prod.env`, `terraform.tfvars` and the compose file independently, and each has its own rules.

`%env(json:VAR)%` does not rescue this. It throws `Invalid JSON in env var` at request time, and not at build time. The container still compiles. A Twig global resolves on every render, so one malformed value answers every page of the application with a 500. A variable that only a preview instance sets can therefore take the whole site down.

## Prefer a shape that needs no quoting

A prefixed string such as `card:<uuid>` carries one fact. A query string such as `card=<uuid>&branch=feat/x` carries several and survives every config layer unquoted. `%env(query_string:VAR)%` reads it into an array.

## Where a new key goes

A new key must reach seven places:

- `.env`
- `terraform/variables.tf`
- `terraform/main.tf`
- `terraform/terraform.tfvars.example`
- `docker/compose/prod.yaml`
- `docker/compose/prod.env.example`
- `docs/reference/environment.md`

`DeploymentConfigParityCheck` covers the first six under `just gamache`. The documentation page is yours, and nothing goes red when you forget it.

`terraform.tfvars.example` names the HCL variable, so `site_review_widget_context` and not `SITE_REVIEW_WIDGET_CONTEXT`. A grep of that file for the environment key returns nothing, which reads exactly like a missing entry. Search for the lower-case variable name before you report one.
