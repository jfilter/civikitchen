<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Reporter;

/**
 * A CRM_ class whose file is not where PSR-0 says it is.
 *
 * CiviCRM autoloads CRM_Foo_Bar_Baz from CRM/Foo/Bar/Baz.php — underscores to
 * slashes, exact case. Put the class one letter off (CRM/Foo/Bar/baz.php, or
 * Forms/ where the class says Form_) and it still loads on a case-insensitive
 * macOS disk: the developer sees green, and the class is simply not found the
 * moment it is used on a Linux runner or a customer's server. The failure names
 * a missing class, not a misfiled file, so it reads as a different bug entirely.
 *
 * Checked against git, whose record of the path is case-exact however the local
 * filesystem folds it. The DAO files civix generates follow the same rule, so
 * they are not exempt — a drift there breaks just as hard.
 */
final class Psr0ClassPathCheck implements Check
{
    public function name(): string
    {
        return 'psr0-class-path';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        if (!$context->isGitRepo()) {
            return;
        }

        $misfiled = [];
        $checked = false;
        foreach ($context->trackedFiles() as $file) {
            if (preg_match('#(?:^|/)CRM/.+\.php$#', $file) !== 1) {
                continue;
            }
            $source = $context->read($file);
            if ($source === null) {
                continue;
            }
            $class = $this->crmClass($source);
            if ($class === null) {
                continue;
            }
            $checked = true;
            $expected = str_replace('_', '/', $class) . '.php';
            if (!str_ends_with($file, '/' . $expected) && $file !== $expected) {
                $misfiled[] = $class . ' is in ' . $file . ', PSR-0 wants …/' . $expected;
            }
        }

        if (!$checked) {
            return;
        }

        if ($misfiled !== []) {
            $reporter->fail(
                'CRM_ classes not at their PSR-0 path: ' . implode('; ', $misfiled)
                . ' — a case-only drift loads on macOS and fails on Linux'
            );
        } else {
            $reporter->ok('every CRM_ class sits at its PSR-0 path');
        }
    }

    /**
     * The first CRM_-prefixed class/interface/trait/enum a file declares.
     */
    private function crmClass(string $source): ?string
    {
        if (preg_match('/\b(?:class|interface|trait|enum)\s+(CRM_[A-Za-z0-9_]+)/', $source, $match) === 1) {
            return $match[1];
        }

        return null;
    }
}
