<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests\Check;

use CiviKitchen\Ckconform\Check\MovedRepositoryCheck;
use CiviKitchen\Ckconform\Tests\CheckTestCase;

final class MovedRepositoryCheckTest extends CheckTestCase
{
    public function testSilentOnTheTemplate(): void
    {
        $template = dirname(__DIR__, 4) . '/scaffold/template/extension';
        $files = [];
        foreach (['.github/workflows/ci.yml', '.github/workflows/release.yml', '.docker/docker-compose.yml', '.docker/docker-compose.ci.yml', 'civikitchen.yaml'] as $file) {
            $files[$file] = (string) file_get_contents($template . '/' . $file);
            self::assertStringContainsString('civico-de/civikitchen', $files[$file], $file);
        }
        $this->assertSilent($this->run_(new MovedRepositoryCheck(), $this->repo($files, git: true)));
    }

    public function testSilentOnProseAndDependencies(): void
    {
        $context = $this->repo([
            'CHANGELOG.md' => "- CI calls jfilter/civikitchen/.github/workflows/extension-ci.yml@v1.\n",
            'vendor/acme/widget/composer.json' => "{\"require\": {\"jfilter/civikitchen-scenario-schema\": \"*\"}}\n",
            'js/node_modules/@jfilter/civicrm-profile-schema/package.json' => "{\"name\": \"@jfilter/civicrm-profile-schema\"}\n",
        ], git: true);
        $this->assertSilent($this->run_(new MovedRepositoryCheck(), $context));
    }

    public function testWarnsOnTheOldCallerWithTheNewName(): void
    {
        $context = $this->repo([
            '.github/workflows/ci.yml' => "jobs:\n  ci:\n    uses: jfilter/civikitchen/.github/workflows/extension-ci.yml@v1\n",
        ], git: true);
        self::assertSame([
            '.github/workflows/ci.yml:3: jfilter/civikitchen moved to civico-de/civikitchen and no longer receives updates',
        ], $this->run_(new MovedRepositoryCheck(), $context)->messages('warn'));
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function oldReferences(): iterable
    {
        yield 'dev compose image' => ['.docker/docker-compose.yml', "    image: \${CIVIKITCHEN_IMAGE:-ghcr.io/jfilter/civikitchen:v1}\n", 'civico-de/civikitchen'];
        yield 'scenario schema' => ['civikitchen.yaml', "\"\$schema\": https://raw.githubusercontent.com/jfilter/civikitchen/main/x.json\n", 'civico-de/civikitchen'];
        yield 'npm dependency' => ['package.json', "{\"devDependencies\": {\"@jfilter/civicrm-profile-schema\": \"^1.0.0\"}}\n", '@civico-de/civicrm-profile-schema'];
        yield 'composer dependency' => ['composer.json', "{\"require\": {\"jfilter/civikitchen-scenario-schema\": \"*\"}}\n", 'civico-de/civikitchen-scenario-schema'];
        yield 'owner in another case' => ['.github/workflows/ci.yml', "    uses: JFilter/CiviKitchen/.github/workflows/extension-ci.yml@v1\n", 'civico-de/civikitchen'];
        yield 'hash inside quotes' => ['.github/workflows/ci.yml', "      - run: echo \" # pulling\"; docker pull ghcr.io/jfilter/civikitchen:v1\n", 'civico-de/civikitchen'];
        yield 'seeded compose comment' => ['.docker/docker-compose.yml', "    # ghcr.io/jfilter/civikitchen:standalone-6.15) for this extension.\n", 'civico-de/civikitchen'];
    }

    /** @dataProvider oldReferences */
    public function testWarnsOnEachOldReference(string $file, string $contents, string $replacement): void
    {
        $reporter = $this->run_(new MovedRepositoryCheck(), $this->repo([$file => $contents], git: true));
        $this->assertWarns($reporter, 'moved to ' . $replacement . ' ');
    }

    public function testReportsEveryLine(): void
    {
        $context = $this->repo([
            '.docker/docker-compose.ci.yml' => "services:\n  app:\n    image: ghcr.io/jfilter/civikitchen:v1\n  cms:\n    image: ghcr.io/jfilter/civikitchen:drupal10-v1\n",
        ], git: true);
        self::assertSame([
            '.docker/docker-compose.ci.yml:3: jfilter/civikitchen moved to civico-de/civikitchen and no longer receives updates',
            '.docker/docker-compose.ci.yml:5: jfilter/civikitchen moved to civico-de/civikitchen and no longer receives updates',
        ], $this->run_(new MovedRepositoryCheck(), $context)->messages('warn'));
    }

    public function testSilentOnOtherJfilterNames(): void
    {
        $context = $this->repo([
            'info.xml' => "<maintainer><email>someone@jfilter.de</email></maintainer>\n",
            'composer.json' => "{\"require\": {\"notjfilter/civikitchen\": \"*\", \"jfilter/civikitchen-fork\": \"*\"}}\n",
        ], git: true);
        $this->assertSilent($this->run_(new MovedRepositoryCheck(), $context));
    }
}
