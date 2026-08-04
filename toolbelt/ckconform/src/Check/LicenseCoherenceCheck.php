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
        $composerLicenses = $this->composerLicenses($context);
        $composer = $this->describe($composerLicenses);
        $want = $context->policyValue('license');

        if ($want !== null) {
            if (!$this->sameLicense($xml, $want)) {
                $reporter->fail(sprintf(
                    "info.xml <license> is '%s', .ckconform expects '%s'",
                    $xml === '' ? 'empty' : $xml,
                    $want,
                ));
            }
            if ($composerLicenses !== [] && !$this->anyMatches($composerLicenses, $want)) {
                $reporter->fail(
                    "composer.json license is '{$composer}', .ckconform expects '{$want}'"
                );
            }

            return;
        }

        if ($xml !== '' && $composerLicenses !== [] && !$this->anyMatches($composerLicenses, $xml)) {
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
     * SPDX allows `"license": ["MIT", "GPL-2.0"]` for disjunctive licensing, and
     * that form is permitted here — but permitted is not the same as unchecked.
     * The bash regex could not see the shape at all, so an array read as unset
     * and skipped the policy entirely; that would make an array the way to
     * bypass every licence rule we have.
     *
     * So a disjunctive list satisfies the policy when the expected licence is
     * one of its members, and is reported in full when it is not.
     *
     * @return list<string>
     */
    private function composerLicenses(Context $context): array
    {
        $composer = $context->json('composer.json');
        $license = $composer['license'] ?? null;

        if (is_string($license)) {
            return $license === '' ? [] : [$license];
        }
        if (is_array($license)) {
            return array_values(array_filter(
                array_map(static fn ($entry): string => is_string($entry) ? $entry : '', $license),
                static fn (string $entry): bool => $entry !== '',
            ));
        }

        return [];
    }

    /**
     * How the composer declaration reads in a message: a single licence as
     * itself, a disjunctive list as the list.
     *
     * @param list<string> $licenses
     */
    private function describe(array $licenses): string
    {
        return count($licenses) === 1 ? $licenses[0] : implode(' or ', $licenses);
    }

    private function sameLicense(string $a, string $b): bool
    {
        return strtolower($a) === strtolower($b);
    }

    /**
     * @param list<string> $licenses
     */
    private function anyMatches(array $licenses, string $want): bool
    {
        foreach ($licenses as $license) {
            if ($this->sameLicense($license, $want)) {
                return true;
            }
        }

        return false;
    }
}
