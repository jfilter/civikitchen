<?php

declare(strict_types=1);

namespace CiviKitchen\PHPStan;

use PHPat\Selector\Selector;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

/**
 * Fleet-wide phpat architecture rules, auto-registered from the image.
 *
 * Boundary rule: an extension may depend on CiviCRM core (per the generated
 * CoreNamespaceCatalog), on itself, and on every extension its info.xml
 * declares in <requires><ext> — a declared requirement is an explicit
 * contract, the same as own code. Every other CRM_/Civi\ symbol is another
 * extension's internals, and the supported way across that line is APIv4
 * (Civi\Api4\* is a core namespace, so API calls pass untouched).
 *
 * A required extension contributes its prefixes only when its checkout is
 * found beside the analysed one (sibling directory, the CI sibling mount,
 * or the image's extension directory). One that is not found is reported
 * like any foreign extension and the message says so — no silent fallback.
 *
 * Legacy-UI rule: the phpat successor of the retired NoLegacyPageForm phpcs
 * sniff. Because phpat resolves the full ancestry, a class extending an own
 * intermediate base or a concrete core report is caught too — the token
 * sniff only ever saw the direct parent's name.
 *
 * Both rules derive the extension from the working directory (info.xml,
 * classloader dirs). Outside an extension root they stay inert — there is no
 * "own namespace" to defend and every own class would count as foreign.
 * Violations are phpstan errors; grandfathered code gets an ignoreErrors
 * entry in the repo's phpstan.neon.dist with a reason.
 */
final class ArchitectureTest
{
    private string $extensionDir;

    public function __construct(string $currentWorkingDirectory)
    {
        $this->extensionDir = $currentWorkingDirectory;
    }

    public function testOnlyCoreAndOwnCivicrmDependencies(): Rule
    {
        $allowed = self::prefixRegexes($this->extensionDir);
        if ($allowed === []) {
            return self::inertRule();
        }

        $missing = [];
        foreach (self::requiredExtensionKeys($this->extensionDir) as $key) {
            if (in_array($key, CoreNamespaceCatalog::CORE_EXTENSION_KEYS, true)) {
                continue;
            }
            $dir = self::locateExtension($this->extensionDir, $key);
            if ($dir === null) {
                $missing[] = $key;
                continue;
            }
            $allowed = array_merge($allowed, self::prefixRegexes($dir));
        }

        $allowedSelectors = array_map(
            static fn (string $regex) => Selector::classname($regex, true),
            array_values(array_unique($allowed)),
        );

        $because = 'this is another extension\'s internal class — call it via APIv4 (Civi\Api4\*), '
            . 'declare the extension in info.xml <requires> (its checkout must sit beside this one), '
            . 'or declare the coupling with an ignoreErrors entry and a reason';
        if ($missing !== []) {
            $because .= '; required but not found beside this extension: ' . implode(', ', $missing)
                . ' (looked in ../<key>, .civikitchen-siblings/<key> and ' . self::imageExtensionDir() . '/<key>)';
        }

        return PHPat::rule()
            ->classes(Selector::all())
            ->shouldNotDependOn()
            ->classes(Selector::classname('~^(CRM_|Civi\\\\)~', true))
            ->excluding(
                Selector::classname(self::coreRegex(), true),
                ...$allowedSelectors,
            )
            ->because($because);
    }

    public function testNoLegacyUiBases(): Rule
    {
        if (self::prefixRegexes($this->extensionDir) === []) {
            return self::inertRule();
        }

        return PHPat::rule()
            ->classes(Selector::all())
            ->shouldNotExtend()
            ->classes(
                Selector::classname('CRM_Core_Page'),
                Selector::classname('CRM_Core_Form'),
                Selector::classname('CRM_Report_Form'),
            )
            ->because('legacy QuickForm/Smarty UI base — prefer a SearchKit display or Afform for UI and an APIv4 action for data endpoints; grandfathered screens get an ignoreErrors entry');
    }

    /**
     * Where a required extension's checkout is, or null when none of the
     * candidate directories holds an info.xml declaring exactly that key.
     * Tried in order: a sibling checkout, the shared CI's sibling mount
     * (.civikitchen-siblings/, see extension-ci.yml), the image's ext dir.
     */
    public static function locateExtension(string $extensionDir, string $key): ?string
    {
        $candidates = [
            dirname(rtrim($extensionDir, '/')) . '/' . $key,
            rtrim($extensionDir, '/') . '/.civikitchen-siblings/' . $key,
            self::imageExtensionDir() . '/' . $key,
        ];
        foreach ($candidates as $candidate) {
            if (self::infoXmlKey($candidate) === $key) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Keys of the extensions an info.xml declares in <requires><ext>.
     *
     * @return list<string>
     */
    public static function requiredExtensionKeys(string $extensionDir): array
    {
        $info = self::infoXml($extensionDir);
        if ($info === null) {
            return [];
        }
        $keys = [];
        foreach ($info->xpath('/extension/requires/ext') ?: [] as $ext) {
            $key = trim((string) $ext);
            if ($key !== '') {
                $keys[] = $key;
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * Prefixes an extension owns, as classname regexes.
     *
     * @return list<string>
     */
    public static function prefixRegexes(string $extensionDir): array
    {
        $info = self::infoXml($extensionDir);
        if ($info === null) {
            return [];
        }

        $regexes = [];
        foreach (self::topLevelNames($extensionDir . '/CRM') as $name) {
            $regexes[] = '~^CRM_' . preg_quote($name, '~') . '(_|$)~';
        }
        foreach (self::topLevelNames($extensionDir . '/Civi') as $name) {
            $regexes[] = '~^Civi\\\\' . preg_quote($name, '~') . '(\\\\|$)~';
        }

        // Non-standard roots (e.g. psr4 Civi\Acme\ => src/) only show up in
        // the classloader declaration; the generic civix CRM_/Civi\ entries
        // would whitelist everything and are skipped.
        foreach ($info->xpath('/extension/classloader/*[@prefix]') ?: [] as $loader) {
            $prefix = trim((string) $loader['prefix'], '\\_');
            if ($prefix === 'CRM' || $prefix === 'Civi' || $prefix === '') {
                continue;
            }
            $regexes[] = '~^' . preg_quote($prefix, '~') . '(_|\\\\|$)~';
        }

        // civix's declared namespace owns generated classes like
        // CRM_Acme_ExtensionUtil even when the repo has no CRM/ tree.
        foreach ($info->xpath('/extension/civix/namespace') ?: [] as $namespace) {
            if (preg_match('~^CRM/([A-Za-z0-9_]+)$~', trim((string) $namespace), $m)) {
                $regexes[] = '~^CRM_' . preg_quote($m[1], '~') . '(_|$)~';
            }
        }

        return array_values(array_unique($regexes));
    }

    /** The image's extension root — the entrypoint exports CK_EXT_DIR. */
    private static function imageExtensionDir(): string
    {
        return rtrim(getenv('CK_EXT_DIR') ?: '/var/www/html/ext', '/');
    }

    private static function infoXml(string $dir): ?\SimpleXMLElement
    {
        $file = $dir . '/info.xml';
        if (!is_file($file)) {
            return null;
        }
        $previous = libxml_use_internal_errors(true);
        $info = simplexml_load_file($file);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $info === false ? null : $info;
    }

    private static function infoXmlKey(string $dir): ?string
    {
        $info = self::infoXml($dir);
        if ($info === null) {
            return null;
        }
        $key = trim((string) $info['key']);

        return $key === '' ? null : $key;
    }

    /** @return list<string> */
    private static function topLevelNames(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $names = [];
        foreach (new \DirectoryIterator($dir) as $entry) {
            if ($entry->isDir() && !$entry->isDot()) {
                $names[] = $entry->getFilename();
            } elseif ($entry->isFile() && str_ends_with($entry->getFilename(), '.php')) {
                $names[] = substr($entry->getFilename(), 0, -4);
            }
        }

        return $names;
    }

    private static function coreRegex(): string
    {
        $crm = implode('|', array_map(
            static fn (string $c) => preg_quote($c, '~'),
            CoreNamespaceCatalog::CRM_COMPONENTS,
        ));
        $civi = implode('|', array_map(
            static fn (string $n) => preg_quote($n, '~'),
            CoreNamespaceCatalog::CIVI_NAMESPACES,
        ));

        return '~^(CRM_(' . $crm . ')(_|$)|Civi\\\\(' . $civi . ')(\\\\|$))~';
    }

    /** A rule whose subject can never match — \0 is not valid in a classname. */
    private static function inertRule(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::classname('~\x00~', true))
            ->shouldNotDependOn()
            ->classes(Selector::classname('~\x00~', true))
            ->because('inert outside an extension root');
    }
}
