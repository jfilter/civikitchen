<?php

declare(strict_types = 1);

use CiviKitchen\Rector\Rules\Api3ToApi4AssistRector;
use Rector\Config\RectorConfig;

return RectorConfig::configure()->withRules([Api3ToApi4AssistRector::class]);
