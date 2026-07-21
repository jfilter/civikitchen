<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Reporter;

/**
 * Licence coherence between info.xml and composer.json. The two drift apart
 * silently, and a repo can end up publishing itself under terms its LICENSE file
 * never granted.
 *
 * WHICH licence is organisation policy, so the expected value is read from the
 * optional `.ckconform` (`license=`). Without one the declarations only have to
 * agree with each other.
 *
 * Both values are now read with real parsers — SimpleXML and json_decode. The
 * bash predecessor pulled them out with sed, which meant a `<license>` split
 * over two lines read as empty and a composer.json that mentioned "license"
 * anywhere earlier (a script name, a dependency) won the `head -1`.
 */
final class LicenseCoherenceCheck implements Check
{
    public function name(): string
    {
        return 'license-coherence';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        $xml = $this->infoLicense($context);
        $composer = $this->composerLicense($context);
        $want = $context->policyValue('license');

        if ($want !== null) {
            if (!$this->sameLicense($xml, $want)) {
                $reporter->fail(sprintf(
                    "info.xml <license> is '%s', .ckconform expects '%s'",
                    $xml === '' ? 'empty' : $xml,
                    $want,
                ));
            }
            if ($composer !== '' && !$this->sameLicense($composer, $want)) {
                $reporter->fail(
                    "composer.json license is '{$composer}', .ckconform expects '{$want}'"
                );
            }

            return;
        }

        if ($xml !== '' && $composer !== '' && !$this->sameLicense($xml, $composer)) {
            $reporter->fail(
                "licence declarations disagree: info.xml '{$xml}' vs composer.json '{$composer}'"
            );
        }
    }

    private function infoLicense(Context $context): string
    {
        $info = $context->infoXml();
        if ($info === null || !isset($info->license)) {
            return '';
        }

        return (string) $info->license;
    }

    /**
     * SPDX allows `"license": ["MIT", "GPL-2.0"]` for disjunctive licensing.
     * The bash regex could not see that shape at all, so it read as unset —
     * which is what a non-string stays here.
     */
    private function composerLicense(Context $context): string
    {
        $composer = $context->json('composer.json');
        $license = $composer['license'] ?? null;

        return is_string($license) ? $license : '';
    }

    private function sameLicense(string $a, string $b): bool
    {
        return strtolower($a) === strtolower($b);
    }
}
