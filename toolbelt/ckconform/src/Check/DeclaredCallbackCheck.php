<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Reporter;

/**
 * A route in xml/Menu/*.xml whose page_callback names a class this extension
 * does not ship.
 *
 * The menu XML is read at cache-rebuild time and stored verbatim; nothing
 * validates the callback. The class is only resolved when someone opens the
 * URL, and then CiviCRM fatals ("Could not load class") — a 500 on a page that
 * looked installed, invisible in CI, in the extension list and in every test
 * that does not happen to hit that route. Renaming or moving a Page class
 * without touching the menu XML produces exactly this.
 *
 * Only the extension's own CRM_<Shortname>_… classes are resolvable statically,
 * so those are the only ones judged; core and third-party callbacks would need
 * a booted CiviCRM and are passed over in silence rather than warned about.
 */
final class DeclaredCallbackCheck implements Check
{
    public function name(): string
    {
        return 'declared-callback';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        $files = $context->isGitRepo()
            ? $context->trackedUnder('xml/Menu', ['.xml'])
            : $context->findFiles('xml/Menu', ['.xml']);
        if ($files === []) {
            return;
        }

        $namespaces = ExtensionNamespace::all($context);

        foreach ($files as $relative) {
            $raw = $context->read($relative);
            if ($raw === null) {
                continue;
            }
            $previous = libxml_use_internal_errors(true);
            $xml = simplexml_load_string($raw);
            libxml_use_internal_errors($previous);
            if ($xml === false) {
                $reporter->fail("$relative: unparseable menu XML — CiviCRM cannot register any route from this file");
                continue;
            }

            foreach ($xml->item as $item) {
                $callback = trim((string) ($item->page_callback ?? ''));
                if ($callback === '') {
                    // A container entry (label + path only) has no callback.
                    continue;
                }
                $method = null;
                if (str_contains($callback, '::')) {
                    [$callback, $method] = explode('::', $callback, 2);
                    $callback = trim($callback);
                    $method = trim($method);
                }
                if (!ExtensionNamespace::isOwnClass($callback, $namespaces)) {
                    // Core or another extension: not resolvable without a boot.
                    continue;
                }

                $expected = str_replace('_', '/', $callback) . '.php';
                if (!$context->ships($expected)) {
                    $reporter->fail(
                        "$relative: page_callback $callback has no file $expected — the route 500s on first visit"
                    );
                    continue;
                }
                if ($method !== null && $method !== '') {
                    $source = $context->read($expected) ?? '';
                    if (preg_match('/\bfunction\s+' . preg_quote($method, '/') . '\s*\(/', $source) !== 1) {
                        $reporter->fail(
                            "$relative: page_callback $callback::$method — $expected declares no function $method()"
                        );
                    }
                }
            }
        }
    }

}
