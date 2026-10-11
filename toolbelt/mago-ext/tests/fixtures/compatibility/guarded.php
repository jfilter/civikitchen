<?php

declare(strict_types=1);

function next_id(string $id): string
{
    return function_exists('str_increment') ? str_increment($id) : $id;
}

function document(): ?object
{
    return class_exists(\Dom\HTMLDocument::class) ? \Dom\HTMLDocument::createEmpty() : null;
}

function stamp(): ?\DateTimeImmutable
{
    return class_exists(\DateTimeImmutable::class) ? \DateTimeImmutable::createFromTimestamp(0) : null;
}
