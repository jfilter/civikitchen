<?php

declare(strict_types=1);

namespace Acme\Widget;

use function request_parse_body as parseBody;

final class Greeter
{
    public const HELLO = 'hello';

    public function greet(string $name): string
    {
        $constant = self::{$name};
        $fixed = self::HELLO;
        $fetched = constant(self::class . '::' . $name);
        $body = request_parse_body();
        $handles = curl_multi_get_handles(curl_multi_init());
        $access = posix_eaccess('/tmp');
        $polyfilled = mb_trim($name);
        $aliased = parseBody();

        return implode(' ', [$constant, $fixed, $fetched, $body, $handles, $access, $polyfilled, $aliased]);
    }

    public function query(\mysqli $link): mixed
    {
        return mysqli_execute_query($link, 'SELECT 1');
    }
}
