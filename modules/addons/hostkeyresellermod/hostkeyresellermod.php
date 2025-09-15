<?php

use Illuminate\Database\Schema\Blueprint;
use WHMCS\Database\Capsule as Capsule;
use WHMCS\Module\Addon\Hostkeyresellermod\HostkeyResellerModConstants;
use WHMCS\Module\Addon\Hostkeyresellermod\HostkeyResellerModCounter;
use WHMCS\Module\Addon\Hostkeyresellermod\HostkeyResellerModException;
use WHMCS\Module\Addon\Hostkeyresellermod\HostkeyResellerModLib;
use WHMCS\Module\Addon\Hostkeyresellermod\HostkeyResellerModCleaner;

function hostkeyresellermod_Config(): array
{
    return [
        'name' => 'HOSTKEY VPS/Dedicated',
        'description' => 'Easy to start reselling program for VPS/Dedicated servers',
        'version' => '1.0.13',
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
            'logging' => [
                'FriendlyName' => 'Logging of requests to Hostkey API',
                'Type' => 'yesno',
                'Default' => false,
            ],
            'importsource' => [
                'FriendlyName' => 'Import Settings Source',
                'Type' => 'dropdown',
                'Options' => [
                    'auto' => 'Auto',
                    'admin' => 'Admin Panel Only',
                    'ini' => 'import.ini Only',
                ],
                'Default' => 'auto',
                'Description' => 'Choose source for import settings',
            ],
        ]
    ];
}

function hostkeyresellermod_ConfigOptions(): array
{
    return HostkeyResellerModLib::configOptions();
}

function hostkeyresellermod_activate(): array
{
    // Create custom tables and schema required by your module
    try {
        if (!Capsule::schema()->hasTable(HostkeyResellerModConstants::HOSTKEYRESELLERMOD_TABLE_NAME)) {
            Capsule::schema()
                ->create(
                    HostkeyResellerModConstants::HOSTKEYRESELLERMOD_TABLE_NAME,
                    function ($table) {
                        /** @var Blueprint $table */
                        $table->increments('id');
                        $table->string('type', 16);
                        $table->integer('relid');
                        $table->string('name', 256);
                        $table->string('server_type', 256);
                    }
                );
        }
        HostkeyResellerModLib::checkLogTable();
        HostkeyResellerModLib::checkImportSettingsTable();
        return [
            // Supported values here include: success, error or info
            'status' => 'success',
            'description' => '',
        ];
    } catch (Exception $e) {
        return [
            // Supported values here include: success, error or info
            'status' => 'error',
            'description' => 'Unable to create ' . HostkeyResellerModConstants::HOSTKEYRESELLERMOD_TABLE_NAME . ': ' . $e->getMessage(
                ),
        ];
    }
}

function hostkeyresellermod_deactivate(): array
{
    try {
        return [
            // Supported values here include: success, error or info
            'status' => 'success',
            'description' => '',
        ];
    } catch (Exception $e) {
        return [
            'status' => 'error',
            'description' => '',
        ];
    }
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
                $iniFile = __DIR__ . DIRECTORY_SEPARATOR . 'import.ini';
                $groupToImport = [];
                $markup = [];
                $currency = [];
                $round = 0;
                $template = 0;
                $importSource = $vars['importsource'] ?? 'auto';
                $useIni = false;
                
                switch ($importSource) {
                    case 'ini':
                        if (!file_exists($iniFile)) {
                            echo "ERROR: Import source is set to 'import.ini Only' but import.ini file does not exist\n";
                            return;
                        }
                        $useIni = true;
                        break;
                    case 'admin':
                        $useIni = false;
                        break;
                    case 'auto':
                    default:
                        $useIni = file_exists($iniFile);
                        break;
                }
                
                if ($useIni) {
                    $ini = parse_ini_file($iniFile, true);
                    foreach ($ini as $key => $value) {
                        switch ($key) {
                            case 'general':
                                $round = $value['round'] ?? 0;
                                $template = $value['template'] ?? 0;
                                break;
                            case 'markup':
                                foreach ($value as $groupName => $groupMarkup) {
                                    if ($groupMarkup) {
                                        $groupToImport[] = $groupName;
                                        $groupMarkup = explode(' ', $groupMarkup);
                                        $markup[$groupName] = $groupMarkup[0];
                                        $currency[$groupName] = $groupMarkup[1] ?? '%';
                                    }
                                }
                                break;
                        }
                    }
                } else {
                    $importSettings = HostkeyResellerModLib::getEntityByCondition(HostkeyResellerModConstants::HOSTKEYRESELLERMOD_IMPORT_SETTINGS_TABLE_NAME);
                    foreach ($importSettings as $setting) {
                        if ($setting['group'] == 'round') {
                            $round = $setting['amount'];
                        } elseif ($setting['group'] == 'template') {
                            $template = $setting['amount'];
                        } else {
                            if ($setting['active'] == '1') {
                                $groupToImport[] = $setting['group'];
                                $markup[$setting['group']] =  $setting['amount'];
                                $currency[$setting['group']] = $setting['currency'];
                            }
                        }
                    }
                }
            } else {
                $groupToImport = [];
                $productGroups = array_keys(HostkeyResellerModConstants::getProductGroups());
                foreach (array_keys($_REQUEST['g']) as $key) {
                    if (in_array($key, $productGroups)) {
                        $groupToImport[] = $key;
                    }
                }
                $markup = $_REQUEST['m'];
                $currency = $_REQUEST['c'];
                $round = $_REQUEST['r'];
                $template = $_REQUEST['e'];
            }
            HostkeyResellerModLib::saveImportSettings($groupToImport, $markup, $currency, $round, $template);
            if (count($groupToImport)) {
                $domain = $vars['apiurl'] ?? false;
                $start = time();
                $workTime = 0;
                if ($domain) {
                    try {
                        set_time_limit(1000);
                        ini_set('max_execution_time', 1000);
                        if (file_exists($domain)){
                            $json = json_decode(file_get_contents($domain), true);
                        } else {
                            $json = HostkeyResellerModLib::getPresetJson($domain . 'presets.php?action=info');
                        }
                        HostkeyResellerModLib::loadPresetsIntoDb(
                            $json['presets'],
                            $groupToImport,
                            $markup,
                            $currency,
                            $round,
                            $template
                        );
                        $workTime = time() - $start;
                    } catch (HostkeyResellerModException $e) {
                        $error = 'HostkeyResellerModException: ' . $e->getMessage();
                        $out .= $isConsole ? ($error . "\n") : ('<p><strong>' . $error . '</strong></p>');
                    }
                } else {
                    $error = 'API Url is empty';
                    $out .= $isConsole ? ($error . "\n") : ('<p><strong>' . $error . '</strong></p>');
                }
                $msg = "\n" . 'Script running time: ' . $workTime . ' sec' . "\n";
                $out .= $isConsole ? ($msg . "\n") : ('<p><strong>' . $msg . '</strong></p>');
            }
            $groups = HostkeyResellerModCounter::getGroups();
            $products = HostkeyResellerModCounter::getProducts();
            if ($isConsole) {
                $out .= count($products) . ' products in ' . count(
                        $groups
                    ) . " groups are now available to purchase.\n";
                if (count($groups)) {
                    $out .= "Groups names:\n\t" . implode("\n\t", $groups) . "\n";
                }
            } else {
                $out .= '<p>' . count($products) . ' products in ' . count(
                        $groups
                    ) . ' groups are now available to purchase.</p>';
                $out .= '<p>To set up new product go to <a href="/admin/configproducts.php">System Settings - Product Services</a></p>';
                if (count($groups)) {
                    $out .= '<p>Groups names: ' . implode(', ', $groups) . '</p>';
                }
                $out .= '<p>Get pick to the <a href="https://hostkey.ru/documentation/resselers/install/" target="_blank">HOSTKEY reselling 101 guide</a> to learn more.</p>';
                $out = '<h2>Select products to resell</h2>' . $out;
            }
            logModuleCall(
                HostkeyResellerModConstants::HOSTKEYRESELLERMOD_MODULE_NAME,
                'Load',
                json_encode($vars),
                $out
            );
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
