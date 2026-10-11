<?php

declare(strict_types=1);

function versioned(): ?object
{
    return PHP_VERSION_ID >= 80400 ? \Dom\HTMLDocument::createEmpty() : null;
}

function compared(string $id): string
{
    return version_compare(PHP_VERSION, '8.3', '>=') ? str_increment($id) : $id;
}
