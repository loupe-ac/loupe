# The five gamache layers

`ubermuda/gamache` enforces project conventions through five layers, each wired into a different tool. Check all five before you conclude that gamache has no rule for something. Most of them run under `just ci`, and not under `just gamache`.

| Layer | Package dir | Wired via | Run by |
|---|---|---|---|
| Convention checks | `src/Check/` | `gamache.php` | `just gamache` (`vendor/bin/gamache`) |
| PHPStan rules | `src/PHPStan/` | `extension.neon` + `parameters.gamache:` in `phpstan.dist.neon` | `just phpstan` / `just ci` |
| Rector rules | `src/Rector/` | `GamacheSetList::CONVENTIONS` in `rector.php` | `just rector` |
| PHP-CS-Fixer rules | `src/PhpCsFixer/` | `Gamache\PhpCsFixer\Fixers` in `.php-cs-fixer.dist.php` | `just cs-fix` |
| Twig-CS-Fixer rules | `src/TwigCsFixer/` | `GamacheStandard` in `.twig-cs-fixer.php` | twig-cs-fixer |

`just gamache` runs the `src/Check/` layer only, which holds the advisory and structural convention checks. The other four belong to the normal static-analysis pipeline. To answer "does a gamache check exist for this?", grep the package's `src/Check/`, `src/PHPStan/`, `src/Rector/`, `src/PhpCsFixer/` and `src/TwigCsFixer/`.

1. The PHPStan-rule layer sees only the files in PHPStan's `paths:` in `phpstan.dist.neon`. `migrations/` must stay in that list, or a migration-targeting rule such as `MigrationDescriptionRule` never runs. Add a new top-level source dir to `paths:` when a rule should police it.
2. gamache is an external package. Open a PR on https://github.com/ubermuda/gamache to add or change a rule in any of the five layers. Do not add a rule, check or fixer class to this project. `src/Utils/Gamache/` no longer exists.
3. Each `src/Check/` class takes constructor parameters, set in `gamache.php`. The PHPStan layer is configured under `parameters.gamache:` in `phpstan.dist.neon`.
