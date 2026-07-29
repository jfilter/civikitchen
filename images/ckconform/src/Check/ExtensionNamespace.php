<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Context;

/**
 * Which class-name prefixes belong to the extension under inspection.
 *
 * The static checks that resolve a class name to a file can only judge the
 * extension's own classes: a missing CRM_Contact_Page_View is a typo in the
 * check, not a bug in the repo, because core ships it. Guessing wrong in either
 * direction is costly — too narrow and a genuinely dangling callback passes, too
 * wide and every core reference fails — so the shortname is taken from both
 * ends: info.xml (<key>/<file>) and the directories the repo actually ships
 * (CRM/<X>/, Civi/<X>/). Comparison is case-insensitive; PSR-0 case drift is
 * Psr0ClassPathCheck's job, not this one's.
 */
final class ExtensionNamespace
{
    /**
     * @return list<string> Shortnames, lower-cased.
     */
    public static function all(Context $context): array
    {
        $names = [];

        $info = $context->infoXml();
        if ($info !== null) {
            foreach ([(string) $info['key'], (string) ($info->file ?? '')] as $candidate) {
                $candidate = trim($candidate);
                if ($candidate === '') {
                    continue;
                }
                $parts = explode('.', $candidate);
                $names[] = strtolower((string) end($parts));
            }
        }

        $files = $context->isGitRepo() ? $context->trackedFiles() : $context->findFiles('');
        foreach ($files as $file) {
            if (preg_match('#^(?:CRM|Civi)/([A-Za-z0-9]+)/#', $file, $match) === 1) {
                $names[] = strtolower($match[1]);
            }
        }

        return array_values(array_unique(array_filter($names, static fn (string $n): bool => $n !== '')));
    }

    /**
     * Is this a CRM_<Shortname>_… class of the extension itself?
     *
     * @param list<string> $namespaces
     */
    public static function isOwnClass(string $class, array $namespaces): bool
    {
        if (preg_match('/^CRM_([A-Za-z0-9]+)_/', $class, $match) !== 1) {
            return false;
        }

        return in_array(strtolower($match[1]), $namespaces, true);
    }

    /**
     * The PSR-0/PSR-4 file that must ship a class of this extension, or null for
     * a foreign class.
     *
     * @param list<string> $namespaces
     */
    public static function ownClassFile(string $class, array $namespaces): ?string
    {
        $class = ltrim(str_replace('\\\\', '\\', $class), '\\');

        if (self::isOwnClass($class, $namespaces)) {
            return str_replace('_', '/', $class) . '.php';
        }
        if (preg_match('#^Civi\\\\([A-Za-z0-9]+)\\\\#', $class, $match) === 1
            && in_array(strtolower($match[1]), $namespaces, true)
        ) {
            return str_replace('\\', '/', $class) . '.php';
        }

        return null;
    }
}
