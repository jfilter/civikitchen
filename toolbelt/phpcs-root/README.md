# phpcs installation root

This directory becomes the image's `COMPOSER_HOME` (`/opt/composer`), so
`composer global install` reads exactly these two files. It carries phpcs as
the engine of PHPCompatibility for `ckcompat`, and the dependency analyser
behind `ckdeps`. `cklint` lints with mago, not phpcs.

## Why each pin

| Package | Why |
| --- | --- |
| `squizlabs/php_codesniffer` | The engine PHPCompatibility runs on (it accepts 3.13.3+ and 4.x). SECURITY FLOOR: below 3.13.6 the image scan fails on CVE-2026-67434 (OS command injection), so a re-lock is the way to take a phpcs security fix. |
| `dealerdirect/phpcodesniffer-composer-installer` | Registers installed standards with phpcs. A composer plugin, hence the `allow-plugins` entry. |
| `phpcompatibility/php-compatibility` | Powers `ckcompat` — does this code RUN on the declared PHP floor, the question phpstan's `phpVersion` does not answer. Pinned to an alpha deliberately: the last stable is 9.3.5 from 2019, which predates every PHP version these extensions target. 10.x is the line that knows PHP 8. |
| `phpcsstandards/phpcsutils` | PHPCompatibility's base. A direct pin keeps it from drifting with that alpha. |
| `shipmonk/composer-dependency-analyser` | Powers `ckdeps`: `composer.json` against the dependencies the code really uses. |

## Bumping
The lock is resolved against PHP 8.1.31 (`config.platform.php`), the floor the
images support — not against whatever PHP the maintainer runs. Without it a
re-lock on a newer laptop can pull a transitive that refuses to start on an
8.1/8.2 image, which is how `cktaint` was broken (see toolbelt/psalm/README.md).


Edit `composer.json`, run `composer update --working-dir=toolbelt/phpcs-root`
and commit both files.
