<?php

use WHMCS\Module\Addon\Hostkeyresellermod\HostkeyResellerModLib;

function hostkeyresellermod_lib_InvoicePaid($vars)
{
    $r = HostkeyResellerModLib::makeOrder($vars['invoiceid']);
    if ($r && $r->invoice) {
        HostkeyResellerModLib::addInvoiceId($vars['invoiceid'], $r->invoice);
    }
    return $r->invoice;
}

/**
 * $params: {
  "userid": 3,
  "relid": 10,
  "reason": ".",
  "type": "Immediate"/"End of Billing Period"
  }
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
  "userid": 3,
  "clientId": 3,
  "serviceid": 10
  }
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
