<?php

declare(strict_types=1);

namespace CiviKitchen\Toolbelt\Cli;

use CiviKitchen\Toolbelt\Process\Runner;

final class Application
{
    /** @var array<string, string> */
    private const LEGACY_TOOLS = [
        'lint' => 'cklint', 'format' => 'ckfmt', 'fmt' => 'ckfmt',
        'compatibility' => 'ckcompat', 'compat' => 'ckcompat', 'javascript' => 'ckeslint',
        'js' => 'ckeslint', 'eslint' => 'ckeslint', 'test' => 'ckphpunit', 'phpunit' => 'ckphpunit',
        'lifecycle' => 'cklifecycle',
        'taint' => 'cktaint', 'mutate' => 'ckmutate',
        'modernize' => 'ckmodernize', 'release' => 'ckrelease',
        'core-test' => 'ckcoretest',
        'test-reset' => 'cktestreset',
    ];

    public function __construct(
        private readonly string $binDirectory,
        private readonly string $checkoutRoot,
        private readonly Runner $runner = new Runner(),
    ) {
    }

    /** @param list<string> $arguments */
    public function run(array $arguments, string $program): int
    {
        $name = basename($program);
        $aliases = [
            'ckprofile' => 'profile',
            'ckdeps' => 'dependencies',
            'ckconform' => 'conform',
            'ckscenario' => 'scenario',
            'ckcivix' => 'civix',
            'cksmarty' => 'smarty',
            'ckcoverage' => 'coverage',
            'ckschemadiff' => 'schema',
        ];
        if (isset($aliases[$name])) {
            array_unshift($arguments, $aliases[$name]);
        }
        $command = array_shift($arguments) ?? 'help';
        if (in_array($command, ['help', '-h', '--help'], true)) {
            echo $this->usage(), "\n";
            return 0;
        }
        if ($command === 'profile') {
            return (new ProfileCommand($this->checkoutRoot))->run($arguments);
        }
        if (in_array($command, ['dependencies', 'deps'], true)) {
            return (new DependenciesCommand($this->runner))->run($arguments);
        }
        if ($command === 'internal') {
            return (new InternalRuntimeCommand($this->checkoutRoot))->run($arguments);
        }
        if ($command === 'conform') {
            $implementation = $this->checkoutRoot . '/toolbelt/ckconform/bin/ckconform';
            if (!is_file($implementation)) {
                $implementation = '/opt/civikitchen-ckconform/bin/ckconform';
            }
            return $this->runner->passthrough([PHP_BINARY, $implementation, ...$arguments]);
        }
        if (in_array($command, ['config', 'scenario'], true)) {
            $implementation = $this->checkoutRoot . '/packages/civikitchen-scenario-schema/scenario.php';
            if (!is_file($implementation)) {
                $implementation = '/usr/local/share/civikitchen/scenario-schema/scenario.php';
            }
            return $this->runner->passthrough([PHP_BINARY, $implementation, ...$arguments]);
        }
        if ($command === 'civix') {
            return (new CivixCommand($this->runner))->run($arguments);
        }
        if ($command === 'smarty') {
            return (new SmartyCommand($this->checkoutRoot, $this->runner))->run($arguments);
        }
        if ($command === 'coverage') {
            return (new CoverageCommand($this->checkoutRoot, $this->runner))->run($arguments);
        }
        if ($command === 'schema') {
            return (new SchemaDiffCommand($this->runner))->run($arguments);
        }
        $tool = self::LEGACY_TOOLS[$command] ?? null;
        if ($tool === null) {
            fwrite(STDERR, "ck: unknown command: {$command}\n" . $this->usage() . "\n");
            return 2;
        }
        $local = $this->binDirectory . '/' . $tool;
        return $this->runner->passthrough([is_executable($local) ? $local : $tool, ...$arguments]);
    }

    private function usage(): string
    {
        return <<<'TXT'
ck — CiviKitchen extension-development toolbelt

  ck conform [args]       repository conformance
  ck lint [args]          PHP lint and bug patterns
  ck format [args]        PHP/JS formatting
  ck compatibility       declared PHP-floor compatibility
  ck dependencies        Composer dependency audit
  ck javascript          JS/TS linting
  ck test [args]          headless PHPUnit with transaction canary
  ck coverage [args]      PHPUnit coverage floor
  ck lifecycle [args]     install/disable/enable/uninstall checks
  ck schema [args]        install-vs-upgrade schema parity
  ck smarty [args]        compile shipped Smarty templates
  ck taint [args]         taint analysis
  ck mutate [args]        mutation testing
  ck modernize [args]     civix/Rector modernization
  ck release [args]       extension release artifact
  ck profile [args]       validate/list runtime profiles
  ck config [args]        validate civikitchen.yaml
  ck scenario [args]      validate/render a reproducible scenario
  ck civix [args]         civix scaffold drift
  ck core-test [args]     CiviCRM core PHPUnit suites
  ck test-reset [args]    reset the standalone scratch DB

Use `ck <command> --help` for command-specific help.
TXT;
    }
}
