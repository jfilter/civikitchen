<?php

declare(strict_types=1);

use CiviKitchen\Toolbelt\Cli\Application;
use CiviKitchen\Toolbelt\Cli\CompatibilityCommand;
use CiviKitchen\Toolbelt\Cli\CoverageCommand;
use CiviKitchen\Toolbelt\Cli\FormatCommand;
use CiviKitchen\Toolbelt\Cli\InternalRuntimeCommand;
use CiviKitchen\Toolbelt\Cli\ReleaseCommand;
use CiviKitchen\Toolbelt\Process\Runner;
use CiviKitchen\Toolbelt\Repository\Files;
use CiviKitchen\Toolbelt\Runtime\ExtensionInspector;
use CiviKitchen\Toolbelt\Runtime\ExtensionArchiveInstaller;
use CiviKitchen\Toolbelt\Runtime\ProfileData;
use CiviKitchen\Toolbelt\Scaffold\ExtensionEditor;
use PHPUnit\Framework\TestCase;

final class SharedPhpTest extends TestCase
{
    private string $temporary;

    protected function setUp(): void
    {
        $this->temporary = sys_get_temp_dir() . '/civikitchen-shared-php-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->temporary, 0700));
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->temporary)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->temporary, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->temporary);
    }

    public function testApplicationRoutesHelpForEveryCommandThatOffersIt(): void
    {
        $application = new Application(dirname(__DIR__, 2) . '/toolbelt/bin', dirname(__DIR__, 2));
        foreach (['civix', 'compatibility', 'dependencies', 'format', 'javascript', 'lifecycle', 'lint',
            'profile', 'release', 'schema', 'smarty'] as $command) {
            ob_start();
            $status = $application->run([$command, '--help'], 'ck');
            $output = (string) ob_get_clean();
            self::assertSame(0, $status, $command);
            self::assertNotSame('', $output, $command);
        }
        ob_start();
        self::assertSame(0, $application->run([], 'ck'));
        self::assertStringContainsString('CiviKitchen', (string) ob_get_clean());

        foreach (['coverage', 'mutate', 'test'] as $command) {
            self::assertSame(2, $application->run([$command], 'ck'), $command);
        }
        self::assertSame(2, $application->run(['internal', 'unknown-operation'], 'ck'));
        self::assertSame(2, $application->run(['definitely-unknown'], 'ck'));
    }

    public function testSentinelProbeTellsAbsentFromUnanswerable(): void
    {
        // Only "no such database" and "no such table" mean the sentinel was never written.
        self::assertSame(InternalRuntimeCommand::SENTINEL_ABSENT, InternalRuntimeCommand::sentinelStatus(false, 1049));
        self::assertSame(InternalRuntimeCommand::SENTINEL_ABSENT, InternalRuntimeCommand::sentinelStatus(false, 1146));
        // Access denied, lost connection, or no errno at all: the question is open.
        self::assertSame(InternalRuntimeCommand::SENTINEL_UNKNOWN, InternalRuntimeCommand::sentinelStatus(false, 1142));
        self::assertSame(InternalRuntimeCommand::SENTINEL_UNKNOWN, InternalRuntimeCommand::sentinelStatus(false, 2013));
        self::assertSame(InternalRuntimeCommand::SENTINEL_UNKNOWN, InternalRuntimeCommand::sentinelStatus(false, 0));
        self::assertNotSame(InternalRuntimeCommand::SENTINEL_ABSENT, InternalRuntimeCommand::SENTINEL_UNKNOWN);
    }

    public function testRunnerCapturesRedirectsAndPassesThroughProcesses(): void
    {
        $runner = new Runner();
        self::assertSame(['status' => 0, 'output' => "hello\n"], $runner->capture(['printf', "hello\n"]));
        self::assertSame(7, $runner->capture(['sh', '-c', 'printf failed; exit 7'])['status']);
        $output = $this->temporary . '/output';
        self::assertSame(0, $runner->redirect(['printf', 'redirected'], $output));
        self::assertSame('redirected', file_get_contents($output));
        self::assertSame(0, $runner->passthrough(['true']));
    }

    /** proc_open searches the parent's PATH (posix_spawnp/execvpe), never the one passed to the child. */
    public function testRunnerResolvesTheProgramAgainstTheParentPath(): void
    {
        $bin = $this->temporary . '/bin';
        mkdir($bin);
        file_put_contents($bin . '/ck-env-probe', "#!/bin/sh\nprintf probe\n");
        chmod($bin . '/ck-env-probe', 0755);
        $runner = new Runner();
        $onlyInChild = $runner->capture(['ck-env-probe'], ['PATH' => $bin]);
        self::assertSame(2, $onlyInChild['status']);
        self::assertSame('ck: ck-env-probe was not found on PATH (' . getenv('PATH') . ")\n", $onlyInChild['output']);
        self::assertSame(['status' => 0, 'output' => ''], $runner->capture(['true'], ['PATH' => $bin]));
        self::assertSame(['status' => 0, 'output' => ''], $runner->capture(['true'], ['HOME' => $this->temporary]));
    }

    public function testRunnerDrainsLargeStdoutAndStderrWithoutDeadlock(): void
    {
        $php = PHP_SAPI === 'phpdbg' ? dirname(PHP_BINARY) . '/php' : PHP_BINARY;
        $result = (new Runner())->captureSeparate([
            $php,
            '-r',
            'fwrite(STDERR, str_repeat("e", 200000)); fwrite(STDOUT, str_repeat("o", 200000));',
        ]);
        self::assertSame(0, $result['status']);
        self::assertSame(200000, strlen($result['stdout']));
        self::assertSame(200000, strlen($result['stderr']));
    }

    public function testProfileDataReadsFiltersAndMergesProfiles(): void
    {
        $first = $this->profile('first.json', [
            'cms' => 'standalone',
            'authx' => ['header_cred' => ['api_key', 'jwt']],
            'dependencies' => [
                ['name' => 'kept'],
                ['name' => 'skipped', 'skipUf' => ['Standalone'], 'skipUfReason' => 'not supported'],
                'ignored',
            ],
            'apiUsers' => [['username' => 'api', 'role' => 'editor', 'permissions' => ['view contacts']]],
        ]);
        $second = $this->profile('second.json', [
            'apiUsers' => [['username' => 'other', 'role' => 'editor', 'permissions' => ['edit contacts']]],
        ]);
        $data = new ProfileData();
        self::assertTrue($data->hasApiUsers($first, true));
        self::assertSame('api_key,jwt', $data->authxPolicy($first, true));
        self::assertSame('standalone', $data->cms($first));
        self::assertSame(['  SKIP skipped on Standalone: not supported'], $data->skipped($first, 'Standalone'));
        self::assertSame('kept', $data->dependencies($first, 'Standalone')[0]['name']);
        $merged = $this->temporary . '/merged.json';
        $data->merge($merged, 'jwt,api_key', [$first, $second]);
        $result = $data->load($merged);
        self::assertCount(2, $result['apiUsers']);
        self::assertSame(['edit contacts', 'view contacts'], $result['apiUsers'][0]['permissions']);
        self::assertSame(['jwt', 'api_key'], $result['authx']['header_cred']);
    }

    public function testProfileDataRejectsConflictingRoles(): void
    {
        $first = $this->profile('first.json', ['apiUsers' => [['username' => 'same', 'role' => 'a']]]);
        $second = $this->profile('second.json', ['apiUsers' => [['username' => 'same', 'role' => 'b']]]);
        $this->expectException(RuntimeException::class);
        (new ProfileData())->merge($this->temporary . '/merged.json', '', [$first, $second]);
    }

    public function testExtensionInspectorReadsSafeMetadata(): void
    {
        $file = $this->temporary . '/info.xml';
        file_put_contents($file, '<extension key="org.example.test"><version>1.2.3</version><requires>'
            . '<ext>org.example.one</ext><ext> org.example.two </ext></requires></extension>');
        $inspector = new ExtensionInspector($this->temporary . '/missing-autoload.php');
        self::assertSame('org.example.test', $inspector->key($file));
        self::assertSame(['org.example.one', 'org.example.two'], $inspector->requirements($file));
        self::assertSame('1.2.3', (string) $inspector->load($file, 'org.example.test')->version);
        $inspector->assertVersion($inspector->load($file, 'org.example.test'), '');
        self::assertSame('', $inspector->key($this->temporary . '/missing.xml'));
        self::assertSame([], $inspector->requirements($this->temporary . '/missing.xml'));
    }

    public function testExtensionInspectorRejectsWrongKey(): void
    {
        $file = $this->temporary . '/info.xml';
        file_put_contents($file, '<extension key="wrong"/>');
        $this->expectException(RuntimeException::class);
        (new ExtensionInspector('missing'))->load($file, 'expected');
    }

    public function testArchiveInstallerRejectsUnsafeExtensionKeyBeforeExtraction(): void
    {
        $installer = new ExtensionArchiveInstaller(new ExtensionInspector('missing'));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unsafe extension key');
        $installer->install('missing.zip', '../unsafe', $this->temporary . '/target', '');
    }

    public function testArchiveInstallerValidatesAndAtomicallyExtractsZip(): void
    {
        $archive = $this->temporary . '/extension.zip';
        $zip = new ZipArchive();
        self::assertTrue($zip->open($archive, ZipArchive::CREATE));
        $zip->addFromString('package/info.xml', '<extension key="org.example.safe"><version>1.0.0</version></extension>');
        $zip->addFromString('package/subdirectory/file.txt', 'contents');
        $zip->close();
        $target = $this->temporary . '/installed';
        (new ExtensionArchiveInstaller(new ExtensionInspector('missing')))
            ->install($archive, 'org.example.safe', $target, '');
        self::assertSame('contents', file_get_contents($target . '/subdirectory/file.txt'));

        $badArchive = $this->temporary . '/multiple-roots.zip';
        $zip = new ZipArchive();
        self::assertTrue($zip->open($badArchive, ZipArchive::CREATE));
        $zip->addFromString('one/info.xml', '<extension/>');
        $zip->addFromString('two/file.txt', 'contents');
        $zip->close();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('one root directory');
        (new ExtensionArchiveInstaller(new ExtensionInspector('missing')))
            ->install($badArchive, 'org.example.safe', $this->temporary . '/bad-target', '');
    }

    public function testLifecycleRejectsUnsafeKeyBeforeStartingCv(): void
    {
        file_put_contents($this->temporary . '/info.xml', '<extension key="org.example.safe"><file>safe</file></extension>');
        $before = getcwd();
        chdir($this->temporary);
        try {
            $runner = new RecordingRunner();
            $application = new Application(dirname(__DIR__, 2) . '/toolbelt/bin', dirname(__DIR__, 2), $runner);
            self::assertSame(2, $application->run(['lifecycle', '--key', "x');phpinfo();//"], 'ck'));
            self::assertSame([], $runner->commands);
        } finally {
            chdir($before === false ? dirname(__DIR__, 2) : $before);
        }
    }

    public function testCoverageTreatsOptionalTestsPolicyWithItsMandatoryReasonAsOptional(): void
    {
        file_put_contents($this->temporary . '/info.xml', '<extension key="org.example.safe"><file>safe</file></extension>');
        $before = getcwd();
        chdir($this->temporary);
        try {
            // ckconform --policy prints the whole value, and policy.tests
            // requires a reason, so the value is never the bare word.
            ob_start();
            $status = (new CoverageCommand(dirname(__DIR__, 2), new PolicyRunner('optional -- no PHP in this repo')))->run([]);
            $output = (string) ob_get_clean();
            self::assertSame(0, $status);
            self::assertStringContainsString('declares it optional', $output);

            ob_start();
            $required = (new CoverageCommand(dirname(__DIR__, 2), new PolicyRunner('')))->run([]);
            ob_end_clean();
            self::assertSame(2, $required);
        } finally {
            chdir($before === false ? dirname(__DIR__, 2) : $before);
        }
    }

    public function testLiteralOptionTerminatorPreservesDashPrefixedPaths(): void
    {
        file_put_contents($this->temporary . '/info.xml', '<extension key="org.example.safe"><file>safe</file></extension>');
        file_put_contents($this->temporary . '/composer.json', '{"require":{"php":">=8.1"}}');
        file_put_contents($this->temporary . '/-odd.php', '<?php');
        $before = getcwd();
        chdir($this->temporary);
        try {
            $formatRunner = new RecordingRunner();
            self::assertSame(0, (new FormatCommand(dirname(__DIR__, 2), $formatRunner))->run(['--check', '--', '-odd.php']));
            self::assertTrue($formatRunner->passedArgument('-odd.php'));

            $compatibilityRunner = new RecordingRunner();
            self::assertSame(0, (new CompatibilityCommand($compatibilityRunner))->run(['--php', '8.1', '--', '-odd.php']));
            self::assertTrue($compatibilityRunner->passedArgument('-odd.php'));
        } finally {
            chdir($before === false ? dirname(__DIR__, 2) : $before);
        }
    }

    public function testReleaseVersionStripsExactlyOneVPrefix(): void
    {
        file_put_contents($this->temporary . '/info.xml', '<extension key="org.example.safe"><file>safe</file><version>1.2.3</version></extension>');
        $before = getcwd();
        chdir($this->temporary);
        try {
            self::assertSame(0, (new ReleaseCommand(dirname(__DIR__, 2), new RecordingRunner()))
                ->run(['check', '--version', 'v1.2.3']));
            self::assertSame(1, (new ReleaseCommand(dirname(__DIR__, 2), new RecordingRunner()))
                ->run(['check', '--version', 'vv1.2.3']));
        } finally {
            chdir($before === false ? dirname(__DIR__, 2) : $before);
        }
    }

    public function testReleaseAcceptsDashPrefixedArchiveAfterOptionTerminator(): void
    {
        file_put_contents($this->temporary . '/info.xml', '<extension key="org.example.safe"><file>safe</file><version>1.2.3</version></extension>');
        $archive = $this->temporary . '/-archive.zip';
        $zip = new ZipArchive();
        self::assertTrue($zip->open($archive, ZipArchive::CREATE));
        $zip->addFromString('org.example.safe/info.xml', '<extension key="org.example.safe"><version>1.2.3</version></extension>');
        $zip->close();
        $before = getcwd();
        chdir($this->temporary);
        try {
            self::assertSame(0, (new ReleaseCommand(dirname(__DIR__, 2), new RecordingRunner()))
                ->run(['verify', '--', '-archive.zip']));
        } finally {
            chdir($before === false ? dirname(__DIR__, 2) : $before);
        }
    }

    public function testReleaseStagesTheDeclaredBuildOutputAndNothingElse(): void
    {
        $repository = $this->stagedRepository(['ang/app.bundle.js', 'dist/lib']);
        $this->write($repository, 'ang/app.bundle.js', 'built();');
        $this->write($repository, 'ang/unlisted.bundle.js', 'undeclared();');
        $this->write($repository, 'dist/lib/lib.min.js', 'lib();');
        $this->write($repository, 'dist/lib/licenses/LICENSE-dependency', 'license');
        $this->write($repository, 'notes.txt', 'untracked and not ignored');

        [$status, $entries] = $this->releaseDist($repository);

        self::assertSame(0, $status);
        foreach (['staged.php', 'ang/app.bundle.js', 'dist/lib/lib.min.js', 'dist/lib/licenses/LICENSE-dependency'] as $path) {
            self::assertContains("org.example.staged/{$path}", $entries);
        }
        foreach ($entries as $entry) {
            self::assertDoesNotMatchRegularExpression('#unlisted|notes\.txt|/tests/|civikitchen\.yaml|bun\.lock#', $entry);
        }
        // The checkout's own index still matches HEAD.
        self::assertSame('', $this->git($repository, 'diff', '--cached', '--name-only'));
    }

    public function testReleaseRefusesDeclaredBuildOutputTheBuildDidNotWrite(): void
    {
        $repository = $this->stagedRepository(['ang/app.bundle.js', 'dist/lib']);
        $this->write($repository, 'ang/app.bundle.js', 'built();');
        self::assertTrue(mkdir($repository . '/dist/lib', 0777, true));

        [$status, $entries] = $this->releaseDist($repository);

        self::assertSame(1, $status);
        self::assertSame([], $entries);
        $cli = (new Runner())->captureSeparate(
            ['php', dirname(__DIR__, 2) . '/toolbelt/bin/ckrelease', 'dist', '--output', $this->temporary . '/cli'],
            null,
            $repository,
        );
        self::assertSame(1, $cli['status']);
        self::assertStringContainsString("policy.dist.build output missing:\n  dist/lib\n", $cli['stderr']);
        self::assertStringContainsString('Run bun install --frozen-lockfile && bun run build first', $cli['stderr']);
        self::assertFileDoesNotExist($this->temporary . '/cli/org.example.staged-1.0.0.zip');
    }

    public function testReleaseRefusesDeclaredBuildOutputThatGitTracks(): void
    {
        $repository = $this->stagedRepository(['staged.php']);

        [$status, $entries] = $this->releaseDist($repository);

        self::assertSame(1, $status);
        self::assertSame([], $entries);
        $cli = (new Runner())->captureSeparate(
            ['php', dirname(__DIR__, 2) . '/toolbelt/bin/ckrelease', 'dist', '--output', $this->temporary . '/cli'],
            null,
            $repository,
        );
        self::assertSame(1, $cli['status']);
        self::assertStringContainsString("policy.dist.build output is tracked at HEAD:\n  staged.php\n", $cli['stderr']);
    }

    public function testReleaseStagesOntoATreeThatAlreadyBundlesVendor(): void
    {
        $repository = $this->stagedRepository(['ang/app.bundle.js']);
        $this->write($repository, 'ang/app.bundle.js', 'built();');
        $this->write($repository, 'vendor/autoload.php', '<?php');
        $this->git($repository, 'add', '--force', 'vendor');

        [$status, $entries] = $this->releaseDist($repository, $this->git($repository, 'write-tree'));

        self::assertSame(0, $status);
        self::assertContains('org.example.staged/vendor/autoload.php', $entries);
        self::assertContains('org.example.staged/ang/app.bundle.js', $entries);
    }

    public function testVerifyRequiresEveryDeclaredBuildOutputInTheArchive(): void
    {
        $repository = $this->stagedRepository(['ang/app.bundle.js']);
        $archive = $this->temporary . '/archive.zip';
        $zip = new ZipArchive();
        self::assertTrue($zip->open($archive, ZipArchive::CREATE));
        $zip->addFromString('org.example.staged/info.xml', '<extension key="org.example.staged"><version>1.0.0</version></extension>');
        $zip->close();
        $verify = static fn (): int => (new ReleaseCommand(dirname(__DIR__, 2)))->run(['verify', $archive]);

        self::assertSame(1, $this->inRepository($repository, $verify));
        self::assertTrue($zip->open($archive));
        $zip->addFromString('org.example.staged/ang/app.bundle.js', 'built();');
        $zip->close();
        self::assertSame(0, $this->inRepository($repository, $verify));
    }

    public function testExtensionEditorUpdatesOpenAndProprietaryMetadata(): void
    {
        $directory = $this->temporary . '/extension';
        mkdir($directory);
        file_put_contents($directory . '/info.xml', '<extension><license>AGPL-3.0</license><urls>'
            . '<url desc="Licensing">https://example.test</url></urls><php_compatibility>'
            . '<ver>8.1</ver><ver>8.2</ver><ver>8.3</ver></php_compatibility></extension>');
        file_put_contents($directory . '/README.md', 'Example, licensed under [AGPL](LICENSE.txt).');
        file_put_contents($directory . '/LICENSE.txt', "Copyright old\nBody\n");
        file_put_contents($directory . '/composer.json', '{"license":"AGPL-3.0","require":{}}');
        file_put_contents($directory . '/phpstan.neon.dist', "parameters:\n  phpVersion: 80100\n");
        $editor = new ExtensionEditor();
        $editor->updateComposer($directory . '/composer.json', 'Proprietary', '8.2');
        $composer = json_decode((string) file_get_contents($directory . '/composer.json'), true);
        self::assertSame('proprietary', $composer['license']);
        self::assertTrue($composer['private']);
        self::assertSame('>=8.2', $composer['require']['php']);
        $editor->rewriteLicense($directory, 'Proprietary', 'Example Ltd');
        self::assertStringContainsString('proprietary software', (string) file_get_contents($directory . '/README.md'));
        self::assertStringNotContainsString('Licensing', (string) file_get_contents($directory . '/info.xml'));
        $editor->alignPhpFloor($directory . '/composer.json', $directory . '/info.xml', $directory . '/phpstan.neon.dist');
        self::assertStringNotContainsString('<ver>8.1</ver>', (string) file_get_contents($directory . '/info.xml'));
        self::assertStringContainsString('phpVersion: 80200', (string) file_get_contents($directory . '/phpstan.neon.dist'));
    }

    public function testRepositoryFilesUsesGitAndFiltersGeneratedAndVendoredPaths(): void
    {
        $root = $this->temporary . '/repo';
        mkdir($root);
        mkdir($root . '/toolbelt/bin', 0700, true);
        file_put_contents($root . '/toolbelt/bin/ckconform', "#!/bin/sh\nexit 0\n");
        chmod($root . '/toolbelt/bin/ckconform', 0700);
        (new Runner())->capture(['git', 'init', '-q'], null, $root);
        foreach (['keep.php', 'generated.civix.php', 'DAO/Generated.php', 'vendor/ignored.php'] as $file) {
            $path = $root . '/' . $file;
            is_dir(dirname($path)) || mkdir(dirname($path), 0700, true);
            file_put_contents($path, '<?php');
        }
        (new Runner())->capture(['git', 'add', '.'], null, $root);
        (new Runner())->capture(['git', '-c', 'user.name=Test', '-c', 'user.email=test@example.test', 'commit', '-qm', 'fixture'], null, $root);
        file_put_contents($root . '/keep.php', "<?php\n// changed\n");
        $files = new Files($root);
        $before = getcwd();
        chdir($root);
        try {
            self::assertTrue($files->isGitCheckout());
            self::assertSame(['keep.php'], $files->source(['php']));
            self::assertSame(['keep.php'], $files->changedPhp());
        } finally {
            chdir($before === false ? dirname(__DIR__, 2) : $before);
        }
    }

    public function testRepositoryFilesScopesChangedFilesToTheCurrentExtensionDirectory(): void
    {
        $root = $this->temporary . '/multi';
        mkdir($root . '/base/CRM', 0700, true);
        mkdir($root . '/addon/CRM', 0700, true);
        mkdir($root . '/toolbelt/bin', 0700, true);
        file_put_contents($root . '/toolbelt/bin/ckconform', "#!/bin/sh\nexit 0\n");
        chmod($root . '/toolbelt/bin/ckconform', 0700);
        (new Runner())->capture(['git', 'init', '-q'], null, $root);
        foreach (['base/CRM/A.php', 'addon/CRM/B.php'] as $file) {
            file_put_contents($root . '/' . $file, '<?php');
        }
        (new Runner())->capture(['git', 'add', '.'], null, $root);
        (new Runner())->capture(['git', '-c', 'user.name=Test', '-c', 'user.email=test@example.test', 'commit', '-qm', 'fixture'], null, $root);
        file_put_contents($root . '/base/CRM/A.php', "<?php\n// changed\n");
        file_put_contents($root . '/addon/CRM/B.php', "<?php\n// changed\n");
        file_put_contents($root . '/addon/CRM/C.php', '<?php');
        $files = new Files($root);
        $before = getcwd();
        chdir($root . '/addon');
        try {
            // Paths relative to the extension, and the neighbour left out.
            self::assertSame(['CRM/B.php', 'CRM/C.php'], $files->changedPhp());
        } finally {
            chdir($before === false ? dirname(__DIR__, 2) : $before);
        }
    }

    public function testRepositoryFilesTrustsTheWorktreeRootRatherThanTheCurrentDirectory(): void
    {
        $root = $this->temporary . '/guard';
        mkdir($root . '/addon', 0700, true);
        mkdir($root . '/.git', 0700, true);
        $recorder = new class () extends Runner {
            /** @var list<string> */
            public array $commands = [];

            /** @param non-empty-list<string> $command @return array{status: int, output: string} */
            public function capture(array $command, ?array $environment = null, ?string $workingDirectory = null): array
            {
                $this->commands[] = implode(' ', $command);
                return ['status' => 1, 'output' => ''];
            }
        };
        $files = new Files($root, $recorder);
        $before = getcwd();
        chdir($root . '/addon');
        try {
            self::assertFalse($files->isGitCheckout());
        } finally {
            chdir($before === false ? dirname(__DIR__, 2) : $before);
        }
        self::assertSame(
            'git -c safe.directory=' . (string) realpath($root) . ' rev-parse --is-inside-work-tree',
            $recorder->commands[0],
        );
    }

    public function testRunnerNamesAProgramThatIsNotOnPathInsteadOfSpawningIt(): void
    {
        $runner = new Runner();
        $result = $runner->capture(['ck-no-such-tool'], ['PATH' => $this->temporary]);
        self::assertSame(2, $result['status']);
        self::assertSame('ck: ck-no-such-tool was not found on PATH (' . getenv('PATH') . ")\n", $result['output']);

        // An absolute path is not looked up, so it gets its own message.
        $result = $runner->capture([$this->temporary . '/nope']);
        self::assertSame(2, $result['status']);
        self::assertSame("ck: {$this->temporary}/nope is not an executable file\n", $result['output']);

        // A program that IS there still runs.
        $result = $runner->capture(['env'], ['PATH' => '/usr/bin:/bin']);
        self::assertSame(0, $result['status']);
    }

    public function testLintPinsPhpExtensionsEvenWithAProjectPhpcsConfig(): void
    {
        file_put_contents($this->temporary . '/info.xml', '<extension key="org.example.safe"><file>safe</file></extension>');
        file_put_contents($this->temporary . '/phpcs.xml.dist', '<?xml version="1.0"?><ruleset name="p"><file>.</file><rule ref="CiviKitchen"/></ruleset>');
        file_put_contents($this->temporary . '/README.md', str_repeat('long ', 60) . "\n");
        $before = getcwd();
        chdir($this->temporary);
        ob_start();
        try {
            $runner = new RecordingRunner();
            (new Application(dirname(__DIR__, 2) . '/toolbelt/bin', dirname(__DIR__, 2), $runner))->run(['lint', '--all'], 'ck');
            $phpcs = $this->firstCommand($runner, 'phpcs');
            // The CiviKitchen ruleset refs Drupal, whose nested extensions arg
            // adds md and yml; only the CLI flag overrides it.
            self::assertContains('--extensions=php', $phpcs);
            // A project config brings its own standard.
            self::assertNotContains('--standard=CiviKitchen', $phpcs);

            unlink($this->temporary . '/phpcs.xml.dist');
            $bare = new RecordingRunner();
            (new Application(dirname(__DIR__, 2) . '/toolbelt/bin', dirname(__DIR__, 2), $bare))->run(['lint', '--all'], 'ck');
            $phpcs = $this->firstCommand($bare, 'phpcs');
            self::assertContains('--extensions=php', $phpcs);
            self::assertContains('--standard=CiviKitchen', $phpcs);
        } finally {
            ob_end_clean();
            chdir($before === false ? dirname(__DIR__, 2) : $before);
        }
    }

    public function testFormatChecksUntrackedFilesToo(): void
    {
        $root = $this->temporary . '/fmt';
        mkdir($root . '/Civi', 0700, true);
        file_put_contents($root . '/info.xml', '<extension key="org.example.safe"><file>safe</file></extension>');
        file_put_contents($root . '/Civi/Tracked.php', "<?php\n");
        (new Runner())->capture(['git', 'init', '-q'], null, $root);
        (new Runner())->capture(['git', 'add', '.'], null, $root);
        (new Runner())->capture(['git', '-c', 'user.name=Test', '-c', 'user.email=test@example.test', 'commit', '-qm', 'fixture'], null, $root);
        file_put_contents($root . '/Civi/Untracked.php', "<?php  class  Untracked {}\n");
        // Real git, faked toolchain: the file list is what is under test.
        $runner = new class () extends Runner {
            /** @var list<list<string>> */
            public array $commands = [];

            public function capture(array $command, ?array $environment = null, ?string $workingDirectory = null): array
            {
                $this->commands[] = $command;
                if ($command[0] === 'git') {
                    return parent::capture($command, $environment, $workingDirectory);
                }
                return ['status' => 0, 'output' => $command[0] === 'sh' ? '/fake/mago' : ''];
            }

            public function passthrough(array $command, ?array $environment = null, ?string $workingDirectory = null): int
            {
                $this->commands[] = $command;
                return 0;
            }
        };
        $before = getcwd();
        chdir($root);
        ob_start();
        try {
            self::assertSame(0, (new FormatCommand(dirname(__DIR__, 2), $runner))->run(['--check']));
        } finally {
            ob_end_clean();
            chdir($before === false ? dirname(__DIR__, 2) : $before);
        }
        $formatted = array_merge(...array_values(array_filter($runner->commands, static fn(array $command): bool => $command[0] === '/fake/mago')));
        self::assertContains('Civi/Untracked.php', $formatted);
        self::assertContains('Civi/Tracked.php', $formatted);
    }

    /**
     * The first recorded command whose program is $program.
     *
     * @return list<string>
     */
    private function firstCommand(RecordingRunner $runner, string $program): array
    {
        foreach ($runner->commands as $command) {
            if ($command[0] === $program) {
                return $command;
            }
        }
        self::fail("no {$program} command was recorded");
    }

    /** @param array<string, mixed> $contents */
    private function profile(string $name, array $contents): string
    {
        $file = $this->temporary . '/' . $name;
        file_put_contents($file, json_encode($contents, JSON_THROW_ON_ERROR));
        return $file;
    }

    /**
     * A committed extension repository whose civikitchen.yaml declares these build outputs.
     *
     * @param list<string> $outputs
     */
    private function stagedRepository(array $outputs): string
    {
        $repository = $this->temporary . '/extension';
        $this->write($repository, 'info.xml', '<extension key="org.example.staged"><file>staged</file><version>1.0.0</version></extension>');
        $this->write($repository, 'staged.php', '<?php');
        $this->write($repository, 'tests/StagedTest.php', '<?php');
        $this->write($repository, 'package.json', '{"packageManager": "bun@1.4.0"}');
        $this->write($repository, 'bun.lock', '{}');
        $this->write($repository, '.gitignore', "/ang/\n/dist/\n/vendor/\n");
        $this->write($repository, 'civikitchen.yaml', "version: 1\npolicy:\n  dist:\n    build:\n      tool: bun\n      outputs:\n"
            . implode('', array_map(static fn (string $output): string => "        - {$output}\n", $outputs)));
        $this->git($repository, 'init', '-q');
        $this->git($repository, 'add', '-A');
        $this->git($repository, '-c', 'user.name=ck', '-c', 'user.email=ck@example.org', 'commit', '-q', '-m', 'fixture');
        return $repository;
    }

    private function write(string $repository, string $path, string $contents): void
    {
        $file = $repository . '/' . $path;
        if (!is_dir(dirname($file))) {
            self::assertTrue(mkdir(dirname($file), 0777, true));
        }
        self::assertNotFalse(file_put_contents($file, $contents));
    }

    /** Git in the fixture, insulated from the developer's own configuration. */
    private function git(string $repository, string ...$arguments): string
    {
        $result = (new Runner())->captureSeparate(
            ['git', ...$arguments],
            [...getenv(), 'GIT_CONFIG_GLOBAL' => '/dev/null', 'GIT_CONFIG_SYSTEM' => '/dev/null'],
            $repository,
        );
        self::assertSame(0, $result['status'], $result['stderr']);
        return trim($result['stdout']);
    }

    /** @return array{0: int, 1: list<string>} the exit status and the entry names of the zip it left */
    private function releaseDist(string $repository, string $ref = 'HEAD'): array
    {
        $output = $this->temporary . '/dist';
        $status = $this->inRepository($repository, static fn (): int => (new ReleaseCommand(dirname(__DIR__, 2)))
            ->run(['dist', '--ref', $ref, '--output', $output]));
        $entries = [];
        $zip = new ZipArchive();
        if (is_file($output . '/org.example.staged-1.0.0.zip') && $zip->open($output . '/org.example.staged-1.0.0.zip') === true) {
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $entries[] = (string) $zip->getNameIndex($index);
            }
            $zip->close();
        }
        return [$status, $entries];
    }

    /** @param callable(): int $command */
    private function inRepository(string $repository, callable $command): int
    {
        $before = getcwd();
        chdir($repository);
        ob_start();
        try {
            return $command();
        } finally {
            ob_end_clean();
            chdir($before === false ? dirname(__DIR__, 2) : $before);
        }
    }
}

final class RecordingRunner extends Runner
{
    /** @var list<list<string>> */
    public array $commands = [];

    public function capture(array $command, ?array $environment = null, ?string $workingDirectory = null): array
    {
        $this->commands[] = $command;
        if ($command[0] === 'sh') {
            return ['status' => 0, 'output' => '/fake/mago'];
        }
        if ($command[0] === 'git') {
            $joined = implode(' ', $command);
            if (str_contains($joined, 'rev-parse')) {
                return ['status' => 0, 'output' => "true\n"];
            }
            return str_contains($joined, '*.php') || in_array('-odd.php', $command, true)
                ? ['status' => 0, 'output' => "-odd.php\n"]
                : ['status' => 0, 'output' => ''];
        }
        if (str_ends_with($command[0], 'ckconform')) {
            return ['status' => 0, 'output' => "dir tests\n"];
        }
        return ['status' => 0, 'output' => ''];
    }

    public function passthrough(array $command, ?array $environment = null, ?string $workingDirectory = null): int
    {
        $this->commands[] = $command;
        return 0;
    }

    public function passedArgument(string $argument): bool
    {
        foreach ($this->commands as $command) {
            if (in_array($argument, $command, true)) {
                return true;
            }
        }
        return false;
    }
}

/** Answers `ckconform --policy <key>` with one canned value. */
final class PolicyRunner extends Runner
{
    public function __construct(private readonly string $value)
    {
    }

    public function capture(array $command, ?array $environment = null, ?string $workingDirectory = null): array
    {
        return ['status' => 0, 'output' => $this->value . "\n"];
    }
}
