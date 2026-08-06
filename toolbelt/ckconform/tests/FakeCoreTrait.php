<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests;

/**
 * A throwaway fake CiviCRM core on disk for checks that read core's Civi/Api4
 * tree. The using test writes its own entity files under makeCore()'s
 * Civi/Api4; creation and removal live here so the rm -rf can never point
 * anywhere but the directory this trait itself created.
 */
trait FakeCoreTrait
{
    private ?string $core = null;

    protected function tearDown(): void
    {
        if ($this->core !== null
            && str_starts_with($this->core, sys_get_temp_dir() . '/ckconform-core-')
            && is_dir($this->core)
        ) {
            exec('rm -rf ' . escapeshellarg($this->core));
        }
        $this->core = null;
        parent::tearDown();
    }

    protected function coreDir(): ?string
    {
        return $this->core;
    }

    /** Creates the core skeleton (with an empty Civi/Api4) and returns its root. */
    private function makeCore(): string
    {
        $this->core = sys_get_temp_dir() . '/ckconform-core-' . bin2hex(random_bytes(6));
        mkdir($this->core . '/Civi/Api4', 0777, true);

        return $this->core;
    }
}
