<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests;

use CiviKitchen\Ckconform\Reporter;
use PHPUnit\Framework\TestCase;

final class ReporterTest extends TestCase
{
    public function testJsonCarriesRuleAndCounts(): void
    {
        $reporter = new Reporter();
        $reporter->setRule('demo-check');
        $reporter->failAt('src/Demo.php', 12, 'broken');
        $reporter->warn('review this');

        $json = json_decode($reporter->renderJson(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(1, $json['failures']);
        self::assertSame('demo-check', $json['results'][0]['rule']);
    }

    public function testSarifCarriesLocationAndSeverity(): void
    {
        $reporter = new Reporter();
        $reporter->setRule('demo-check');
        $reporter->failAt('tests/My Feature.php', 12, 'broken');

        $sarif = json_decode($reporter->renderSarif(), true, 512, JSON_THROW_ON_ERROR);
        $result = $sarif['runs'][0]['results'][0];
        self::assertSame('demo-check', $result['ruleId']);
        self::assertSame('error', $result['level']);
        self::assertSame('tests/My%20Feature.php', $result['locations'][0]['physicalLocation']['artifactLocation']['uri']);
        self::assertSame(12, $result['locations'][0]['physicalLocation']['region']['startLine']);
    }

    public function testGithubOmitsPassesAndEscapesCommands(): void
    {
        $reporter = new Reporter();
        $reporter->setRule('demo-check');
        $reporter->ok('fine');
        $reporter->warnAt('tests/My Feature.php', 9, "one: two, three\nnext");

        $github = $reporter->renderGithub();
        self::assertStringNotContainsString('fine', $github);
        self::assertStringContainsString('::warning title=ckconform/demo-check,file=tests/My Feature.php,line=9::one: two, three%0Anext', $github);
    }
}
