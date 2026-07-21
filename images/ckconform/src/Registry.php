<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform;

/**
 * The ordered list of checks.
 *
 * Order is not cosmetic: the golden output captured from the bash predecessor is
 * compared line by line, so a reordering reads as a regression. Entries whose
 * class does not exist yet are skipped, which is what lets the port land check by
 * check instead of as one unreviewable commit.
 */
final class Registry
{
    /** @var list<class-string<Check>> */
    private const CHECKS = [
        Check\PhpcsConfigCheck::class,
        Check\PhpstanConfigCheck::class,
        Check\PhpunitConfigCheck::class,
        Check\TestBootstrapGuardCheck::class,
        Check\CoverageSectionCheck::class,
        Check\CiCoverageCheck::class,
        Check\CoversNothingCheck::class,
        Check\TestSuiteRequiredCheck::class,
        Check\ComposerJsonCheck::class,
        Check\CiWorkflowCheck::class,
        Check\ConfigWithoutRunnerCheck::class,
        Check\RequiredExtensionsCheck::class,
        Check\LicenseSkeletonCheck::class,
        Check\LicenseCoherenceCheck::class,
        Check\CopyrightCheck::class,
        Check\LicensingUrlCheck::class,
        Check\NpmLicenseCheck::class,
        Check\FloatingTagCheck::class,
        Check\ComposeFloatingTagCheck::class,
        Check\ComposeProjectNameCheck::class,
        Check\PlaywrightDiagnosticsCheck::class,
        Check\WorkflowPermissionsCheck::class,
        Check\FrontEndApi3Check::class,
        Check\Api4EntityCheck::class,
        Check\Api4SelfEntityCheck::class,
        Check\LockfileCheck::class,
        Check\NpmInstallCheck::class,
        Check\CommittedArtifactCheck::class,
        Check\GitignoreCheck::class,
        Check\GitignoreCoverageCheck::class,
    ];

    /** @return list<Check> */
    public static function all(): array
    {
        $checks = [];
        foreach (self::CHECKS as $class) {
            if (class_exists($class)) {
                $checks[] = new $class();
            }
        }

        return $checks;
    }

    /** @return list<class-string<Check>> */
    public static function declared(): array
    {
        return self::CHECKS;
    }
}
