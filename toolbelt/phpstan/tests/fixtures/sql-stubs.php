<?php

declare(strict_types=1);

namespace {
    /** @see \CRM_Utils_SQL_Select */
    class CRM_Utils_SQL_Select
    {
        public static function from(string $from): self
        {
            return new self();
        }

        public function execute(): object
        {
            return $this;
        }
    }
}

namespace CiviKitchen\Fixtures\Support {
    /** A builder whose execute() is not SQL. */
    class ReportBuilder
    {
        public function execute(): void
        {
        }
    }
}
