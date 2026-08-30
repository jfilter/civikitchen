<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests;

use CiviKitchen\Ckconform\DistPaths;

final class DistPathsTest extends CheckTestCase
{
    public function testUnifiedConfigNeverShipsByDefault(): void
    {
        $excluded = DistPaths::excluded($this->repo([]));
        self::assertFalse(DistPaths::ships('civikitchen.yaml', $excluded));
    }

    public function testSecretConfigurationNeverShipsByDefault(): void
    {
        $excluded = DistPaths::excluded($this->repo([]));
        foreach (['.env', '.env.local', '.netrc', '.npmrc', 'auth.json', 'credentials.json'] as $path) {
            self::assertFalse(DistPaths::ships($path, $excluded), $path);
        }
    }

    public function testUnifiedConfigCannotBeForcedIntoARelease(): void
    {
        $context = $this->repo([
            '__policy_fixture' => "dist_include=civikitchen.yaml -- would disclose development policy\n",
        ]);
        self::assertStringContainsString('protected', implode("\n", DistPaths::problems($context)));
    }

    public function testDistPathsCannotEscapeTheRepository(): void
    {
        $context = $this->repo([
            '__policy_fixture' => "dist_exclude=../outside\n",
        ]);
        self::assertStringContainsString('safe repo-relative path', implode("\n", DistPaths::problems($context)));
    }

    public function testCommaInStructuredPathIsNotAListSeparator(): void
    {
        $context = $this->repo([
            'civikitchen.yaml' => "version: 1\npolicy:\n  dist:\n    exclude:\n      - docs/a,b\n",
        ]);
        $excluded = DistPaths::excluded($context);
        self::assertFalse(DistPaths::ships('docs/a,b', $excluded));
        self::assertTrue(DistPaths::ships('docs/a', $excluded));
        self::assertTrue(DistPaths::ships('b', $excluded));
    }

    public function testReasonDelimiterInExcludePathIsLiteral(): void
    {
        $context = $this->repo([
            'civikitchen.yaml' => "version: 1\npolicy:\n  dist:\n    exclude:\n      - 'docs/foo -- bar'\n",
        ]);
        $excluded = DistPaths::excluded($context);
        self::assertFalse(DistPaths::ships('docs/foo -- bar', $excluded));
        self::assertTrue(DistPaths::ships('docs/foo', $excluded));
    }

    public function testEverySecretPathIsProtectedFromInclude(): void
    {
        foreach (['.netrc', '.npmrc', '.pypirc', 'auth.json', 'credentials.json', '.env.production'] as $path) {
            $context = $this->repo(['__policy_fixture' => "dist_include={$path} -- unsafe fixture\n"]);
            self::assertStringContainsString('protected', implode("\n", DistPaths::problems($context)), $path);
        }
    }
}
