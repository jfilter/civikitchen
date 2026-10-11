<?php

declare(strict_types=1);

namespace Acme\Widget;

// Older code asked function_exists('str_increment') here.
function next_id(string $id, string $library): string
{
    return version_compare($library, '9.0', '>=') ? str_increment($id) : $id;
}

function document(): ?object
{
    return class_exists(Other\HTMLDocument::class) ? \Dom\HTMLDocument::createEmpty() : null;
}

function stamp(): ?\DateTimeImmutable
{
    return method_exists(\SplFileInfo::class, 'createFromTimestamp') ? \DateTimeImmutable::createFromTimestamp(0) : null;
}
