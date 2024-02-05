<?php

use WHMCS\Module\Addon\Hostkeyresellermod\HostkeyResellerModCleaner;

require_once realpath(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'init.php');
$cleaner = new HostkeyResellerModCleaner();
$r = $cleaner->clear();
