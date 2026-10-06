<?php

declare(strict_types = 1);

use CiviKitchen\Rector\Rules\CrmCoreErrorFatalToExceptionRector;
use Rector\Config\RectorConfig;

return RectorConfig::configure()->withRules([CrmCoreErrorFatalToExceptionRector::class]);
