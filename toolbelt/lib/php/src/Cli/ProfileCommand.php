<?php

declare(strict_types=1);

namespace CiviKitchen\Toolbelt\Cli;

use CkProfileSchemaValidator;
use Throwable;

final class ProfileCommand implements Command
{
    public function __construct(private readonly string $checkoutRoot)
    {
    }

    public function run(array $arguments): int
    {
        $usage = <<<'TXT'
ck profile — validate and discover CiviKitchen profiles

  ck profile validate <profile-dir|profile.json>
  ck profile list [profile-root ...]

list searches explicit roots, then CIVIKITCHEN_PROFILE_PATH (colon-separated),
then the bundled profile root when available. validate applies the canonical
published JSON Schema without network access.
TXT;
        $command = $arguments[0] ?? 'help';
        if (in_array($command, ['help', '-h', '--help'], true)) {
            echo $usage, "\n";
            return 0;
        }
        if ($command === 'validate') {
            return $this->validate($arguments[1] ?? '', $usage);
        }
        if ($command === 'list') {
            return $this->list(array_slice($arguments, 1));
        }
        fwrite(STDERR, "ckprofile: unknown command: {$command}\n{$usage}\n");
        return 2;
    }

    private function validate(string $target, string $usage): int
    {
        if ($target === '') {
            fwrite(STDERR, $usage . "\n");
            return 2;
        }
        if (is_dir($target)) {
            $target = rtrim($target, '/') . '/profile.json';
        }
        if (!is_file($target)) {
            fwrite(STDERR, "ckprofile: no profile JSON at {$target}\n");
            return 2;
        }
        [$validator, $schema] = $this->profileSchemaPaths();
        try {
            require_once $validator;
            $schemaDocument = json_decode((string) file_get_contents($schema), true, 512, JSON_THROW_ON_ERROR);
            $profileDocument = json_decode((string) file_get_contents($target), false, 512, JSON_THROW_ON_ERROR);
            $errors = (new CkProfileSchemaValidator($schemaDocument))->validate($profileDocument);
        } catch (Throwable $e) {
            fwrite(STDERR, "profile validation failed: {$e->getMessage()}\n");
            return 2;
        }
        if ($errors !== []) {
            foreach ($errors as $error) {
                fwrite(STDERR, "{$target}: {$error}\n");
            }
            return 1;
        }
        echo "{$target}: valid civicrm profile\n";
        return 0;
    }

    /** @param list<string> $roots */
    private function list(array $roots): int
    {
        $environmentRoots = getenv('CIVIKITCHEN_PROFILE_PATH');
        if ($environmentRoots !== false && $environmentRoots !== '') {
            array_push($roots, ...explode(PATH_SEPARATOR, $environmentRoots));
        }
        foreach ([$this->checkoutRoot . '/docker/profiles', '/usr/local/share/civikitchen/profiles'] as $bundled) {
            if (is_dir($bundled)) {
                $roots[] = $bundled;
            }
        }
        $seen = [];
        foreach ($roots as $root) {
            if (!is_dir($root)) {
                continue;
            }
            $profiles = glob(rtrim($root, '/') . '/*/profile.json') ?: [];
            sort($profiles, SORT_STRING);
            foreach ($profiles as $json) {
                $directory = dirname($json);
                $name = basename($directory);
                if (!isset($seen[$name])) {
                    $seen[$name] = true;
                    echo $name, "\t", $directory, "\n";
                }
            }
        }
        return 0;
    }

    /** @return array{string, string} */
    private function profileSchemaPaths(): array
    {
        $checkoutValidator = $this->checkoutRoot . '/packages/civicrm-profile-schema/validate.php';
        if (is_file($checkoutValidator)) {
            return [$checkoutValidator, $this->checkoutRoot . '/packages/civicrm-profile-schema/profile.schema.json'];
        }
        return ['/usr/local/share/civikitchen/profile-schema/validate.php', '/usr/local/share/civikitchen/profile-schema/profile.schema.json'];
    }
}
