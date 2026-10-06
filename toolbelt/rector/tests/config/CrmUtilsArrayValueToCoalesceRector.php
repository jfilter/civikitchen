<?php

declare(strict_types = 1);

use CiviKitchen\Rector\Rules\CrmUtilsArrayValueToCoalesceRector;
use Rector\Config\RectorConfig;

return RectorConfig::configure()->withRules([CrmUtilsArrayValueToCoalesceRector::class]);
