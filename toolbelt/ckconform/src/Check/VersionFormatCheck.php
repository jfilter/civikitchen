<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Reporter;

/**
 * `info.xml` `<version>` as a SemVer 2.0 version without build metadata:
 * `X.Y.Z` or `X.Y.Z-<pre-release>`. The release workflow rejects every other
 * tag, so a version outside that grammar can never be released.
 */
final class VersionFormatCheck implements Check
{
    private const NUMBER = '(?:0|[1-9][0-9]*)';
    private const IDENTIFIER = '(?:0|[1-9][0-9]*|[0-9]*[A-Za-z-][0-9A-Za-z-]*)';

    public function name(): string
    {
        return 'version-format';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        if ($context->infoXml() === null) {
            return;
        }
        $version = $context->infoVersion();
        $pattern = '/^' . self::NUMBER . '\.' . self::NUMBER . '\.' . self::NUMBER
            . '(?:-' . self::IDENTIFIER . '(?:\.' . self::IDENTIFIER . ')*)?$/';
        if (preg_match($pattern, $version) === 1) {
            $reporter->ok("info.xml <version> {$version} is a releasable version");

            return;
        }

        $shown = $version === '' ? 'is missing' : "'{$version}' is not X.Y.Z or X.Y.Z-<pre-release>";
        $reporter->fail(
            "info.xml <version> {$shown} (SemVer 2.0, no leading zeros, no build metadata) — "
            . 'the release workflow accepts no other tag, so this version can never be released'
        );
    }
}
