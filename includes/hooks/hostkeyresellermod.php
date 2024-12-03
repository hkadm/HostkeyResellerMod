<?php

use WHMCS\Module\Addon\Hostkeyresellermod\HostkeyResellerModException;
use WHMCS\Module\Addon\Hostkeyresellermod\HostkeyResellerModLib;

function hostkeyresellermod_lib_InvoicePaid($vars)
{
    return HostkeyResellerModLib::InvoicePaid($vars['invoiceid']);
}

/**
 * $params: {
 * "userid": 3,
 * "relid": 10,
 * "reason": ".",
 * "type": "Immediate"/"End of Billing Period"
 * }
 * @throws HostkeyResellerModException
 */
function hostkeyresellermod_lib_CancellationRequest($params)
{
    $hostingId = $params['relid'];
    $type = $params['type'];
    $reason = $params['reason'];
    HostkeyResellerModLib::sendCancelRequest($hostingId, $type, $reason);
}

/**
 * $params: {
 * "userid": 3,
 * "clientId": 3,
 * "serviceid": 10
 * }
 * @throws HostkeyResellerModException
 */
function hostkeyresellermod_lib_ServiceDelete($params)
{
    $hostingId = $params['serviceid'];
    $type = 'Immediate';
    $reason = 'Due to server deletion';
    HostkeyResellerModLib::sendCancelRequest($hostingId, $type, $reason);
}

add_hook('InvoicePaid', 1, 'hostkeyresellermod_lib_InvoicePaid');
add_hook('CancellationRequest', 1, 'hostkeyresellermod_lib_CancellationRequest');
add_hook('ServiceDelete', 1, 'hostkeyresellermod_lib_ServiceDelete');
