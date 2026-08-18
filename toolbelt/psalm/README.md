# psalm taint setup

Psalm used ONLY as a taint engine (`cktaint`), never as a second phpstan:
does request input reach a query, shell command, file path or redirect
unescaped? Installed into `/opt/civikitchen-psalm` together with
`psalm-taint.xml.dist` and the CiviCRM source/sink/escape stubs.

Isolated from the phpstan root on purpose: psalm brings ~50 packages of its
own (amphp, nikic/php-parser, felixfbecker/…), and the phpstan tree is the
gate every extension depends on — it must not be perturbed by another tool's
dependency resolution.

## The PHP floor in `config.platform.php`

`composer.json` resolves the lock against PHP **8.1.31**, not against the PHP
the maintainer happens to run. That floor is what makes `cktaint` work on the
images built for older CiviCRM lines: resolved against 8.3, the tree picks
`sebastian/diff` 7.x (`php >=8.3`), and psalm then dies at startup on an 8.2
image with "Your Composer dependencies require a PHP version >= 8.3.0" —
installed, but unusable, which is the worst of both.

8.1.31 is the lowest patch psalm 6.16.1 itself accepts; its `php` constraint is
patch-level (`~8.1.31 || ~8.2.27 || ~8.3.16 || …`), so an image on an older
patch of a line still fails loudly rather than silently skipping taint
analysis. Raising the floor is a decision about which images keep `cktaint`,
not a formality.

## Bumping

Edit `composer.json`, run `composer update --working-dir=toolbelt/psalm` and
commit both files. Verify a taint finding still reports on the lowest image
PHP, not just on your laptop — a lock that installs is not a lock that runs.
