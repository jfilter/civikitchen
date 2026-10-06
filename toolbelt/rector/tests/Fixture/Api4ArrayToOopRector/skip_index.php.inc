<?php

civicrm_api4('Contact', 'get', ['limit' => 5], 'id');
civicrm_api4('Contact', 'get', ['limit' => 5], 0);
civicrm_api4('Contact', 'get', [], ['id' => 'display_name']);
civicrm_api4('Contact', 'get', [], index: 0);
