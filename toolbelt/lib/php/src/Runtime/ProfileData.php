<?php

declare(strict_types=1);

namespace CiviKitchen\Toolbelt\Runtime;

use RuntimeException;

final class ProfileData
{
    /** @return array<string, mixed> */
    public function load(string $file): array
    {
        $data = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new RuntimeException("profile is not a JSON object: {$file}");
        }
        return $data;
    }

    public function hasApiUsers(string $file, bool $requireNonEmpty): bool
    {
        $profile = $this->load($file);
        return $requireNonEmpty ? count($profile['apiUsers'] ?? []) > 0 : array_key_exists('apiUsers', $profile);
    }

    public function authxPolicy(string $file, bool $sort): string
    {
        $profile = $this->load($file);
        if (count($profile['apiUsers'] ?? []) === 0) {
            return '';
        }
        $policy = $profile['authx']['header_cred'] ?? ['jwt', 'api_key'];
        $policy = array_values(array_map('strval', is_array($policy) ? $policy : []));
        if ($sort) {
            sort($policy);
        }
        return implode(',', $policy);
    }

    public function cms(string $file): string
    {
        $profile = $this->load($file);
        return is_string($profile['cms'] ?? null) ? $profile['cms'] : '';
    }

    /** @return list<string> */
    public function skipped(string $file, string $uf): array
    {
        $messages = [];
        foreach ($this->load($file)['dependencies'] ?? [] as $dependency) {
            if (!is_array($dependency)) {
                continue;
            }
            if (!in_array($uf, $dependency['skipUf'] ?? [], true)) {
                continue;
            }
            $reason = $dependency['skipUfReason'] ?? 'declared incompatible in profile.json';
            $messages[] = "  SKIP {$dependency['name']} on {$uf}: {$reason}";
        }
        return $messages;
    }

    /** @return list<array<string, mixed>> */
    public function dependencies(string $file, string $uf): array
    {
        $profile = $this->load($file);
        return array_values(array_filter($profile['dependencies'] ?? [], static fn(mixed $dependency): bool =>
            is_array($dependency) && !in_array($uf, $dependency['skipUf'] ?? [], true)));
    }

    /** @param list<string> $files */
    public function merge(string $output, string $policy, array $files): void
    {
        /** @var array<string, array{role:string,permissions:list<string>}> $users */
        $users = [];
        foreach ($files as $file) {
            foreach ($this->load($file)['apiUsers'] ?? [] as $user) {
                if (!is_array($user)) {
                    continue;
                }
                $username = (string) ($user['username'] ?? '');
                $role = (string) ($user['role'] ?? '');
                if (isset($users[$username]) && $users[$username]['role'] !== $role) {
                    throw new RuntimeException("same API username declares conflicting roles: {$username}");
                }
                $permissions = array_map('strval', is_array($user['permissions'] ?? null) ? $user['permissions'] : []);
                $users[$username] = [
                    'role' => $role,
                    'permissions' => array_values(array_unique([...($users[$username]['permissions'] ?? []), ...$permissions])),
                ];
            }
        }
        $rolePermissions = [];
        foreach ($users as $user) {
            $rolePermissions[$user['role']] = array_values(array_unique([
                ...($rolePermissions[$user['role']] ?? []), ...$user['permissions'],
            ]));
            sort($rolePermissions[$user['role']]);
        }
        ksort($users);
        $apiUsers = [];
        foreach ($users as $username => $user) {
            $apiUsers[] = [
                'username' => $username,
                'role' => $user['role'],
                'permissions' => $rolePermissions[$user['role']],
            ];
        }
        $merged = [
            'description' => 'Aggregated CiviKitchen API users',
            'dependencies' => [],
            'authx' => ['header_cred' => array_values(array_filter(explode(',', $policy), 'strlen'))],
            'apiUsers' => $apiUsers,
        ];
        $json = json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (file_put_contents($output, $json . "\n") === false) {
            throw new RuntimeException("cannot write merged profile: {$output}");
        }
    }
}
