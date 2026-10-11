<?php

declare(strict_types=1);

// A stub replaces core's file at the same path for mago: each non-private member core
// declares must be in it with the same native signature. Members core lacks are listed.

if (($argv[1] ?? '') === '--dump') {
    exit(dump($argv[2]));
}
if ($argc !== 2 || in_array($argv[1], ['-h', '--help'], true)) {
    fwrite(STDERR, "usage: php stub-parity.php <civicrm-core-dir>\n");
    exit(2);
}
$core = rtrim($argv[1], '/');
$stubs = __DIR__ . '/stubs';
$status = 0;
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($stubs, FilesystemIterator::SKIP_DOTS));
foreach ($files as $file) {
    $path = substr($file->getPathname(), strlen($stubs) + 1);
    if (!is_file("{$core}/{$path}")) {
        echo "stub-parity: {$core} has no {$path}\n";
        $status = 1;
        continue;
    }
    $coreLines = members("{$core}/{$path}");
    $stubLines = members($file->getPathname());
    $missing = array_diff($coreLines, $stubLines);
    $extra = array_diff($stubLines, $coreLines);
    echo "stub-parity: {$path} " . ($missing !== [] ? "differs from {$core}" : ($extra === [] ? 'matches core' : 'covers core')) . "\n";
    foreach ($missing as $line) {
        echo "  core only: {$line}\n";
    }
    foreach ($extra as $line) {
        echo "  stub only: {$line}\n";
    }
    $status = $missing === [] ? $status : 1;
}
exit($status);

/** @return list<string> One line per class and per non-private member, from a separate process. */
function members(string $file): array
{
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --dump ' . escapeshellarg($file), $lines, $status);
    if ($status !== 0) {
        fwrite(STDERR, "stub-parity: cannot load {$file}:\n" . implode("\n", $lines) . "\n");
        exit(2);
    }
    return $lines;
}

function dump(string $file): int
{
    // Notices from loading core's file must not land among the compared lines.
    ini_set('display_errors', 'stderr');
    $before = get_declared_classes();
    require $file;
    $classes = array_filter(
        array_map(static fn(string $name): ReflectionClass => new ReflectionClass($name), array_diff(get_declared_classes(), $before)),
        static fn(ReflectionClass $class): bool => $class->getFileName() === realpath($file),
    );
    if ($classes === []) {
        fwrite(STDERR, "{$file} declares no class\n");
        return 1;
    }
    foreach ($classes as $class) {
        $name = $class->getName();
        $interfaces = $class->getInterfaceNames();
        sort($interfaces);
        echo ltrim(implode(' ', Reflection::getModifierNames($class->getModifiers())) . " class {$name}")
            . ($class->getParentClass() ? ' extends ' . $class->getParentClass()->getName() : '')
            . ($interfaces === [] ? '' : ' implements ' . implode(', ', $interfaces))
            . ($class->getTraitNames() === [] ? '' : ' uses ' . implode(', ', $class->getTraitNames())) . "\n";
        foreach ($class->getReflectionConstants() as $constant) {
            if ($constant->getDeclaringClass()->getName() === $name && !$constant->isPrivate()) {
                echo "{$name}: " . implode(' ', Reflection::getModifierNames($constant->getModifiers())) . " const {$constant->getName()} = " . export($constant->getValue()) . "\n";
            }
        }
        foreach ($class->getProperties() as $property) {
            if ($property->getDeclaringClass()->getName() === $name && !$property->isPrivate()) {
                echo "{$name}: " . implode(' ', Reflection::getModifierNames($property->getModifiers())) . ' ' . ltrim($property->getType() . ' ')
                    . "\${$property->getName()}" . ($property->hasDefaultValue() ? ' = ' . export($property->getDefaultValue()) : '') . "\n";
            }
        }
        foreach ($class->getMethods() as $method) {
            if ($method->getDeclaringClass()->getName() === $name && !$method->isPrivate()) {
                echo "{$name}: " . implode(' ', Reflection::getModifierNames($method->getModifiers())) . ' function ' . ($method->returnsReference() ? '&' : '')
                    . $method->getName() . '(' . implode(', ', array_map(parameter(...), $method->getParameters())) . ')'
                    . ($method->hasReturnType() ? ': ' . $method->getReturnType() : '') . "\n";
            }
        }
    }
    return 0;
}

function parameter(ReflectionParameter $parameter): string
{
    $default = '';
    if ($parameter->isDefaultValueAvailable()) {
        $default = ' = ' . ($parameter->isDefaultValueConstant() ? $parameter->getDefaultValueConstantName() : export($parameter->getDefaultValue()));
    }
    return ltrim($parameter->getType() . ' ') . ($parameter->isPassedByReference() ? '&' : '') . ($parameter->isVariadic() ? '...' : '')
        . "\${$parameter->getName()}" . $default;
}

function export(mixed $value): string
{
    return (string) preg_replace('/\s+/', ' ', var_export($value, true));
}
