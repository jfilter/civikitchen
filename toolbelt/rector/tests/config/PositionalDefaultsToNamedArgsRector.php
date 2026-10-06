<?php

declare(strict_types = 1);

use CiviKitchen\Rector\Rules\PositionalDefaultsToNamedArgsRector;
use Rector\Config\RectorConfig;

return RectorConfig::configure()->withRules([PositionalDefaultsToNamedArgsRector::class]);
