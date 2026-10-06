<?php

declare(strict_types = 1);

use CiviKitchen\Rector\Rules\Api3ToApi4OopAssistRector;
use Rector\Config\RectorConfig;

return RectorConfig::configure()->withRules([Api3ToApi4OopAssistRector::class]);
