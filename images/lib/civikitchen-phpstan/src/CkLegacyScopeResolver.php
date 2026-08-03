<?php

declare(strict_types=1);

namespace CiviKitchen\PHPStan;

use PHPStan\Analyser\Scope;
use PHPStan\Rules\Deprecations\DeprecatedScopeResolver;

/**
 * Treats code marked `@ck-legacy` as a deprecated scope, so calls it makes into
 * deprecated APIs are not reported.
 *
 * phpstan-deprecation-rules only exempts scopes that are themselves
 * `@deprecated`. That is wrong for a test of a deprecated API: the test is
 * living code, and marking it `@deprecated` would claim callers should stop
 * using the test. `@ck-legacy` states the real intent — this code exists to
 * exercise something deprecated — and it disappears together with the shim or
 * test it annotates.
 *
 * The marker counts on the surrounding class/trait or on the individual
 * method/function; the narrower placement is preferred.
 */
final class CkLegacyScopeResolver implements DeprecatedScopeResolver
{

    /**
     * Exact tag: `@ck-legacy-ish` or `@ck-legacyfoo` must not match.
     */
    private const MARKER_PATTERN = '/@ck-legacy(?![\w-])/';

    public function isScopeDeprecated(Scope $scope): bool
    {
        $function = $scope->getFunction();
        if ($function !== null && $this->hasMarker($function->getDocComment())) {
            return true;
        }

        foreach ([$scope->getClassReflection(), $scope->getTraitReflection()] as $class) {
            if ($class === null) {
                continue;
            }
            $phpDoc = $class->getResolvedPhpDoc();
            if ($phpDoc === null || !$phpDoc->hasPhpDocString()) {
                continue;
            }
            if ($this->hasMarker($phpDoc->getPhpDocString())) {
                return true;
            }
        }

        return false;
    }

    private function hasMarker(?string $docComment): bool
    {
        return $docComment !== null && preg_match(self::MARKER_PATTERN, $docComment) === 1;
    }

}
