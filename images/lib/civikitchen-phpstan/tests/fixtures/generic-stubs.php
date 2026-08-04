<?php

declare(strict_types=1);

namespace Civi\Api4\Generic;

/** @see \Civi\Api4\Generic\AbstractAction */
abstract class AbstractAction
{
    protected bool $checkPermissions = true;
}

abstract class AbstractCreateAction extends AbstractAction {}

/** A builder stub: every method returns the builder again. */
class DummyAction extends AbstractAction
{
    /** @return $this */
    public function addSelect(string ...$fields): self
    {
        return $this;
    }

    /** @return $this */
    public function addWhere(string $field, string $op, mixed $value = null): self
    {
        return $this;
    }

    /** @return $this */
    public function addValue(string $field, mixed $value): self
    {
        return $this;
    }

    /** @return $this */
    public function addOrderBy(string $field, string $direction = 'ASC'): self
    {
        return $this;
    }

    /** @return array<int, array<string, mixed>> */
    public function execute(): array
    {
        return [];
    }
}
