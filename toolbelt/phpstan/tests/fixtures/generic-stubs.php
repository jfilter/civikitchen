<?php

declare(strict_types=1);

namespace Civi\Api4\Generic;

/** @see \Civi\Api4\Generic\AbstractAction */
abstract class AbstractAction
{
    protected bool $checkPermissions = true;

    public function __construct($entityName = '', $actionName = '')
    {
    }
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
    public function addWhere(string $fieldName, string $op, mixed $value = null): self
    {
        return $this;
    }

    /** @return $this */
    public function addValue(string $fieldName, mixed $value): self
    {
        return $this;
    }

    /** @return $this */
    public function addOrderBy(string $fieldName, string $direction = 'ASC'): self
    {
        return $this;
    }

    /** @return $this */
    public function addJoin(string $entity, string $side = 'LEFT', mixed ...$conditions): self
    {
        return $this;
    }

    /** @return array<int, array<string, mixed>> */
    public function execute(): array
    {
        return [];
    }
}
