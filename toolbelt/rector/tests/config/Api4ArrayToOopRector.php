<?php

declare(strict_types = 1);

use CiviKitchen\Rector\Rules\Api4ArrayToOopRector;
use Rector\Config\RectorConfig;

return RectorConfig::configure()->withRules([Api4ArrayToOopRector::class]);
