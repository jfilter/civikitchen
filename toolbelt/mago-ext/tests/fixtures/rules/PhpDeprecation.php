<?php

declare(strict_types=1);

namespace Acme\Widget;

use function get_class as className;

final class Greeter
{
    public function greet(string $name, array $names): string
    {
        $legacy = "hello ${name}";
        $legacyIndex = "hello ${names['first']}";
        $modern = "hello {$name} and $name";
        $variable = ${'name'};
        $class = get_class();
        $parent = get_parent_class();
        $explicit = get_class($this);
        $aliased = className();

        return implode(' ', [$legacy, $legacyIndex, $modern, $variable, $class, $parent, $explicit, $aliased]);
    }
}

function heredoc(string $name): string
{
    return <<<TXT
        hello ${name}
        TXT;
}
