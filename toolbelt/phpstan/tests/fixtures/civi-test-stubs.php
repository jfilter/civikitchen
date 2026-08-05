<?php

declare(strict_types=1);

namespace Civi\Test;

/** @see \Civi\Test\TransactionalInterface */
interface TransactionalInterface {}

namespace CiviKitchen\Fixtures\Support;

/** Stand-in for PHPUnit's TestCase, which the fixtures do not need. */
abstract class TestCase
{
    protected function setUp(): void {}
}

class ExtensionManager
{
    public function install(string $key): void {}

    public function disable(string $key): void {}
}
