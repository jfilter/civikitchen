<?php

declare(strict_types=1);

final class RaisesTest
{
    public function testRaise(): void
    {
        \PEAR::raiseError('a test double raises on purpose');
        \CRM_Utils_Array::value('id', []);
    }
}
