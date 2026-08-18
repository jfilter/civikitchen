# rector setup

CiviCRM-specific modernization rules plus the off-the-shelf sets, installed
into `/opt/civikitchen-rector` and run by `ckmodernize`. `composer.lock` fixes
the tree so a rebuild cannot silently change what gets rewritten.

## Why each pin

| Package | Why |
| --- | --- |
| `rector/rector` | Pinned so a rebuild can't change the rewrites a repo gets. |
| `phpstan/phpstan` | CEILING: rector reads a private property (`$container`) of phpstan's `RichParser` that 2.2.6 removed, and its own constraint (`^2.2.2`) is loose enough to pick it up — every `ckmodernize` run then dies with `MissingPrivatePropertyException`. Pinned here until rector stops reaching into that internal. |

## Bumping
The lock is resolved against PHP 8.1.31 (`config.platform.php`), the floor the
images support — not against whatever PHP the maintainer runs. Without it a
re-lock on a newer laptop can pull a transitive that refuses to start on an
8.1/8.2 image, which is how `cktaint` was broken (see toolbelt/psalm/README.md).


Edit `composer.json`, run `composer update --working-dir=toolbelt/rector` and
commit both files. Check `ckmodernize` actually runs afterwards — the phpstan
coupling above is not expressible as a constraint.
