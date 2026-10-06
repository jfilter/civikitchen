<?php

civicrm_api3('Contact', 'get', ['id' => 3], 'extra');
civicrm_api3('Contact', ...$rest);
