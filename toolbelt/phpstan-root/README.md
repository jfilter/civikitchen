# phpstan installation root

What the image installs into `/opt/civikitchen-phpstan`, and what `cklint`
runs. The versions live in `composer.json`; `composer.lock` fixes the
transitive tree so a monthly image rebuild cannot move the static-analysis
gate every extension depends on.

The CiviKitchen phpstan extension itself is **not** required here — it is a
local package (`toolbelt/phpstan`) and joins as a path repository during the
image build, see `toolbelt/install-dev-tools.sh`.

Not the standalone phar, because phpstan extensions are composer packages and
the phar cannot load one.

## Why each pin

| Package | Why |
| --- | --- |
| `phpstan/phpstan` | FLOOR: 2.2.3 is the lowest release `phpstan-phpunit` 2.0.18 accepts (`^2.2.3`), and every extension here resolves against it — keep the bump small. |
| `phpstan/phpstan-deprecation-rules` | Reports every call into code marked `@deprecated` (or `#[\Deprecated]`). Not a phpstan default and not implied by any level, including 10. This is how deprecated CiviCRM *symbols* get caught without anyone maintaining a list; the hook catalog in `ckconform` covers the one thing it structurally cannot see, since a hook implementation is a function definition matching a naming convention, not a call to a deprecated symbol. |
| `phpstan/extension-installer` | Registers the rules automatically, so no project has to add an `includes:` line to its `phpstan.neon`. A composer plugin, hence the `allow-plugins` entry. |
| `spaze/phpstan-disallowed-calls` | Bans as CONFIG instead of sniff code (`civicrm-disallowed.neon`). Inert until a project includes a config, so installing it changes nothing by itself. |
| `phpstan/phpstan-strict-rules` | Opt-in per repo — switching these on fleet-wide at level 10 would turn every repo red in one build. Hence the `extra.phpstan/extension-installer.ignore` entry: installed, not auto-registered. Repos opt in with one `includes:` line, see `docs/extension-standards.md`. |
| `phpstan/phpstan-phpunit` | Type narrowing for phpunit assertions — fewer level-10 false reports in tests. Inert until a repo has tests, so it registers automatically. |
| `phpat/phpat` | Architecture rules AS phpstan rules. Inert until a repo writes an `ArchitectureRule` class, so it registers automatically. |

## Bumping
The lock is resolved against PHP 8.1.31 (`config.platform.php`), the floor the
images support — not against whatever PHP the maintainer runs. Without it a
re-lock on a newer laptop can pull a transitive that refuses to start on an
8.1/8.2 image, which is how `cktaint` was broken (see toolbelt/psalm/README.md).


Edit `composer.json`, run `composer update --working-dir=toolbelt/phpstan-root`
and commit both files. There is no build-time override: an image whose tool
tree was resolved fresh at build time is not the image anyone reviewed.
