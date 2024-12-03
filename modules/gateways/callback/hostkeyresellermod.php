<?php

use WHMCS\Module\Addon\Hostkeyresellermod\HostkeyResellerModException;
use WHMCS\Module\Addon\Hostkeyresellermod\HostkeyResellerModLib;

require_once __DIR__ . DIRECTORY_SEPARATOR . '../../../init.php';

header('Content-Type: application/json');

$hosting = $_GET['hosting'] ?? 0;

if ($hosting) {
    try {
        $ret = HostkeyResellerModLib::completeLinkToPreset($hosting);
    } catch (HostkeyResellerModException $e) {
        $ret = [
            'result' => 'error',
            'error' => $e->getMessage(),
        ];
    }
} else {
    $ret = [
        'result' => 'error',
        'error' => 'Hosting is empty',
    ];
}
echo json_encode($ret);
