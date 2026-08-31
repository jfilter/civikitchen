<?php

declare(strict_types=1);

use CiviKitchen\Toolbelt\Cli\Application;
use CiviKitchen\Toolbelt\Cli\CompatibilityCommand;
use CiviKitchen\Toolbelt\Cli\FormatCommand;
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

    /** @param array<string, mixed> $contents */
    private function profile(string $name, array $contents): string
    {
        $file = $this->temporary . '/' . $name;
        file_put_contents($file, json_encode($contents, JSON_THROW_ON_ERROR));
        return $file;
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
