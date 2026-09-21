<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Policy;
use CiviKitchen\Ckconform\Reporter;

/**
 * A dependency that lives in the same repository and is not mounted into the
 * stack.
 *
 * `sibling_repo` clones a dependency from another repository; a neighbour two
 * directories away is a hand-written volume line instead, and nothing declares
 * it. Without that line the stack comes up without the dependency and
 * `cv ext:enable` fails at the end of a full boot, pointing at the extension
 * rather than at the missing mount.
 *
 * The mount is all there is to check: the entrypoint enables every directory
 * bind-mounted under CK_EXT_DIR and resolves each one's `<requires>` first
 * (docker/runtime/provision.sh), so the order of the volume lines is irrelevant.
 *
 * The expected target is named after the dependency's KEY, not its `<file>`:
 * a required extension is looked up under `<ext dir>/<key>` — the template's
 * phpstan bootstrap resolves it there and the shared CI mounts every sibling
 * there. Only an extension's own mount carries its `<file>`.
 *
 * Any compose file the repo ships may carry the mount — the dev stack and the
 * one CI boots are usually the same file.
 */
final class MonorepoRequiresMountedCheck implements Check
{
    /** The service the site runs in, in the template's stacks and in shared CI. */
    private const SERVICE = 'app';

    public function name(): string
    {
        return 'monorepo-requires-mounted';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        if (!$context->isMonorepo()) {
            return;
        }

        $siblings = $this->siblings($context);
        $required = [];
        foreach ($context->requiredExtensions() as $key) {
            if (isset($siblings[$key])) {
                $required[$key] = $siblings[$key];
            }
        }
        if ($required === []) {
            return;
        }

        $mounts = [];
        $interpolated = [];
        foreach ($context->composeFiles() as $composeFile) {
            $error = null;
            $mounts += $this->mounts($context, $composeFile, $interpolated, $error);
            if ($error !== null) {
                // What an unreadable stack mounts is unknown — say so rather than
                // judge the mounts of the files that did parse.
                $reporter->fail($this->name() . ' not evaluated: ' . $composeFile . ' ' . $error);

                return;
            }
        }

        $problems = [];
        $unevaluated = [];
        foreach ($required as $key => $directory) {
            $target = $context->installPath($key);
            $expected = realpath($context->repositoryRoot() . '/' . $directory);
            $mounted = $mounts[$target] ?? null;
            if ($mounted === null && isset($interpolated[$target])) {
                $unevaluated[] = "{$this->name()} not evaluated: the mount of {$target} comes from"
                    . " {$interpolated[$target]}, which compose interpolates at run time";
            } elseif ($mounted === null) {
                $problems[] = "{$key} is this repository's {$directory}/ but no compose file mounts it into the app service at {$target}";
            } elseif ($mounted !== $expected) {
                $problems[] = "{$target} is mounted from {$mounted}, not from this repository's {$directory}/";
            }
        }

        foreach ($unevaluated as $message) {
            $reporter->warn($message);
        }
        if ($problems === [] && $unevaluated === []) {
            $reporter->ok('every same-repository dependency is mounted into the stack');
        }
        if ($problems === []) {
            return;
        }

        $reporter->fail(
            'same-repository dependency not mounted: ' . implode('; ', $problems)
            . ' — a required extension is looked up by its key (the template\'s phpstan bootstrap and the'
            . ' shared CI\'s sibling mounts both do), so a mount named after its <file> is not found and'
            . ' the stack boots without the dependency'
        );
    }

    /**
     * The other extensions of this repository, key => directory.
     *
     * @return array<string, string>
     */
    private function siblings(Context $context): array
    {
        $siblings = [];
        foreach ($context->repositoryExtensions() as $directory => $info) {
            $key = trim((string) ($info['key'] ?? ''));
            if ($key !== '' && $directory !== $context->extensionDirectory()) {
                $siblings[$key] = $directory;
            }
        }

        return $siblings;
    }

    /**
     * The bind mounts the `app` service of one compose file declares, container
     * path => resolved host path. Only that service counts: the template defines
     * the site in it and the shared workflow runs `cv` and phpunit in it, so a
     * mount into another service is not on the site's extension path. Read
     * through the YAML parser, in both volume spellings; a source that does not
     * exist on disk is no mount. A source compose interpolates is not guessed:
     * it lands in $interpolated, target => source.
     *
     * @param array<string, string> $interpolated
     * @return array<string, string>
     */
    private function mounts(Context $context, string $composeFile, array &$interpolated, ?string &$error): array
    {
        $parsed = Policy::parseYaml($context->read($composeFile) ?? '', $error);
        $services = is_array($parsed) ? ($parsed['services'] ?? null) : null;
        $app = is_array($services) ? ($services[self::SERVICE] ?? null) : null;
        $volumes = is_array($app) ? ($app['volumes'] ?? null) : null;
        if (!is_array($volumes)) {
            return [];
        }
        $base = dirname($context->path($composeFile));
        $mounts = [];
        foreach ($volumes as $volume) {
            if (is_string($volume)) {
                $parts = explode(':', $this->maskInterpolation($volume));
                if (count($parts) < 2) {
                    continue;
                }
                $source = substr($volume, 0, strlen($parts[0]));
                $target = $parts[1];
            } elseif (is_array($volume) && is_string($volume['source'] ?? null) && is_string($volume['target'] ?? null)) {
                $source = $volume['source'];
                $target = $volume['target'];
            } else {
                continue;
            }
            if ($this->maskInterpolation($source) !== $source) {
                $interpolated[rtrim($target, '/')] ??= $source;
                continue;
            }
            $resolved = realpath(str_starts_with($source, '/') ? $source : $base . '/' . $source);
            if ($resolved !== false) {
                $mounts[rtrim($target, '/')] = $resolved;
            }
        }

        return $mounts;
    }

    /**
     * $text with every compose interpolation (`$VAR`, `${VAR…}`) replaced by
     * NUL padding of the same length; `$$` is a literal dollar and stays.
     */
    private function maskInterpolation(string $text): string
    {
        $masked = '';
        $length = strlen($text);
        for ($i = 0; $i < $length; $i++) {
            $end = $i + 1;
            if ($text[$i] === '$' && ($text[$end] ?? '') === '$') {
                $masked .= '$$';
                $i = $end;
                continue;
            }
            if ($text[$i] === '$' && ($text[$end] ?? '') === '{') {
                for ($depth = 0; $end < $length; $end++) {
                    $depth += ['{' => 1, '}' => -1][$text[$end]] ?? 0;
                    if ($depth === 0) {
                        break;
                    }
                }
            } elseif ($text[$i] === '$' && preg_match('/\\G[A-Za-z_][A-Za-z0-9_]*/', $text, $name, 0, $end) === 1) {
                $end += strlen($name[0]) - 1;
            } else {
                $masked .= $text[$i];
                continue;
            }
            $masked .= str_repeat("\0", min($end, $length - 1) - $i + 1);
            $i = $end;
        }

        return $masked;
    }
}
