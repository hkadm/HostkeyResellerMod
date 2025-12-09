<?php

use WHMCS\Module\Addon\Hostkeyresellermod\HostkeyResellerModConstants;
use WHMCS\Module\Addon\Hostkeyresellermod\HostkeyResellerModLib;
use WHMCS\Database\Capsule as Capsule;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

function hostkeyresellermod_MetaData(): array
{
    return [
        'DisplayName' => 'HOSTKEY VPS/Dedicated',
        'APIVersion' => '1.1', // Use API Version 1.1
        'RequiresServer' => true, // Set true if module requires a server to work
        'DefaultNonSSLPort' => '80', // Default Non-SSL Connection Port
        'DefaultSSLPort' => '1112', // Default SSL Connection Port
        'ServiceSingleSignOnLabel' => 'Login to Panel as User',
        'AdminSingleSignOnLabel' => 'Login to Panel as Admin',
    ];
}

function hostkeyresellermod_CreateAccount(array $params): string
{
    try {
        /** @var PDO $pdo */
        $pdo = Capsule::connection()->getPdo();
        $hostingId = $params['model']['id'];
        $customFields = (array)$params['customfields'];
        $apiKey = $customFields[HostkeyResellerModConstants::CUSTOM_FIELD_API_KEY_NAME] ?? '';

        if (empty($apiKey)) {
            HostkeyResellerModLib::completeLinkToPreset($hostingId);
        }

        $hostingQuery = 'UPDATE `tblhosting` SET `domainstatus` = ? WHERE `id` = ?';
        $hostingStmt = $pdo->prepare($hostingQuery);
        $hostingStmt->execute(['Active', $hostingId]);
        
        return 'success';
    } catch (Exception $e) {
        return $e->getMessage();
    }
}

function hostkeyresellermod_ClientArea(array $params)
{
    try {
        $customFields = (array)$params['customfields'];
        $apiKey = false;
        $invoiceId = false;
        foreach ($customFields as $key => $value) {
            if ($key == HostkeyResellerModConstants::CUSTOM_FIELD_API_KEY_NAME) {
                $apiKey = $value;
            } elseif ($key == HostkeyResellerModConstants::CUSTOM_FIELD_INVOICE_ID) {
                $invoiceId = $value;
            }
        }
        if (!$invoiceId) {
            logModuleCall(
                HostkeyResellerModConstants::HOSTKEYRESELLERMOD_MODULE_NAME,
                'clientarea',
                json_encode($params),
                'invoiceId is empty'
            );
            return [
                'templatefile' => 'error.tpl',
                'vars' => [
                    'error' => 'Server provisioning failed. Please contact support.',
                ],
            ];
        }
        $orderId = $params['model']['orderid'];
        $hosting = HostkeyResellerModLib::getEntityByCondition('tblhosting', ['orderid' => $orderId]);
        $location = HostkeyResellerModLib::getLocation($hosting['id']);
        $hostServerId = HostkeyResellerModLib::getCustomFieldValue(
            $hosting['id'],
            HostkeyResellerModConstants::CUSTOM_FIELD_PRESET_ID
        );
        if (!$hostServerId) {
            $hostServerId = HostkeyResellerModLib::getServerIdByInvoiceId($invoiceId, $location, $hosting['id']);
            if ($hostServerId) {
                HostkeyResellerModLib::addCustomFieldValue(
                    $hosting['packageid'],
                    $hosting['id'],
                    HostkeyResellerModConstants::CUSTOM_FIELD_PRESET_ID,
                    $hostServerId
                );
            }
        }
        $serverStatus = $params['model']['domainstatus'];
        if (!$invoiceId && $params['model']['orderid']) {
            $order = HostkeyResellerModLib::getEntityById('tblorders', $params['model']['orderid']);
            $invoiceIdBilling = $order['invoicenum'];
            if ($invoiceIdBilling) {
                $invoiceId = HostkeyResellerModLib::InvoicePaid($invoiceIdBilling);
            }
        }
        if (!$apiKey && $invoiceId) {
            $packageid = $params['model']['packageid'];
            if ($hostServerId > 0) {
                $apiKeysList = HostkeyResellerModLib::getApiKeyList($hostServerId);
                if (count($apiKeysList) == 0) {
                    $apiKey = HostkeyResellerModLib::addApiKey(
                        HostkeyResellerModConstants::CUSTOM_FIELD_API_KEY_NAME . ' [' . $hostServerId . '.' . $invoiceId . ']',
                        $hostServerId
                    );
                    if (!$apiKey) {
                        logModuleCall(
                            HostkeyResellerModConstants::HOSTKEYRESELLERMOD_MODULE_NAME,
                            'clientarea',
                            json_encode($params),
                            'No apikey'
                        );
                        return [
                            'templatefile' => 'error.tpl',
                            'vars' => [
                                'error' => 'Unable to generate API key for your server. Please contact support.',
                            ],
                        ];
                    }
                    HostkeyResellerModLib::addCustomFieldValue(
                        $packageid,
                        $hosting['id'],
                        HostkeyResellerModConstants::CUSTOM_FIELD_API_KEY_NAME,
                        $apiKey
                    );
                    HostkeyResellerModLib::setHostingStatus(
                        $hosting['id'],
                        HostkeyResellerModConstants::PL_HOSTING_STATUS_ACTIVE
                    );
                }
            }
        }
        $hostServerData = HostkeyResellerModLib::getServerData($hostServerId);
//        if (!$hostServerData && ($params['status'] == HostkeyResellerModConstants::PL_HOSTING_STATUS_ACTIVE)) {
//            HostkeyResellerModLib::setHostingStatus($hosting['id'], HostkeyResellerModConstants::PL_HOSTING_STATUS_SUSPENDED);
//        } else {
        if ($hostServerData) {
            $hostServerStatus = '';
            switch ($hostServerData['Condition_Component']) {
                case 'power_off':
                case 'presale':
                    $hostServerStatus = HostkeyResellerModConstants::PL_HOSTING_STATUS_SUSPENDED;
                    break;
                case 'rent':
                    $hostServerStatus = HostkeyResellerModConstants::PL_HOSTING_STATUS_ACTIVE;
                    break;
                case 'TT':
                    $hostServerStatus = HostkeyResellerModConstants::PL_HOSTING_STATUS_PENDING;
                    break;
            }
            if ($hostServerStatus && ($hostServerStatus !== $serverStatus)) {
                HostkeyResellerModLib::setHostingStatus($hosting['id'], $hostServerStatus);
            }
        }
        logModuleCall(
            HostkeyResellerModConstants::HOSTKEYRESELLERMOD_MODULE_NAME,
            'clientarea',
            json_encode($params),
            'Apikey received'
        );
        
        $settings = HostkeyResellerModLib::getModuleSettings();
        $apiUrl = $settings['apiurl'] ?? 'https://invapi.hostkey.com/';
        $parsedUrl = parse_url($apiUrl);
        $hostParts = explode('.', $parsedUrl['host']);
        $topLevelDomain = end($hostParts);
        $panelHost = 'https://panel.hostkey.' . $topLevelDomain . '/';
        
        return [
            'templatefile' => 'clientarea.tpl',
            'vars' => [
                'apihost' => $panelHost,
                'apikey' => $apiKey,
            ],
        ];
    } catch (Exception $exc) {
        return [
            'templatefile' => 'error.tpl',
            'vars' => [
                'error' => $exc->getMessage(),
            ],
        ];
    }
}
