<?php

declare(strict_types=1);

namespace CiviKitchen\Toolbelt\Cli;

use CiviKitchen\Toolbelt\Process\Runner;

final class CiCommand implements Command
{
    /**
     * The in-container gates of extension-ci.yml's `ci` job, in run order: the one gate list.
     * `ext` runs where CiviCRM enabled the extension, `tool` in the git checkout.
     *
     * @var array<string, array{0: 'ext'|'tool', 1: list<string>}>
     */
    private const GATES = [
        'cklint' => ['tool', ['cklint', '--all']],
        'ckconform' => ['tool', ['ckconform']],
        'ckcivix' => ['tool', ['ckcivix', '--check']],
        'ckfmt' => ['tool', ['ckfmt', '--check']],
        'ckcoverage' => ['ext', ['ckcoverage', 'tests/phpunit']],
        'phpunit-extra' => ['ext', ['phpunit', '-c']],
        'phpstan' => ['ext', ['phpstan', 'analyse', '--no-progress']],
        'phpstan-tests' => ['ext', ['phpstan', 'analyse', '-c']],
        'ckcompat' => ['tool', ['ckcompat']],
        'ckdeps' => ['ext', ['ckdeps']],
        'cktaint' => ['tool', ['cktaint']],
        'cksmarty' => ['ext', ['cksmarty']],
        'ckeslint' => ['tool', ['ckeslint']],
    ];

    public function __construct(private readonly Runner $runner = new Runner())
    {
    }

    public function run(array $arguments): int
    {
        $only = [];
        $skip = [];
        $extra = (string) getenv('CK_EXTRA_PHPUNIT_CONFIG');
        while ($arguments !== []) {
            $argument = array_shift($arguments);
            if (in_array($argument, ['-h', '--help'], true)) {
                echo $this->usage();
                return 0;
            }
            [$option, $value] = str_contains($argument, '=') ? explode('=', $argument, 2) : [$argument, null];
            if (!in_array($option, ['--only', '--skip', '--extra-phpunit-config'], true)) {
                fwrite(STDERR, "ck ci: unknown argument: {$argument}\n" . $this->usage());
                return 2;
            }
            $value ??= (string) array_shift($arguments);
            if ($value === '') {
                fwrite(STDERR, "ck ci: {$option} needs a value\n");
                return 2;
            }
            if ($option === '--extra-phpunit-config') {
                $extra = $value;
                continue;
            }
            $names = array_map('trim', explode(',', $value));
            $unknown = array_diff($names, array_keys(self::GATES));
            if ($unknown !== []) {
                fwrite(STDERR, 'ck ci: unknown gate: ' . implode(', ', $unknown)
                    . "\nGates: " . implode(', ', array_keys(self::GATES)) . "\n");
                return 2;
            }
            $option === '--only' ? array_push($only, ...$names) : array_push($skip, ...$names);
        }

        $extPath = (string) getenv('CK_EXT_PATH') ?: (string) getcwd();
        $toolPath = (string) getenv('CK_TOOL_PATH') ?: $extPath;
        if (!is_file($extPath . '/info.xml')) {
            fwrite(STDERR, "ck ci: no info.xml in {$extPath} - run from the extension root or set CK_EXT_PATH.\n");
            return 2;
        }
        if (!is_dir($toolPath)) {
            fwrite(STDERR, "ck ci: CK_TOOL_PATH {$toolPath} is not a directory.\n");
            return 2;
        }
        $selected = array_diff($only === [] ? array_keys(self::GATES) : array_unique($only), $skip);
        if ($selected === []) {
            fwrite(STDERR, "ck ci: --only and --skip leave no gate to run\n");
            return 2;
        }

        $github = (string) getenv('GITHUB_ACTIONS') !== '';
        $results = [];
        foreach (self::GATES as $gate => [$where, $command]) {
            if (!in_array($gate, $selected, true)) {
                continue;
            }
            $directory = $where === 'ext' ? $extPath : $toolPath;
            $skipped = $this->optIn($gate, $directory, $extra, $command);
            if (is_string($skipped)) {
                echo "==> {$gate}: skipped ({$skipped})\n";
                $results[$gate] = ['skipped', null, $skipped];
                continue;
            }
            echo $github ? '::group::' : "\n==> ", $gate, ' (', implode(' ', $command), " in {$directory})\n";
            $started = hrtime(true);
            $status = $this->runner->passthrough($command, null, $directory);
            $seconds = (hrtime(true) - $started) / 1e9;
            if ($github) {
                echo "::endgroup::\n";
                if ($status !== 0) {
                    echo "::error title=ck ci::{$gate} failed (exit {$status})\n";
                }
            }
            $results[$gate] = $status === 0 ? ['pass', $seconds, ''] : ['fail', $seconds, "exit {$status}"];
        }

        echo "\nck ci summary\n";
        foreach ($results as $gate => [$result, $seconds, $note]) {
            printf("  %-14s %-8s %7s  %s\n", $gate, $result, $this->duration($seconds), $note);
        }
        $summaryFile = (string) getenv('GITHUB_STEP_SUMMARY');
        if ($github && $summaryFile !== '') {
            file_put_contents($summaryFile, $this->markdown($results), FILE_APPEND);
        }
        return in_array('fail', array_column($results, 0), true) ? 1 : 0;
    }

    /**
     * The skip reason of an opt-in gate whose switch is off, or null after completing its command.
     *
     * @param list<string> $command
     */
    private function optIn(string $gate, string $directory, string $extra, array &$command): ?string
    {
        if ($gate === 'phpunit-extra') {
            if ($extra === '') {
                return 'no --extra-phpunit-config / CK_EXTRA_PHPUNIT_CONFIG';
            }
            $command[] = $extra;
        }
        if ($gate === 'phpstan-tests') {
            $config = array_values(array_filter(['phpstan-tests.neon', 'phpstan-tests.neon.dist'],
                static fn(string $file): bool => is_file($directory . '/' . $file)))[0] ?? null;
            if ($config === null) {
                return 'no phpstan-tests.neon.dist - test analysis not enabled';
            }
            array_push($command, $config, '--no-progress');
        }
        return null;
    }

    private function duration(?float $seconds): string
    {
        return $seconds === null ? '-' : sprintf('%.1fs', $seconds);
    }

    /** @param array<string, array{0: string, 1: ?float, 2: string}> $results */
    private function markdown(array $results): string
    {
        $out = "### ck ci\n\n| Gate | Result | Duration |\n|------|--------|----------|\n";
        foreach ($results as $gate => [$result, $seconds, $note]) {
            $out .= "| `{$gate}` | {$result}" . ($note === '' ? '' : " ({$note})") . ' | ' . $this->duration($seconds) . " |\n";
        }
        $taint = $results['cktaint'][0] ?? 'skipped';
        if ($taint === 'pass') {
            $out .= "\n### Taint analysis: no blocking findings\n";
        } elseif ($taint === 'fail') {
            // A non-zero exit can also be a broken config or an analyzer crash, so this claims no flow.
            $out .= <<<'MD'

### Taint analysis: blocking findings or analysis failure

`cktaint` exited non-zero — see its group in the log for whether it reported
tainted data flows or failed to run at all. SQL/shell/include/unserialize/SSRF
taint fails the build; INFO-level findings do not. For a real finding, fix the
flow; for a false positive, record a justified exception (`cktaint --baseline` /
`@psalm-suppress` with a reason). See docs/extension-standards.md, section
"Taint analysis".

MD;
        }
        return $out;
    }

    private function usage(): string
    {
        $gates = implode(', ', array_keys(self::GATES));
        return <<<TXT
ck ci — run every in-container gate of the shared extension CI, in its order.

  ck ci                                  all gates, then a summary table
  ck ci --only GATE[,GATE]               just these gates
  ck ci --skip GATE[,GATE]               all but these gates
  ck ci --extra-phpunit-config FILE      also run `phpunit -c FILE` (CK_EXTRA_PHPUNIT_CONFIG)

Gates: {$gates}

Every gate runs even after an earlier one failed; the exit code is 1 when any
failed. Run from the extension root. CK_EXT_PATH (default: the current
directory) is where CiviCRM enabled the extension; CK_TOOL_PATH (default:
CK_EXT_PATH) is the same directory inside the git checkout, /civikitchen-repo/<dir>
in a repository of several extensions. phpstan-tests runs when
phpstan-tests.neon(.dist) exists. Under GitHub Actions each gate is a log group
and the table goes to \$GITHUB_STEP_SUMMARY.

TXT;
    }
}
