<?php

declare(strict_types=1);

namespace CiviKitchen\Toolbelt\Cli;

interface Command
{
    /** @param list<string> $arguments */
    public function run(array $arguments): int;
}
