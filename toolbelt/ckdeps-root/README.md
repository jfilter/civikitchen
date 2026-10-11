# ckdeps installation root

This directory becomes the image's `COMPOSER_HOME` (`/opt/composer`), so
`composer global install` reads exactly these two files. It carries the
dependency analyser behind `ckdeps`.

## Why each pin

| Package | Why |
| --- | --- |
| `shipmonk/composer-dependency-analyser` | Powers `ckdeps`: `composer.json` against the dependencies the code really uses. |

## Bumping
The lock is resolved against PHP 8.1.31 (`config.platform.php`), the floor the
images support — not against whatever PHP the maintainer runs. Without it a
re-lock on a newer laptop can pull a transitive that refuses to start on an
8.1/8.2 image, which is how `cktaint` was broken (see toolbelt/psalm/README.md).

Edit `composer.json`, run `composer update --working-dir=toolbelt/ckdeps-root`
and commit both files.
