# phpcs installation root

This directory becomes the image's `COMPOSER_HOME` (`/opt/composer`), so
`composer global install` reads exactly these two files. It carries phpcs and
everything that rides on it: `cklint` (Drupal + CiviKitchen standards),
`ckcompat` (PHPCompatibility) and `ckdeps`.

The `civicrm/coder` fork is deliberately **not** here — it has no usable
release tags and is cloned at a pinned commit, see
`toolbelt/install-dev-tools.sh` for why a composer VCS repository is the wrong
tool for it.

## Why each pin

| Package | Why |
| --- | --- |
| `squizlabs/php_codesniffer` | Stays on the 3 line — `civicrm/coder` needs it, and under 4.0 the Drupal standard aborts outright ("Referenced sniff Squiz.CSS.* does not exist", plus the CSS/JS scanning removal). SECURITY FLOOR: below 3.13.6 the image scan fails on CVE-2026-67434 (OS command injection), so a re-lock is the way to take a phpcs security fix. |
| `dealerdirect/phpcodesniffer-composer-installer` | Registers installed standards with phpcs. A composer plugin, hence the `allow-plugins` entry. |
| `phpcompatibility/php-compatibility` | Powers `ckcompat` — does this code RUN on the declared PHP floor, the question phpstan's `phpVersion` does not answer. Pinned to an alpha deliberately: the last stable is 9.3.5 from 2019, which predates every PHP version these extensions target. 10.x is the line that knows PHP 8. |
| `slevomat/coding-standard` | Cherry-picked sniffs only (`DeclareStrictTypes` today); the full standard would fight the Drupal base the CiviKitchen ruleset is built on. 8.22.1 is also where the phpcs `^3.13.6` constraint lands the resolver on its own (8.23 needs php_codesniffer `^4.0.1`), so this is a drift pin, not a ceiling anyone has to hold. |
| `shipmonk/composer-dependency-analyser` | Powers `ckdeps`: `composer.json` against the dependencies the code really uses. |

## Bumping
The lock is resolved against PHP 8.1.31 (`config.platform.php`), the floor the
images support — not against whatever PHP the maintainer runs. Without it a
re-lock on a newer laptop can pull a transitive that refuses to start on an
8.1/8.2 image, which is how `cktaint` was broken (see toolbelt/psalm/README.md).


Edit `composer.json`, run `composer update --working-dir=toolbelt/phpcs-root`
and commit both files.
