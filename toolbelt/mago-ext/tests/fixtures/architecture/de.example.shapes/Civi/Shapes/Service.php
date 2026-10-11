<?php

declare(strict_types=1);

namespace Civi\Shapes;

use CRM_Foreign_Statics;
use Civi\Foreign\Imported as Alias;

interface Service
{
    public function make(Alias $imported): CRM_Foreign_Statics;
}
