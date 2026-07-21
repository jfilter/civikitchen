<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Reporter;

/**
 * LICENSE.txt scaffolding leftovers: civix writes the key of whatever extension
 * the file was copied from, and a FIXME copyright holder. Both survive a
 * copy-paste bootstrap unnoticed, and then the licence file names someone else's
 * package.
 *
 * The extension key comes from the root element's `key` attribute via SimpleXML;
 * the bash predecessor sed'ed for it and picked up the first `key="…"` on any
 * line that happened to mention `<extension`.
 */
final class LicenseSkeletonCheck implements Check
{
    public function name(): string
    {
        return 'license-skeleton';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        if (!$context->exists('LICENSE.txt')) {
            return;
        }

        $license = $context->read('LICENSE.txt') ?? '';
        $package = $this->packageLine($license);
        $key = $this->extensionKey($context);

        if ($package !== '' && $key !== '' && $package !== $key) {
            $reporter->fail(
                "LICENSE.txt says 'Package: {$package}' but info.xml key is '{$key}' (copied skeleton)"
            );
        }

        if (str_contains($license, 'FIXME')) {
            $reporter->warn('LICENSE.txt still contains FIXME (copyright holder unfilled)');
        }
    }

    /** First `Package:` line, with the label and its padding stripped. */
    private function packageLine(string $license): string
    {
        foreach (explode("\n", $license) as $line) {
            if (str_starts_with($line, 'Package:')) {
                return ltrim(substr($line, strlen('Package:')), ' ');
            }
        }

        return '';
    }

    private function extensionKey(Context $context): string
    {
        $info = $context->infoXml();

        return $info === null ? '' : (string) ($info['key'] ?? '');
    }
}
