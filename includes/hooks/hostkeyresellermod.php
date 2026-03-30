<?php

use WHMCS\Module\Addon\Hostkeyresellermod\HostkeyResellerModException;
use WHMCS\Module\Addon\Hostkeyresellermod\HostkeyResellerModLib;
use WHMCS\Module\Addon\Hostkeyresellermod\HostkeyResellerModConstants;
use WHMCS\Database\Capsule;

function hostkeyresellermod_lib_InvoicePaid($vars)
{
    if (!hostkeyresellermod_hasHostkeyProducts($vars['invoiceid'])) {
        return;
    }
    
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
    if (!hostkeyresellermod_isHostkeyService($hostingId)) {
        return;
    }
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
    if (!hostkeyresellermod_isHostkeyService($hostingId)) {
        return;
    }
    $type = 'Immediate';
    $reason = 'Due to server deletion';
    HostkeyResellerModLib::sendCancelRequest($hostingId, $type, $reason);
}

/**
 * Checks if the invoice contains items with the Hostkey module
 *
 * @param int $invoiceId The invoice ID to check
 * @return bool True if the invoice contains Hostkey products, false otherwise
 */
function hostkeyresellermod_hasHostkeyProducts($invoiceId)
{
    if (!is_numeric($invoiceId) || $invoiceId <= 0) {
        return false;
    }
    try {
        $pdo = Capsule::connection()->getPdo();
        $query = "SELECT COUNT(*) FROM tblorders o 
                  JOIN tblhosting h ON h.orderid = o.id 
                  JOIN tblproducts p ON p.id = h.packageid 
                  WHERE o.invoiceid = ? AND p.servertype = ?";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$invoiceId, HostkeyResellerModConstants::HOSTKEYRESELLERMOD_MODULE_NAME]);

        return $stmt->fetchColumn() > 0;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Checks if the service uses the Hostkey module
 * 
 * @param int $serviceId The hosting service ID
 * @return bool True if the service uses Hostkey module, false otherwise
 */
function hostkeyresellermod_isHostkeyService($serviceId)
{
    if (!is_numeric($serviceId) || $serviceId <= 0) {
        return false;
    }
    try {
        $pdo = Capsule::connection()->getPdo();

        $query = "SELECT p.servertype FROM tblhosting h 
                  JOIN tblproducts p ON p.id = h.packageid 
                  WHERE h.id = ?";

        $stmt = $pdo->prepare($query);
        $stmt->execute([$serviceId]);

        $serverType = $stmt->fetchColumn();
        return $serverType === HostkeyResellerModConstants::HOSTKEYRESELLERMOD_MODULE_NAME;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Validates the root password for Hostkey products during checkout.
 *
 * @param array $vars
 * @return array
 */
function hostkeyresellermod_lib_ShoppingCartValidateCheckout($vars)
{
    $errors = [];
    $products = $_SESSION['cart']['products'] ?? [];

    foreach ($products as $product) {
        $pid = $product['pid'] ?? 0;
        if (!$pid) {
            continue;
        }

        try {
            $pdo = Capsule::connection()->getPdo();
            $stmt = $pdo->prepare('SELECT servertype FROM tblproducts WHERE id = ?');
            $stmt->execute([$pid]);
            $serverType = $stmt->fetchColumn();
        } catch (Exception $e) {
            continue;
        }

        if ($serverType !== HostkeyResellerModConstants::HOSTKEYRESELLERMOD_MODULE_NAME) {
            continue;
        }

        $password = $product['password'] ?? '';
        if ($password === '') {
            continue;
        }

        $validationError = HostkeyResellerModLib::validatePassword($password);
        if ($validationError !== null) {
            $errors[] = $validationError;
            break;
        }
    }

    return $errors;
}

add_hook('InvoicePaid', 1, 'hostkeyresellermod_lib_InvoicePaid');
add_hook('CancellationRequest', 1, 'hostkeyresellermod_lib_CancellationRequest');
add_hook('ServiceDelete', 1, 'hostkeyresellermod_lib_ServiceDelete');
add_hook('ShoppingCartValidateCheckout', 1, 'hostkeyresellermod_lib_ShoppingCartValidateCheckout');
