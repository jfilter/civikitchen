<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Reporter;

/**
 * The civix scaffold ships `<url desc="Licensing">` pointing at the AGPL text.
 * If the extension is relicensed and only `<license>` is edited, the link
 * survives — so the extension browser shows terms nobody granted. Readers trust
 * the link over the tag, so a stale one is worse than none.
 *
 * The url is located by attribute through SimpleXML rather than by matching
 * `desc="Licensing">` in the raw text, so attribute order and whitespace inside
 * the tag no longer decide whether the check runs at all.
 */
final class LicensingUrlCheck implements Check
{
    public function name(): string
    {
        return 'licensing-url';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        $url = $this->licensingUrl($context);
        if ($url === '') {
            return;
        }

        $declared = strtolower($this->infoLicense($context));
        $fromUrl = $this->classify(strtolower($url));

        if ($fromUrl !== '' && !str_contains($declared, $fromUrl)) {
            $reporter->fail(sprintf(
                'info.xml declares <license>%s</license> but its Licensing url points at the %s text (%s)',
                $this->infoLicense($context),
                $fromUrl,
                $url,
            ));
        } else {
            $reporter->ok('licensing url agrees with the declared licence');
        }
    }

    /**
     * Order is load-bearing: an AGPL url contains "gpl", so `agpl` and `lgpl`
     * must be decided before the plain `gpl` arm ever sees it. This mirrors the
     * bash `case`, where the first matching pattern wins.
     */
    private function classify(string $url): string
    {
        if (str_contains($url, 'agpl')) {
            return 'agpl';
        }
        if (str_contains($url, 'lgpl')) {
            return 'lgpl';
        }
        if (str_contains($url, 'gpl')) {
            return 'gpl';
        }
        if (str_contains($url, 'mit-license') || str_contains($url, 'licenses/mit')) {
            return 'mit';
        }
        if (str_contains($url, 'apache.org/licenses')) {
            return 'apache';
        }
        // `*mozilla.org/*mpl*` — "mpl" has to come after the host, not before.
        $host = strpos($url, 'mozilla.org/');
        if ($host !== false && str_contains(substr($url, $host), 'mpl')) {
            return 'mpl';
        }

        return '';
    }

    private function licensingUrl(Context $context): string
    {
        $info = $context->infoXml();
        if ($info === null) {
            return '';
        }

        foreach ($info->xpath('//url[@desc="Licensing"]') ?: [] as $url) {
            return (string) $url;
        }

        return '';
    }

    private function infoLicense(Context $context): string
    {
        $info = $context->infoXml();
        if ($info === null || !isset($info->license)) {
            return '';
        }

        return (string) $info->license;
    }
}
