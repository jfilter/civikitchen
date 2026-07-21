<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests\Check;

use CiviKitchen\Ckconform\Check\AutoloadPathCheck;
use CiviKitchen\Ckconform\Tests\CheckTestCase;

final class AutoloadPathCheckTest extends CheckTestCase
{
    private function composer(string $autoload): string
    {
        return "{\n  \"name\": \"civico/ext\",\n  \"autoload\": " . $autoload . "\n}\n";
    }

    public function testSilentWithoutComposer(): void
    {
        $this->assertSilent($this->run_(new AutoloadPathCheck(), $this->repo([], git: true)));
    }

    public function testExistingPsr4DirPasses(): void
    {
        $context = $this->repo([
            'composer.json' => $this->composer('{ "psr-4": { "Civi\\\\Ext\\\\": "Civi/Ext/" } }'),
            'Civi/Ext/Thing.php' => "<?php\n",
        ], git: true);
        $this->assertPasses($this->run_(new AutoloadPathCheck(), $context));
    }

    public function testAMissingPsr4DirFails(): void
    {
        $context = $this->repo([
            'composer.json' => $this->composer('{ "psr-4": { "Civi\\\\Ext\\\\": "Civi/Gone/" } }'),
            'Civi/Ext/Thing.php' => "<?php\n",
        ], git: true);
        $this->assertFails($this->run_(new AutoloadPathCheck(), $context), 'Civi/Gone');
    }

    /** The macOS-green/Linux-red case: right dir, wrong case. */
    public function testACaseOnlyMismatchFails(): void
    {
        $context = $this->repo([
            'composer.json' => $this->composer('{ "psr-4": { "Civi\\\\Ext\\\\": "civi/ext/" } }'),
            'Civi/Ext/Thing.php' => "<?php\n",
        ], git: true);
        $this->assertFails($this->run_(new AutoloadPathCheck(), $context), 'civi/ext');
    }

    /** PSR-0 "CRM_": "." is the repo root, always present. */
    public function testPsr0RootPasses(): void
    {
        $context = $this->repo([
            'composer.json' => $this->composer('{ "psr-0": { "CRM_": "." }, "psr-4": { "Civi\\\\Ext\\\\": "Civi/Ext/" } }'),
            'Civi/Ext/Thing.php' => "<?php\n",
            'CRM/Ext/Page.php' => "<?php\n",
        ], git: true);
        $this->assertPasses($this->run_(new AutoloadPathCheck(), $context));
    }

    public function testAMissingFilesEntryFails(): void
    {
        $context = $this->repo([
            'composer.json' => $this->composer('{ "files": [ "src/helpers.php" ] }'),
            'Civi/Ext/Thing.php' => "<?php\n",
        ], git: true);
        $this->assertFails($this->run_(new AutoloadPathCheck(), $context), 'src/helpers.php');
    }

    public function testAnExistingFilesEntryPasses(): void
    {
        $context = $this->repo([
            'composer.json' => $this->composer('{ "files": [ "src/helpers.php" ] }'),
            'src/helpers.php' => "<?php\n",
        ], git: true);
        $this->assertPasses($this->run_(new AutoloadPathCheck(), $context));
    }
}
