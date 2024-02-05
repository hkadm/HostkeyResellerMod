<?php

use WHMCS\Module\Addon\Hostkeyresellermod\HostkeyResellerModLib;
use WHMCS\Module\Addon\Hostkeyresellermod\HostkeyResellerModConstants;

require_once realpath(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'init.php');
require_once __DIR__ . DIRECTORY_SEPARATOR . 'hostkeyresellermod.php';

$pdo = HostkeyResellerModLib::getPdo();
$sql = 'SELECT * FROM `tbladdonmodules` WHERE `module` = ?';
$stmt = $pdo->prepare($sql);
$stmt->execute([HostkeyResellerModConstants::HOSTKEYRESELLERMOD_MODULE_NAME]);

$vars = [
    'modulelink' => 'addonmodules.php?module=hostkeyresellermod',
    'action' => 'load'
];
$rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
foreach ($rows as $row) {
    $vars[$row['setting']] = $row['value'];
}
hostkeyresellermod_output($vars);
