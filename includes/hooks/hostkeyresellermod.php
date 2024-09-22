<?php

use WHMCS\Module\Addon\Hostkeyresellermod\HostkeyResellerModLib;
use WHMCS\Module\Addon\Hostkeyresellermod\HostkeyResellerModConstants;

function hostkeyresellermod_lib_InvoicePaid($vars)
{
    $customFieldValueQuery = 'SELECT cfv2.value AS preset '
        . 'FROM tblcustomfieldsvalues AS cfv1 '
        . 'JOIN tblcustomfields AS cf1 ON cf1.id = cfv1.fieldid '
        . 'JOIN tblcustomfieldsvalues AS cfv2 ON cfv2.relid = cfv1.relid '
        . 'JOIN tblcustomfields AS cf2 ON cf2.id = cfv2.fieldid '
        . 'WHERE  cf1.`type` =  ? AND '
        . 'cf1.fieldname =  ? AND  '
        . 'cf2.`type` =  ? '
        . 'AND cf2.fieldname =  ? '
        . 'AND  cfv1.value =  ?';
    $params = [
        'product',
        HostkeyResellerModConstants::CUSTOM_FIELD_INVOICE_ID,
        'product',
        HostkeyResellerModConstants::CUSTOM_FIELD_PRESET_ID,
        $vars['invoiceid'],
    ];
    $pdo = HostkeyResellerModLib::getPdo();
    $customFieldValueStmt = $pdo->prepare($customFieldValueQuery);
    $customFieldValueStmt->execute($params);
    $presetId = $customFieldValueStmt->fetchColumn();
    if ($presetId) {
        $customerInvoice = HostkeyResellerModLib::getEntityById('tblinvoices', $vars['invoiceid']);
        $customerPaid = floatval($customerInvoice->total);
        $paramsToShow = [
            'action' => 'show',
            'token' => HostkeyResellerModLib::getTokenByApiKey(),
            'id' => $presetId,
        ];
        $server = HostkeyResellerModLib::makeInvapiCall($paramsToShow, 'eq');
        $paramsToGetInvoices = [
            'action' => 'get_product_invoice',
            'token' => HostkeyResellerModLib::getTokenByApiKey(),
            'product' => $server->account_id,
            'location' => $server['type_billing'],
        ];
        $res = HostkeyResellerModLib::makeInvapiCall($paramsToGetInvoices, 'whmcs');
        $invoices = $res->invoices ?? [];
        foreach ($invoices as $invoice) {
            $haveToPay = floatval($invoice->total);
            if (($invoice->status == 'Unpaid') && ($customerPaid >= $haveToPay)) {
                $customerPaid -= $haveToPay;
                HostkeyResellerModLib::payInvoice($invoice->invoiceid);
                if ($customerPaid <= 0) {
                    break;
                }
            }
        }
    } else {
        $r = HostkeyResellerModLib::makeOrder($vars['invoiceid']);
        if ($r && $r->invoice) {
            HostkeyResellerModLib::addInvoiceId($vars['invoiceid'], $r->invoice);
            HostkeyResellerModLib::payInvoice($r->invoice);
        }
        return $r->invoice;
    }
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
