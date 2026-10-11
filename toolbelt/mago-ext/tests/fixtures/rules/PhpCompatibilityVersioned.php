<?php

declare(strict_types=1);

function body(): array
{
    return PHP_VERSION_ID >= 80400 ? request_parse_body() : [$_POST, $_FILES];
}

function handles(\CurlMultiHandle $multi): array
{
    return version_compare(PHP_VERSION, '8.4', '>=') ? curl_multi_get_handles($multi) : [];
}
