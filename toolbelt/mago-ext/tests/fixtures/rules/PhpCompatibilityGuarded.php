<?php

declare(strict_types=1);

function handles(\CurlMultiHandle $multi): array
{
    return function_exists('\\curl_multi_get_handles') ? curl_multi_get_handles($multi) : [];
}
