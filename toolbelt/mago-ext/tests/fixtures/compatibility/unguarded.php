<?php

declare(strict_types=1);

function next_name(string $id): string
{
    return str_increment($id);
}

function empty_document(): object
{
    return \Dom\HTMLDocument::createEmpty();
}
