<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests\Check;

use CiviKitchen\Ckconform\Check\DeployHygieneCheck;
use CiviKitchen\Ckconform\Tests\CheckTestCase;

final class DeployHygieneCheckTest extends CheckTestCase
{
    public function testFailsWhenADotenvIsTracked(): void
    {
        $context = $this->repo(['.env' => "DM_RPC_CODE=secret\n"], git: true);
        $this->assertFails(
            $this->run_(new DeployHygieneCheck(), $context),
            'deploy ships .env',
        );
    }

    public function testFailsOnDotenvVariantsAnywhereInTheTree(): void
    {
        $context = $this->repo(['bin/.env.local' => "SECRET=1\n"], git: true);
        $this->assertFails(
            $this->run_(new DeployHygieneCheck(), $context),
            'deploy ships bin/.env.local',
        );
    }

    public function testEnvTemplatesAreFine(): void
    {
        $context = $this->repo([
            '.env.example' => "DM_RPC_CODE=\n",
            '.env.dist' => "DM_RPC_CODE=\n",
        ], git: true);
        $this->assertPasses($this->run_(new DeployHygieneCheck(), $context));
    }

    public function testFailsWhenVarIsTracked(): void
    {
        $context = $this->repo(['var/export-2026.json' => '{}'], git: true);
        $this->assertFails(
            $this->run_(new DeployHygieneCheck(), $context),
            'deploy ships var/export-2026.json',
        );
    }

    public function testFailsOnAStrayDocumentOutsideDocs(): void
    {
        $context = $this->repo(['spec/interface.pdf' => '%PDF-1.4'], git: true);
        $this->assertFails(
            $this->run_(new DeployHygieneCheck(), $context),
            'deploy ships spec/interface.pdf',
        );
    }

    public function testFailsOnATopLevelCsv(): void
    {
        $context = $this->repo(['contacts.csv' => "id,email\n"], git: true);
        $this->assertFails(
            $this->run_(new DeployHygieneCheck(), $context),
            'deploy ships contacts.csv',
        );
    }

    public function testDocumentsUnderDocsTestsExamplesAreFine(): void
    {
        $context = $this->repo([
            'docs/spec.pdf' => '%PDF-1.4',
            'tests/fixtures/import.csv' => "id\n",
            'examples/demo.xlsx' => '',
        ], git: true);
        $this->assertPasses($this->run_(new DeployHygieneCheck(), $context));
    }

    public function testCleanRepoIsSilent(): void
    {
        $context = $this->repo([
            'src/Foo.php' => "<?php\n",
            '.gitignore' => ".env\nvar/\n",
        ], git: true);
        $this->assertSilent($this->run_(new DeployHygieneCheck(), $context));
    }

    public function testSilentOutsideAGitRepo(): void
    {
        $context = $this->repo(['.env' => "SECRET=1\n"]);
        $this->assertSilent($this->run_(new DeployHygieneCheck(), $context));
    }

    public function testPolicyAllowsDeclaredPaths(): void
    {
        $context = $this->repo([
            '__policy_fixture' => "deploy_hygiene=data/plz_wk.csv,spec/interface.pdf -- shipped on purpose\n",
            'data/plz_wk.csv' => "plz,wk\n",
            'spec/interface.pdf' => '%PDF-1.4',
        ], git: true);
        $this->assertPasses($this->run_(new DeployHygieneCheck(), $context));
    }

    public function testPolicyOnlyExemptsTheDeclaredPath(): void
    {
        $context = $this->repo([
            '__policy_fixture' => "deploy_hygiene=data/plz_wk.csv -- shipped on purpose\n",
            'data/plz_wk.csv' => "plz,wk\n",
            '.env' => "SECRET=1\n",
        ], git: true);
        $this->assertFails(
            $this->run_(new DeployHygieneCheck(), $context),
            'deploy ships .env',
        );
    }
}
