<?php

declare(strict_types=1);

namespace Acme;

use Random\IntervalBoundary;

function boundary(): IntervalBoundary
{
    return IntervalBoundary::ClosedOpen;
}

function boundaries(): array
{
    return \Random\IntervalBoundary::cases();
}

function socket(): int
{
    return \CURLOPT_PREREQFUNCTION + CURLOPT_TCP_KEEPCNT + \Pdo\Sqlite::OPEN_READONLY;
}

function empty_document(): object
{
    return \Dom\HTMLDocument::createEmpty();
}
