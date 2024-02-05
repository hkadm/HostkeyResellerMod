<?php

use WHMCS\Module\Addon\Hostkeyresellermod\HostkeyResellerModConstants;
use WHMCS\Module\Addon\Hostkeyresellermod\HostkeyResellerModCounter;
use WHMCS\Module\Addon\Hostkeyresellermod\HostkeyResellerModException;
use WHMCS\Module\Addon\Hostkeyresellermod\HostkeyResellerModLib;
use WHMCS\Module\Addon\Hostkeyresellermod\HostkeyResellerModCleaner;

function hostkeyresellermod_Config()
{
    return [
        'name' => 'HOSTKEY VPS/Dedicated',
        'description' => 'Easy to start reselling program for VPS/Dedicated servers',
        'version' => '1.0',
        'author' => 'hostkey.com',
        'fields' => [
            'apiurl' => [
                'FriendlyName' => 'API Url',
                'Type' => 'text',
                'Size' => '255',
                'Default' => 'https://invapi.hostkey.com/',
            ],
            'apikey' => [
                'FriendlyName' => 'API key',
                'Type' => 'text',
                'Size' => '33',
            ],
            'presetnameprefix' => [
                'FriendlyName' => 'Preset name prefix',
                'Type' => 'text',
                'Size' => '10',
                'Default' => 'HKP-',
            ],
            'defaultproductgroup' => [
                'FriendlyName' => 'Default product group',
                'Type' => 'text',
                'Size' => '32',
                'Default' => 'Hostkey servers',
            ],
        ]
    ];
}

function hostkeyresellermod_ConfigOptions()
{
    return HostkeyResellerModLib::configOptions();
}

function hostkeyresellermod_output($vars)
{
    $action = $_REQUEST['action'] ?? $vars['action'] ?? '';
    $isConsole = HostkeyResellerModLib::isConsole();
    $out = '';
    switch ($action) {
        case 'load':
            if ($isConsole) {
                echo "Loading presets info...\n";
                $groupToImport = array_keys(HostkeyResellerModConstants::getProductGroups());
            } else {
                $groupToImport = [];
                $productGroups = array_keys(HostkeyResellerModConstants::getProductGroups());
                foreach (array_keys($_GET) as $key) {
                    if (in_array($key, $productGroups)) {
                        $groupToImport[] = $key;
                    }
                }
            }
            if (count($groupToImport)) {
                $domain = $vars['apiurl'] ?? false;
                $start = time();
                $workTime = 0;
                ob_start();
                if ($domain) {
                    try {
                        set_time_limit(1000);
                        ini_set('max_execution_time', 1000);
                        $json = HostkeyResellerModLib::getPresetJson($domain . 'presets.php?action=info');
                        HostkeyResellerModLib::loadPresetsIntoDb($json->presets, $groupToImport);
                        $workTime = time() - $start;
                    } catch (HostkeyResellerModException $e) {
                        $error = 'HostkeyResellerModException: ' . $e->getMessage();
                        $out .= $isConsole ? ($error . "\n") : ('<p><strong>' . $error . '</strong></p>');
                    }
                } else {
                    $error = 'API Url is empty';
                    $out .= $isConsole ? ($error . "\n") : ('<p><strong>' . $error . '</strong></p>');
                }
                $msg = ob_get_clean() . "\n" . 'Script running time: ' . $workTime . ' sec' . "\n";
                $out .= $isConsole ? ($msg . "\n") : ('<p><strong>' . $msg . '</strong></p>');
            }
            $groups = HostkeyResellerModCounter::getGroups();
            $products = HostkeyResellerModCounter::getProducts();
            if ($isConsole) {
                $out .= count($products) . ' products in ' . count($groups) . " groups are now available to purchase.\n";
                if (count($groups)) {
                    $out .= "Groups names:\n\t" . implode("\n\t", $groups) . "\n";
                }
            } else {
                $out .= '<p>' . count($products) . ' products in ' . count($groups) . ' groups are now available to purchase.</p>';
                $out .= '<p>To set up new product go to <a href="/admin/configproducts.php">System Settings - Product Services</a></p>';
                if (count($groups)) {
                    $out .= '<p>Groups names: ' . implode(', ', $groups) . '</p>';
                }
                $out .= '<p>Get pick to the <a href="https://hostkey.ru/documentation/resselers/install/" target="_blank">HOSTKEY reselling 101 guide</a> to learn more.</p>';
                $out = '<h2>Select products to resell</h2>' . $out;
            }
            logModuleCall(HostkeyResellerModConstants::HOSTKEYRESELLERMOD_MODULE_NAME, 'Load', json_encode($vars), $out);
            break;

        case 'clear':
            $ret = HostkeyResellerModCleaner::create()->clear();
            $out .= HostkeyResellerModCleaner::out($ret);
            break;

        default:
            $out .= HostkeyResellerModLib::out();
    }
    echo $out;
}
