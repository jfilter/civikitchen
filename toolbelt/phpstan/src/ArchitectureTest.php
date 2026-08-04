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
 * CoreNamespaceCatalog) and on itself — every other CRM_/Civi\ symbol is
 * another extension's internals, and the supported way across that line is
 * APIv4 (Civi\Api4\* is a core namespace, so API calls pass untouched).
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
        $own = $this->ownPrefixRegexes();
        if ($own === []) {
            return self::inertRule();
        }

        $ownSelectors = array_map(
            static fn (string $regex) => Selector::classname($regex, true),
            $own,
        );

        return PHPat::rule()
            ->classes(Selector::all())
            ->shouldNotDependOn()
            ->classes(Selector::classname('~^(CRM_|Civi\\\\)~', true))
            ->excluding(
                Selector::classname(self::coreRegex(), true),
                ...$ownSelectors,
            )
            ->because('this is another extension\'s internal class — call it via APIv4 (Civi\Api4\*), or declare the coupling with an ignoreErrors entry and a reason');
    }

    public function testNoLegacyUiBases(): Rule
    {
        if ($this->ownPrefixRegexes() === []) {
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

    /** Prefixes the analysed extension owns, as classname regexes. */
    private function ownPrefixRegexes(): array
    {
        if (!is_file($this->extensionDir . '/info.xml')) {
            return [];
        }

        $regexes = [];
        foreach ($this->topLevelNames($this->extensionDir . '/CRM') as $name) {
            $regexes[] = '~^CRM_' . preg_quote($name, '~') . '(_|$)~';
        }
        foreach ($this->topLevelNames($this->extensionDir . '/Civi') as $name) {
            $regexes[] = '~^Civi\\\\' . preg_quote($name, '~') . '(\\\\|$)~';
        }

        // Non-standard roots (e.g. psr4 Civi\Acme\ => src/) only show up in
        // the classloader declaration; the generic civix CRM_/Civi\ entries
        // would whitelist everything and are skipped.
        $infoXml = (string) file_get_contents($this->extensionDir . '/info.xml');
        if (preg_match_all('/<psr[04]\s[^>]*prefix="([^"]+)"/', $infoXml, $m)) {
            foreach ($m[1] as $prefix) {
                $prefix = trim($prefix, '\\_');
                if ($prefix === 'CRM' || $prefix === 'Civi' || $prefix === '') {
                    continue;
                }
                $regexes[] = '~^' . preg_quote($prefix, '~') . '(_|\\\\|$)~';
            }
        }

        // civix's declared namespace owns generated classes like
        // CRM_Acme_ExtensionUtil even when the repo has no CRM/ tree.
        if (preg_match('~<namespace>\s*CRM/([A-Za-z0-9_]+)\s*</namespace>~', $infoXml, $m)) {
            $regexes[] = '~^CRM_' . preg_quote($m[1], '~') . '(_|$)~';
        }

        return array_values(array_unique($regexes));
    }

    private function topLevelNames(string $dir): array
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
