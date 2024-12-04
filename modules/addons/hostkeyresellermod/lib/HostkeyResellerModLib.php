<?php

namespace WHMCS\Module\Addon\Hostkeyresellermod;

use Illuminate\Database\Schema\Blueprint;
use PDO;
use WHMCS\Database\Capsule as Capsule;

/**
 * Description of HostkeyResellerModLib
 *
 * @author acround
 */
class HostkeyResellerModLib
{

    const HOSTKEYRESELLERMOD_DEBUG = true;

    private static $loadingMode = false;
    protected static $productGroups = [];
    protected static $markup;
    protected static $currency;
    protected static $round;
    protected static $currencies = null;

    public static function debug($params = null, $suffix = null)
    {
        if (!self::HOSTKEYRESELLERMOD_DEBUG) {
            return;
        }
        $r = debug_backtrace();
        $name = $r[1]['function'] ?? 'undefined';
        file_put_contents(__DIR__ . DIRECTORY_SEPARATOR . 'trace.txt', $name . "\n", FILE_APPEND);
        if ($params) {
            file_put_contents(
                __DIR__ . DIRECTORY_SEPARATOR . $name . ($suffix ? '.' . $suffix : '') . '.json',
                json_encode($params, JSON_PRETTY_PRINT)
            );
        }
    }

    public static function isConsole(): bool
    {
        return !array_key_exists("HTTP_HOST", $_SERVER);
    }

    public static function checkLogTable()
    {
        if (!Capsule::schema()->hasTable(HostkeyResellerModConstants::HOSTKEYRESELLERMOD_LOG_TABLE_NAME)) {
            Capsule::schema()
                ->create(
                    HostkeyResellerModConstants::HOSTKEYRESELLERMOD_LOG_TABLE_NAME,
                    function ($table) {
                        /** @var Blueprint $table */
                        $table->increments('id');
                        $table->date('date');
                        $table->string('module', 64);
                        $table->string('action', 64);
                        $table->text('params');
                        $table->text('response');
                    }
                );
        }
    }

    public static function logInvapi($module, $action, $params, $response)
    {
        if (!self::$loadingMode && self::getModuleSettings('logging') == 'on') {
            self::checkLogTable();
            Capsule::table(HostkeyResellerModConstants::HOSTKEYRESELLERMOD_LOG_TABLE_NAME)->insert([
                'date' => date('Y-m-d H:i:s'),
                'module' => $module,
                'action' => $action,
                'params' => is_string($params) ? $params : json_encode($params),
                'response' => $response,
            ]);
        }
    }

    /**
     * @throws HostkeyResellerModException
     */
    public static function error($message)
    {
        $r = debug_backtrace();
        $name = $r[1]['function'] ?? 'undefined';
        logActivity('HostkeyResellerMod error: Function ' . $name . ';Message: ' . $message);
        throw new HostkeyResellerModException($message);
    }

    public static function configOptions(): array
    {
        return [
            'hkid' => [
                'FriendlyName' => 'Hostkey ID',
                'Type' => 'text',
                'Size' => '4',
                'Description' => 'Hostkey ID',
            ],
            'location' => [
                'FriendlyName' => 'Location',
                'Type' => 'text',
                'Size' => '2',
                'Description' => 'Location',
            ],
            'priceusd' => [
                'FriendlyName' => 'Price (USD)',
                'Type' => 'text',
                'Size' => '10',
                'Description' => 'Price (USD)',
            ],
            'cpu' => [
                'FriendlyName' => 'CPU',
                'Type' => 'text',
                'Size' => '4',
                'Description' => 'Central processing unit',
            ],
            'ram' => [
                'FriendlyName' => 'RAM',
                'Type' => 'text',
                'Size' => '8',
                'Description' => 'Random access memory',
            ],
            'hdd' => [
                'FriendlyName' => 'HDD',
                'Type' => 'text',
                'Size' => '16',
                'Description' => 'Hard disk drive',
            ],
            'gpu' => [
                'FriendlyName' => 'GPU',
                'Type' => 'text',
                'Size' => '8',
                'Description' => 'Graphics processing unit',
            ],
        ];
    }

    public static function getNumberConfigOptionByName($name)
    {
        $names = array_keys(self::configOptions());
        return in_array($name, $names) ? (array_search($name, $names) + 1) : 0;
    }

    public static function getModuleConfig($configName = false)
    {
        $condition = [
            'module' => HostkeyResellerModConstants::HOSTKEYRESELLERMOD_MODULE_NAME,
        ];
        if ($configName) {
            $condition['setting'] = $configName;
        }
        return self::getEntityByCondition('tbladdonmodules', $condition);
    }

    protected static function getCurrencyToImport(): string
    {
        $apiUrlSetting = self::getModuleConfig('apiurl');
        $apiUrl = $apiUrlSetting['value'];
        $domain = explode('.', parse_url($apiUrl, PHP_URL_HOST));
        $domainFirst = end($domain);
        switch ($domainFirst) {
            case 'ru':
                return 'RUB';
            case 'com':
                return 'EUR';
            default:
                return 'USD';
        }
    }

    /**
     *
     * @return PDO
     */
    public static function getPdo(): ?PDO
    {
        static $pdo = null;
        if (!$pdo) {
            $pdo = Capsule::connection()->getPdo();
        }
        return $pdo;
    }

    /**
     * @throws HostkeyResellerModException
     */
    public static function getPresetJson($url)
    {
        $defaultCurrency = self::getCurrencyToImport();
        self::$currencies = self::getCurrencies()['list'];
        if (!isset(self::$currencies[$defaultCurrency])) {
            self::error('Attempt to get the preset list. Currency ' . $defaultCurrency . ' not found');
        }
        $currencies = [self::getCurrencyToImport()];
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => 1,
            CURLOPT_POSTFIELDS => http_build_query(['currencies' => implode(',', $currencies)]),
        ];
        $ch = curl_init();
        curl_setopt_array($ch, $options);
        if (!$ch) {
            self::error('Attempt to get the preset list. Unable the host:' . $url);
        }
        $resultJson = curl_exec($ch);
        if (!$resultJson) {
            self::error(curl_error($ch));
        }
        $resultObject = json_decode($resultJson, true);
        if (!$resultObject) {
            self::error('Attempt to get the preset list. Wrong JSON');
        }
        if ($resultObject['result'] != 'OK') {
            self::error('Attempt to get the preset list. Result=' . $resultObject['result']);
        }
        return $resultObject;
    }

    public static function pSort($pr1, $pr2): int
    {
        $price1 = $pr1['price'];
        $price2 = $pr2['price'];
        if (!$price1) {
            $price1 = 0;
        } else {
            $loc = $price1[array_key_first($price1)];
            $price1 = $loc[array_key_first($loc)];
        }
        if (!$price2) {
            $price2 = 0;
        } else {
            $loc = $price2[array_key_first($price2)];
            $price2 = $loc[array_key_first($loc)];
        }
        return ($price1 <=> $price2);
    }

    public static function loadPresetsIntoDb($presets, $groupToImport, $markup, $currency, $round)
    {
        self::$loadingMode = true;
        self::$markup = $markup;
        self::$currency = $currency;
        self::$round = $round;
        if (!self::$currencies) {
            self::$currencies = self::getCurrencies()['list'];
        }
        $pdo = self::getPdo();
        $presetSelect = 'SELECT `id`, `name` FROM `tblproducts` WHERE `servertype` = ?';
        $stmt = $pdo->prepare($presetSelect);
        $stmt->execute([HostkeyResellerModConstants::HOSTKEYRESELLERMOD_MODULE_NAME]);
        $oldPresetsRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $oldPresets = [];
        foreach ($oldPresetsRows as $row) {
            $oldPresets[$row['name']] = $row['id'];
        }
        $isConsole = HostkeyResellerModLib::isConsole();
        usort($presets, [self::class, 'pSort']);
        foreach ($presets as $presetInfo) {
            if (!isset($presetInfo['OS'])) {
                $presetInfo['OS'] = [];
            }
            if (!isset($presetInfo['soft'])) {
                $presetInfo['soft'] = [];
            }
            if (!isset($presetInfo['traffic_plans'])) {
                $presetInfo['traffic_plans'] = [];
            }
            if ($isConsole) {
                echo $presetInfo['name'] . ' - ';
            }
            $group = HostkeyResellerModConstants::getGroupByPresetName($presetInfo['name']);
            if (!$group || !in_array($group, $groupToImport)) {
                if ($isConsole) {
                    echo "passed\n";
                }
                continue;
            }
            $presetInfo['group'] = $group;
            $locations = explode(',', $presetInfo['locations']);
            foreach ($locations as $location) {
                $presetInfo['nameByLocation'] = $presetInfo['name'] . ' (' . $location . ')';
                $configGroupIhsoId = self::checkConfigGroup(
                    $presetInfo['nameByLocation'] . HostkeyResellerModConstants::CONFIG_GROUP_SERVER_OPTIONS_SUFFIX
                );
                $productId = self::checkProduct($presetInfo, $location);
                self::addProductConfigLink($productId, $configGroupIhsoId);
                self::clearProductConfigOptions(
                    $presetInfo,
                    HostkeyResellerModConstants::CONFIG_OPTION_OS_NAME_PREFIX,
                    'OS'
                );
                self::clearProductConfigOptions(
                    $presetInfo,
                    HostkeyResellerModConstants::CONFIG_OPTION_SOFT_NAME_PREFIX,
                    'soft'
                );
                self::clearProductConfigOptions(
                    $presetInfo,
                    HostkeyResellerModConstants::CONFIG_OPTION_TRAFFIC_NAME_PREFIX,
                    'traffic_plans'
                );
                $configOptionOrder = 1;
                $osConfigOptionId = self::addProductConfigOption(
                    $presetInfo,
                    $configGroupIhsoId,
                    HostkeyResellerModConstants::CONFIG_OPTION_OS_NAME_PREFIX,
                    $configOptionOrder++
                );
                self::addOsProductConfigOptionsSub($presetInfo, $osConfigOptionId);
                $softConfigOptionId = self::addProductConfigOption(
                    $presetInfo,
                    $configGroupIhsoId,
                    HostkeyResellerModConstants::CONFIG_OPTION_SOFT_NAME_PREFIX,
                    $configOptionOrder++
                );
                self::addSoftProductConfigOptionsSub($presetInfo, $softConfigOptionId);
                $trafficConfigOptionId = self::addProductConfigOption(
                    $presetInfo,
                    $configGroupIhsoId,
                    HostkeyResellerModConstants::CONFIG_OPTION_TRAFFIC_NAME_PREFIX,
                    $configOptionOrder
                );
                self::addTrafficProductConfigOptionsSub($presetInfo, $trafficConfigOptionId, $location);
                self::addCustomField($productId, HostkeyResellerModConstants::CUSTOM_FIELD_API_KEY_NAME);
                self::addCustomField($productId, HostkeyResellerModConstants::CUSTOM_FIELD_INVOICE_ID);
                self::addCustomField($productId, HostkeyResellerModConstants::CUSTOM_FIELD_PRESET_ID);
                $name = self::getModuleSettings('presetnameprefix') . $presetInfo['nameByLocation'];
                if (isset($oldPresets[$name])) {
                    unset($oldPresets[$name]);
                }
            }
            if ($isConsole) {
                echo "done\n";
            }
        }
        if (count($oldPresets) > 0) {
            $queryToClean = 'UPDATE `tblproducts` SET `hidden`=1 WHERE `id` IN (' . implode(
                    ',',
                    array_values($oldPresets)
                ) . ')';
            $pdo->prepare($queryToClean)->execute();
        }
        $queryGroupUpdate = 'UPDATE `tblproductgroups` SET `hidden`=? WHERE `id`=?';
        $stmtGroupUpdate = $pdo->prepare($queryGroupUpdate);
        foreach (self::$productGroups as $group) {
            $count = self::getCountByCondition('tblproducts', ['gid' => $group['id'], 'hidden' => 0]);
            if (($count == 0) || ($group['hidden'] == '1')) {
                $stmtGroupUpdate->execute(['1', $group['id']]);
            } else {
                $stmtGroupUpdate->execute(['0', $group['id']]);
            }
        }
    }

    public static function makeInsertInto($table, array $columns): string
    {
        $fields = array_keys($columns);
        $values = array_fill(0, count($columns), '?');
        return 'INSERT INTO `' . $table . '` (`' . implode('`,`', $fields) . '`)' . ' VALUES (' . implode(
                ',',
                $values
            ) . ')';
    }

    public static function checkConfigGroup($groupName)
    {
        static $stmtSelect = false;
        static $stmtInsert = false;
        $pdo = self::getPdo();
        if (!$stmtSelect) {
            $stmtSelect = $pdo->prepare('SELECT `id` FROM `tblproductconfiggroups` WHERE `name` = ?');
        }
        $params = [$groupName];
        $stmtSelect->execute($params);
        $group = $stmtSelect->fetch(PDO::FETCH_ASSOC);
        if (!$group) {
            if (!$stmtInsert) {
                $stmtInsert = $pdo->prepare(
                    'INSERT INTO `tblproductconfiggroups` (`name`, `description`)' . ' VALUES (?, \'\')'
                );
            }
            $stmtInsert->execute([$groupName]);
            $groupId = $pdo->lastInsertId();
        } else {
            $groupId = $group['id'];
        }
        return $groupId;
    }

    public static function checkProduct($presetInfo, $location)
    {
        $pdo = self::getPdo();
        $options = [
            'hkid' => $presetInfo['id'],
            'location' => $location,
        ];
        $where = ['`servertype` = ?'];
        foreach (array_keys($options) as $optionName) {
            $where[] = '`configoption' . self::getNumberConfigOptionByName($optionName) . '` = ?';
        }
        array_unshift($options, HostkeyResellerModConstants::HOSTKEYRESELLERMOD_MODULE_NAME);
        $query = 'SELECT * FROM `tblproducts` WHERE ' . implode(' AND ', $where);
        $stmtSelect = $pdo->prepare($query);
        $stmtSelect->execute(array_values($options));
        $product = $stmtSelect->fetch(PDO::FETCH_ASSOC);
        if ($product) {
            $productId = $product['id'];
            $newProduct = self::fillProductFields($presetInfo, $location);
            $newFields = [];
            $newValues = [];
            foreach ($product as $field => $value) {
                if (isset($newProduct[$field]) && ($newProduct[$field] != $value)) {
                    $newFields[] = '`' . $field . '`=?';
                    $newValues[] = $value;
                }
            }
            if ($newFields) {
                $sql = 'UPDATE `tblproducts` SET ' . implode(', ', $newFields) . ' WHERE `id`=?';
                $newValues[] = $productId;
                $pdo->prepare($sql)->execute(array_values($newValues));
            }
        } else {
            $productId = self::addProduct($presetInfo, $location);
            self::addProductSlug($presetInfo, $productId, $location);
            if (isset($presetInfo['price'][$location])) {
                self::addPricing(
                    $presetInfo['group'],
                    $productId,
                    (array)$presetInfo['price'][$location],
                    true,
                    'product'
                );
            }
        }
        return $productId;
    }

    protected static function saveGroupInfo($productGroup)
    {
        $info = [
            'type' => 'group',
            'relid' => $productGroup['id'],
            'name' => $productGroup['name'],
            'server_type' => '',
        ];
        $query = self::makeInsertInto(HostkeyResellerModConstants::HOSTKEYRESELLERMOD_TABLE_NAME, $info);
        self::getPdo()->prepare($query)->execute(array_values($info));
    }

    public static function getProductGroup($presetInfo, $location)
    {
        $pdo = self::getPdo();
        if (count(self::$productGroups) == 0) {
            $stmt = $pdo->prepare('SELECT * FROM `tblproductgroups` WHERE `tagline` = ? ORDER BY `id`');
            $stmt->execute([HostkeyResellerModConstants::GROUP_HEADLINE]);
            $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($groups as $group) {
                self::$productGroups[$group['name']] = $group;
            }
        }
        $groupName = HostkeyResellerModConstants::getGroupNameByPresetName($presetInfo['name']);
        if (!$groupName) {
            $groupName = self::getModuleSettings('defaultproductgroup');
        }
        $groupName = $location . ' ' . $groupName;
        if (!isset(self::$productGroups[$groupName])) {
            $stmt = $pdo->prepare('SELECT MAX(`order`) as `max` FROM `tblproductgroups`');
            $stmt->execute();
            $max = $stmt->fetch(PDO::FETCH_ASSOC)['max'];
            $productGroup = [
                'name' => $groupName,
                'slug' => str_replace([' ', ';'], '-', strtolower($groupName)),
                'headline' => $groupName,
                'tagline' => '',
                'orderfrmtpl' => '',
                'disabledgateways' => '',
                'hidden' => 0,
                'order' => $max + 1,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ];
            $sql = self::makeInsertInto('tblproductgroups', $productGroup);
            $pdo->prepare($sql)->execute(array_values($productGroup));
            $productGroup['id'] = $pdo->lastInsertId();
            self::$productGroups[$groupName] = $productGroup;
            HostkeyResellerModCounter::addGroup($groupName);
            self::saveGroupInfo($productGroup);
        }
        return self::$productGroups[$groupName];
    }

    public static function getAdvancedProductFields($presetInfo, $advanced = []): array
    {
        static $options = false;
        static $advField = false;
        if (!$options) {
            $options = self::configOptions();
            $advField = array_keys($options);
        }
        $out = [];
        foreach ($advField as $index => $key) {
            if (isset($presetInfo[$key])) {
                $out['configoption' . ($index + 1)] = $presetInfo[$key];
            } elseif (isset($options[$key]['Default'])) {
                $out['configoption' . ($index + 1)] = $options[$key]['Default'];
            } else {
                $out['configoption' . ($index + 1)] = 'None';
            }
        }
        foreach ($advanced as $name => $value) {
            $optionOrder = self::getNumberConfigOptionByName($name);
            if ($optionOrder) {
                $out['configoption' . ($optionOrder)] = $value;
            }
        }
        return $out;
    }

    public static function getDefaultProductFields(): array
    {
        static $fieldValues = [];
        if (!$fieldValues) {
            $defaultFieldsValues = [
                'type' => 'server',
                'gid' => 0,
                'name' => '',
                'description' => '',
                'hidden' => 0,
                'showdomainoptions' => 0,
                'welcomeemail' => 17,
                'stockcontrol' => 0,
                'qty' => 0,
                'proratabilling' => 0,
                'proratadate' => 0,
                'proratachargenextmonth' => 0,
                'paytype' => 'recurring',
                'allowqty' => 0,
                'subdomain' => '',
                'autosetup' => 'payment',
                'servertype' => '',
                'servergroup' => 0,
                'configoption1' => '',
                'configoption2' => '',
                'configoption3' => '',
                'configoption4' => '',
                'configoption5' => '',
                'configoption6' => '',
                'configoption7' => '',
                'configoption8' => '',
                'configoption9' => '',
                'configoption10' => '',
                'configoption11' => '',
                'configoption12' => '',
                'configoption13' => '',
                'configoption14' => '',
                'configoption15' => '',
                'configoption16' => '',
                'configoption17' => '',
                'configoption18' => '',
                'configoption19' => '',
                'configoption20' => '',
                'configoption21' => '',
                'configoption22' => '',
                'configoption23' => '',
                'configoption24' => '',
                'freedomain' => '',
                'freedomainpaymentterms' => '',
                'freedomaintlds' => '',
                'recurringcycles' => 0,
                'autoterminatedays' => 0,
                'autoterminateemail' => 0,
                'configoptionsupgrade' => 0,
                'billingcycleupgrade' => '',
                'upgradeemail' => 0,
                'overagesenabled' => '',
                'overagesdisklimit' => 0,
                'overagesbwlimit' => 0,
                'overagesdiskprice' => 0,
                'overagesbwprice' => 0,
                'tax' => 0,
                'affiliateonetime' => 0,
                'affiliatepaytype' => '',
                'affiliatepayamount' => 0,
                'order' => 0,
                'retired' => 0,
                'is_featured' => 0,
                'color' => '#9abb3a',
                'tagline' => '',
                'short_description' => '',
                'created_at' => date(('Y-m-d H:i:s')),
                'updated_at' => date(('Y-m-d H:i:s')),
            ];
            $pdo = self::getPdo();
            $stmt = $pdo->prepare('SHOW COLUMNS FROM `tblproducts`');
            $stmt->execute();
            $realFields = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($realFields as $field) {
                $fieldName = $field['Field'];
                if ($fieldName == 'id') {
                    continue;
                }
                if (isset($defaultFieldsValues[$fieldName])) {
                    $fieldValues[$fieldName] = $defaultFieldsValues[$fieldName];
                }
            }
        }
        return $fieldValues;
    }

    public static function fillProductFields($presetInfo, $location): array
    {
        $fields = self::getDefaultProductFields();
        $productGroup = self::getProductGroup($presetInfo, $location);
        $description = [];
        if (isset($presetInfo['cpu_name'])) {
            $description[] = '<strong>CPU:</strong> ' . $presetInfo['cpu_name'];
        }
        $coresGHz = [];
        $coresGHzName = [];
        if ($presetInfo['cpu']) {
            $coresGHzName[] = 'Cores';
            $coresGHz[] = $presetInfo['cpu'] . ' cores';
        }
        if (isset($presetInfo['cpu_ghz'])) {
            $coresGHzName[] = 'GHz';
            $coresGHz[] = $presetInfo['cpu_ghz'] . 'GHz';
        }
        if (count($coresGHz)) {
            $description[] = '<strong>' . implode(' / ', $coresGHzName) . ':</strong> ' . implode(' / ', $coresGHz);
        }
        if (isset($presetInfo['ram'])) {
            $description[] = '<strong>RAM:</strong> ' . $presetInfo['ram'] . 'GB';
        }
        if (isset($presetInfo['disk_type'])) {
            $description[] = '<strong>Disk:</strong> ' . $presetInfo['disk_type'];
        } elseif ($presetInfo['hdd']) {
            $description[] = '<strong>Disk:</strong> ' . $presetInfo['hdd'] . 'GB';
        }
        if (isset($presetInfo['gpu']) && $presetInfo['gpu']) {
            $description[] = '<strong>GPU:</strong> ' . $presetInfo['gpu'];
        }
        $extendFields = [
            'gid' => $productGroup['id'],
            'name' => self::getModuleSettings('presetnameprefix') . $presetInfo['nameByLocation'],
            'description' => implode("\n", $description),
            'hidden' => $presetInfo['active'] ? 0 : 1,
            'servertype' => HostkeyResellerModConstants::HOSTKEYRESELLERMOD_MODULE_NAME,
            'tagline' => self::getModuleSettings(
                    'presetnameprefix'
                ) . $presetInfo['nameByLocation'] . HostkeyResellerModConstants::TAGLINE_SUFFIX,
            'short_description' => $presetInfo['description'],
        ];
        foreach ($extendFields as $name => $value) {
            if (array_key_exists($name, $fields)) {
                $fields[$name] = $value;
            }
        }
        $advanced = [
            'hkid' => $presetInfo['id'],
            'location' => $location,
            'priceusd' => $presetInfo['price'][$location]['USD'] ?? 0,
        ];
        return array_merge($fields, self::getAdvancedProductFields($presetInfo, $advanced));
    }

    protected static function saveProductInfo($presetInfo, $productId)
    {
        $info = [
            'type' => 'product',
            'relid' => $productId,
            'name' => $presetInfo['name'],
            'server_type' => $presetInfo['server_type'],
        ];
        $query = self::makeInsertInto(HostkeyResellerModConstants::HOSTKEYRESELLERMOD_TABLE_NAME, $info);
        self::getPdo()->prepare($query)->execute(array_values($info));
    }

    public static function addProduct($presetInfo, $location)
    {
        static $sql = false;
        static $stmt = false;
        $pdo = self::getPdo();
        $columns = self::fillProductFields($presetInfo, $location);
        if (!$sql) {
            $sql = self::makeInsertInto('tblproducts', $columns);
            $stmt = $pdo->prepare($sql);
        }
        $stmt->execute(array_values($columns));
        $productId = $pdo->lastInsertId();
        HostkeyresellermodCounter::addProduct($columns['name']);
        self::saveProductInfo($presetInfo, $productId);
        return $productId;
    }

    public static function tableExists($tableName): bool
    {
        $query = 'SHOW TABLES LIKE \'' . $tableName . '\'';
        $pdo = self::getPdo();
        $stmtSelect = $pdo->prepare($query);
        $stmtSelect->execute();
        $result = $stmtSelect->fetch(PDO::FETCH_ASSOC);
        return (bool)$result;
    }

    public static function addProductSlug($presetInfo, $productId, $location): bool
    {
        static $stmt = false;
        if (!self::tableExists('tblproducts_slugs')) {
            return false;
        }
        $replaceStrings = [
            '+' => 'plus',
            ' ' => '-',
            '(' => '',
            ')' => '',
        ];
        $productGroup = self::getProductGroup($presetInfo, $location);
        $columns = [
            'product_id' => $productId,
            'group_id' => $productGroup['id'],
            'group_slug' => $productGroup['slug'],
            'slug' => str_replace(
                array_keys($replaceStrings),
                array_values($replaceStrings),
                $presetInfo['nameByLocation']
            ),
            'active' => $presetInfo['active'],
            'clicks' => 0,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        if (!$stmt) {
            $sql = self::makeInsertInto('tblproducts_slugs', $columns);
            $pdo = self::getPdo();
            $stmt = $pdo->prepare($sql);
        }
        return $stmt->execute(array_values($columns));
    }

    public static function addProductConfigLink($productId, $groupId)
    {
        static $stmtSelect = false;
        static $stmtInsert = false;
        $pdo = self::getPdo();
        if (!$stmtSelect) {
            $sql = 'SELECT `id` FROM `tblproductconfiglinks` WHERE `gid` = ? AND pid = ?';
            $stmtSelect = $pdo->prepare($sql);
        }
        $params = [$groupId, $productId];
        $stmtSelect->execute($params);
        $result1 = $stmtSelect->fetch(PDO::FETCH_ASSOC);
        if (!$result1) {
            if (!$stmtInsert) {
                $sql = 'INSERT INTO `tblproductconfiglinks` (`gid`,`pid`)' . ' VALUES (?,?)';
                $stmtInsert = $pdo->prepare($sql);
            }
            return $stmtInsert->execute($params);
        }
        return 1;
    }

    public static function clearProductConfigOptions($presetInfo, $prefix, $fieldName)
    {
        static $stmtSelectOption = false;
        static $stmtSelectOptionSub = false;
        static $stmtDeleteOptionSubOne = false;
        static $stmtDeleteOptionSubAll = false;
        static $stmtDeletePricing = false;
        $pdo = self::getPdo();
        $configOptionName = $prefix . $presetInfo['nameByLocation'];
        if (!$stmtSelectOption) {
            $sql = 'SELECT `id` FROM `tblproductconfigoptions` WHERE `optionname` = ?';
            $stmtSelectOption = $pdo->prepare($sql);
        }
        $stmtSelectOption->execute([$configOptionName]);
        $result = $stmtSelectOption->fetch(PDO::FETCH_ASSOC);
        $fieldList = $presetInfo[$fieldName] ?? [];
        if ($result) {
            if (!count($fieldList)) {
                if (!$stmtDeleteOptionSubAll) {
                    $stmtDeleteOptionSubAll = $pdo->prepare(
                        'DELETE FROM `tblproductconfigoptionssub` WHERE `configid` = ?'
                    );
                }
                $stmtDeleteOptionSubAll->execute([$result['id']]);
            } else {
                if (!$stmtSelectOptionSub) {
                    $sql = 'SELECT `id`, `optionname` FROM `tblproductconfigoptionssub` WHERE `configid` = ?';
                    $stmtSelectOptionSub = $pdo->prepare($sql);
                }
                $stmtSelectOptionSub->execute([$result['id']]);
                $result = $stmtSelectOptionSub->fetchAll(PDO::FETCH_ASSOC);
                $optionsSub = [];
                foreach ($result as $row) {
                    $optionsSub[$row['optionname']] = $row['id'];
                }
                $valuesFromJson = [];
                if ($fieldName == 'traffic_plans') {
                    foreach ($fieldList as $location => $value) {
                        if (is_array($value)) {
                            foreach ($value as $item) {
                                $valuesFromJson[$item['id']] = '(' . $location . ') ' . $item['name'];
                            }
                        } else {
                            $valuesFromJson[$value['id']] = $value['name'];
                        }
                    }
                } else {
                    foreach ($fieldList as $value) {
                        $valuesFromJson[$value['id']] = $value['name'];
                    }
                }
                foreach (array_keys($optionsSub) as $value) {
                    $name = substr($value, 0, strrpos($value, ' (#'));
                    if (!in_array($name, $valuesFromJson)) {
                        if (!$stmtDeletePricing) {
                            $stmtDeletePricing = $pdo->prepare('DELETE FROM `tblpricing` WHERE `relid` = ?');
                        }
                        $stmtDeletePricing->execute([$optionsSub[$value]]);
                        if (!$stmtDeleteOptionSubOne) {
                            $stmtDeleteOptionSubOne = $pdo->prepare(
                                'DELETE FROM `tblproductconfigoptionssub` WHERE `id` = ?'
                            );
                        }
                        $stmtDeleteOptionSubOne->execute([$optionsSub[$value]]);
                    }
                }
            }
        }
    }

    public static function addProductConfigOption($presetInfo, $groupId, $prefix, $order = 0)
    {
        static $stmtSelect = false;
        static $stmtInsert = false;

        $pdo = self::getPdo();
        if (!$stmtSelect) {
            $stmtSelect = $pdo->prepare('SELECT `id` FROM `tblproductconfigoptions` WHERE `optionname` = ?');
        }
        $configOptionName = $prefix . $presetInfo['nameByLocation'];
        $stmtSelect->execute([$configOptionName]);
        $result = $stmtSelect->fetch(PDO::FETCH_ASSOC);
        if (!$result) {
            if (!$stmtInsert) {
                $stmtInsert = $pdo->prepare(
                    'INSERT INTO `tblproductconfigoptions` (`gid`,`optionname`, `optiontype`, `qtyminimum`, `qtymaximum`, `order`, `hidden`) VALUES (?,?,?,?,?,?,?)'
                );
            }
            $params = [$groupId, $configOptionName, 1, 0, 0, $order, 0];
            $stmtInsert->execute($params);
            $configOptionId = $pdo->lastInsertId();
        } else {
            $configOptionId = $result['id'];
        }
        return $configOptionId;
    }

    public static function addOsProductConfigOptionsSub($presetInfo, $configOptionId)
    {
        static $stmtSelect = false;
        static $stmtInsert = false;

        if (!isset($presetInfo['OS']) || !count($presetInfo['OS'])) {
            return;
        }
        $pdo = self::getPdo();
        $os = $presetInfo['OS'];
        foreach ($os as $index => $item) {
            if (!$stmtSelect) {
                $stmtSelect = $pdo->prepare(
                    'SELECT `id` FROM `tblproductconfigoptionssub` WHERE `optionname` LIKE ? AND `configid` = ?'
                );
            }
            $configOptionNameSub = $item['name'] . ' (#' . $item['id'] . ')';
            $stmtSelect->execute([$configOptionNameSub, $configOptionId]);
            $result = $stmtSelect->fetch(PDO::FETCH_ASSOC);
            if (!$result) {
                if (!$stmtInsert) {
                    $stmtInsert = $pdo->prepare(
                        'INSERT INTO `tblproductconfigoptionssub` (`configid`,`optionname`,`sortorder`,`hidden`) VALUES (?,?,?,?)'
                    );
                }
                $params = [$configOptionId, $configOptionNameSub, ($index + 1), 0];
                $stmtInsert->execute($params);
                $relid = $pdo->lastInsertId();
            } else {
                $relid = $result['id'];
            }
            self::addPricing($presetInfo['group'], $relid, $item['price'] ?? []);
        }
    }

    public static function addSoftProductConfigOptionsSub($presetInfo, $configOptionId)
    {
        static $stmtSelect = false;
        static $stmtInsert = false;
        static $doNotInstall = false;

        if (!isset($presetInfo['soft']) || !count($presetInfo['soft'])) {
            return;
        }
        $soft = $presetInfo['soft'];
        if (!$doNotInstall) {
            $doNotInstall = [
                'id' => 0,
                'name' => HostkeyResellerModConstants::DO_NOT_INSTALL_ITEM_NAME,
                'description' => HostkeyResellerModConstants::DO_NOT_INSTALL_ITEM_NAME
            ];
        }
        array_unshift($soft, $doNotInstall);
        $pdo = self::getPdo();
        foreach ($soft as $index => $item) {
            if (!$stmtSelect) {
                $stmtSelect = $pdo->prepare(
                    'SELECT `id` FROM `tblproductconfigoptionssub` WHERE `optionname` LIKE ? AND `configid` = ?'
                );
            }
            $configOptionNameSub = $item['name'] . ($item['id'] ? ' (#' . $item['id'] . ')' : '');
            $stmtSelect->execute([$configOptionNameSub, $configOptionId]);
            $result = $stmtSelect->fetch(PDO::FETCH_ASSOC);
            if (!$result) {
                if (!$stmtInsert) {
                    $stmtInsert = $pdo->prepare(
                        'INSERT INTO `tblproductconfigoptionssub` (`configid`,`optionname`,`sortorder`,`hidden`) VALUES (?,?,?,?)'
                    );
                }
                $params = [$configOptionId, $configOptionNameSub, ($index + 1), 0];
                $stmtInsert->execute($params);
                $relid = $pdo->lastInsertId();
            } else {
                $relid = $result['id'];
            }
            self::addPricing($presetInfo['group'], $relid, $item['price'] ?? []);
        }
    }

    public static function addTrafficProductConfigOptionsSub($presetInfo, $configOptionId, $location)
    {
        static $stmtSelect = false;
        static $stmtInsert = false;

        $traffics = (array)($presetInfo['traffic_plans'] ?? []);
        if (!count($traffics)) {
            return;
        }
        $pdo = self::getPdo();
        if (!$stmtSelect) {
            $stmtSelect = $pdo->prepare(
                'SELECT `id` FROM `tblproductconfigoptionssub` WHERE `optionname` LIKE ? AND `configid` = ?'
            );
        }
        if (isset($traffics[$location])) {
            $list = $traffics[$location];
            foreach ($list as $index => $item) {
                $configOptionNameSub = $item['name'] . ' (#' . $item['id'] . ')';
                $stmtSelect->execute([$configOptionNameSub, $configOptionId]);
                $result = $stmtSelect->fetch(PDO::FETCH_ASSOC);
                if (!$result) {
                    if (!$stmtInsert) {
                        $stmtInsert = $pdo->prepare(
                            'INSERT INTO `tblproductconfigoptionssub` (`configid`,`optionname`,`sortorder`,`hidden`) VALUES (?,?,?,?)'
                        );
                    }
                    $params = [$configOptionId, $configOptionNameSub, ($index + 1), 0];
                    $stmtInsert->execute($params);
                    $relid = $pdo->lastInsertId();
                } else {
                    $relid = $result['id'];
                }
                self::addPricing($presetInfo['group'], $relid, $item['price'] ?? []);
            }
        }
    }

    public static function getCurrencies()
    {
        static $currencies = false;
        $pdo = self::getPdo();
        if (!$currencies) {
            $currencies = [
                'list' => [],
            ];
            $stmt = $pdo->prepare('SELECT * FROM `tblcurrencies`');
            $stmt->execute();
            $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($result as $currency) {
                $currencies['list'][$currency['code']] = $currency;
                if ($currency['default']) {
                    $currencies['default'] = $currency['code'];
                }
            }
        }
        return $currencies;
    }

    protected static function round($value)
    {
        return self::$round ? round(round($value * self::$round) / self::$round, 2) : $value;
    }

    public static function addPricing(
        $group,
        $optionSubId,
        array $prices = [],
        bool $hasDiscount = false,
        string $type = 'configoptions'
    ): int {
        static $stmtSelect = false;
        static $stmtInsert = false;

        $ret = 0;
        $currencies = self::getCurrencies()['list'];
        if ($prices) {
            $codeFirst = array_key_first($prices);
            $firstPrice = $prices[$codeFirst] ?? 0;
        } else {
            $firstPrice = 0;
            $codeFirst = self::getCurrencyToImport();
        }
        foreach (array_keys($currencies) as $code) {
            if (!isset($prices[$code])) {
                $prices[$code] = $firstPrice / $currencies[$codeFirst]['rate'] * $currencies[$code]['rate'];
            }
        }
        $pdo = self::getPdo();
        if ($hasDiscount) {
            $discount = [
                'quarterly' => 0.03,
                'semiannually' => 0.06,
                'annually' => 0.12
            ];
        } else {
            $discount = [
                'quarterly' => 0,
                'semiannually' => 0,
                'annually' => 0
            ];
        }

        if ($type == 'product') {
            $markupCurrency = self::$currency[$group] ?? '%';
            if ($markupCurrency == '%') {
                $markup = (isset(self::$markup[$group]) ? (self::$markup[$group] / 100) : 0) + 1;
            } else {
                $markup = self::$markup[$group] ?? 0;
            }
        }
        $fieldsToInsert = self::getPricingFields();
        $fieldsToInsert['type'] = $type;
        $fieldsToInsert['relid'] = $optionSubId;
        foreach ($currencies as $code => $currency) {
            $fieldsToInsert['currency'] = $currency['id'];
            $baseAmount = $prices[$code] ?? 0;
            if ($type == 'product') {
                if ($baseAmount == 0) {
                    $price = 0;
                } elseif ($markupCurrency == '%') {
                    $price = $baseAmount * $markup;
                } else {
                    $markupCurrent = $markup * $currency['rate'] / $currencies[$markupCurrency]['rate'];
                    $price = $baseAmount + $markupCurrent;
                }
            } else {
                $price = $baseAmount;
            }
            $fieldsToInsert['monthly'] = self::round($price);
            $fieldsToInsert['quarterly'] = self::round($price * (1 - $discount['quarterly']) * 3);
            $fieldsToInsert['semiannually'] = self::round($price * (1 - $discount['semiannually']) * 6);
            $fieldsToInsert['annually'] = self::round($price * (1 - $discount['annually']) * 12);
            if (!$stmtSelect) {
                $stmtSelect = $pdo->prepare(
                    'SELECT * FROM `tblpricing` WHERE `type` = ? AND `relid` = ? AND `currency` = ?'
                );
            }
            $stmtSelect->execute([$type, $optionSubId, $currency['id']]);
            $res = $stmtSelect->fetch(PDO::FETCH_ASSOC);
            if ($res) {
                $newFields = [];
                $newValues = [];
                foreach ($fieldsToInsert as $field => $value) {
                    if ($res[$field] != $value) {
                        $newFields[] = '`' . $field . '`=?';
                        $newValues[] = $value;
                    }
                }
                if ($newFields) {
                    $sql = 'UPDATE `tblpricing` SET ' . implode(', ', $newFields) . ' WHERE `id`=?';
                    $newValues[] = $res['id'];
                    $ret += (int)$pdo->prepare($sql)->execute(array_values($newValues));
                }
            } else {
                if (!$stmtInsert) {
                    $sql = self::makeInsertInto('tblpricing', $fieldsToInsert);
                    $stmtInsert = $pdo->prepare($sql);
                }
                $ret += (int)$stmtInsert->execute(array_values($fieldsToInsert));
            }
        }
        return $ret;
    }

    public static function getPricingFields(): array
    {
        return [
            'type' => '',
            'relid' => '',
            'currency' => '',
            'msetupfee' => 0,
            'qsetupfee' => 0,
            'ssetupfee' => 0,
            'asetupfee' => 0,
            'bsetupfee' => 0,
            'tsetupfee' => 0,
            'monthly' => -1,
            'quarterly' => -1,
            'semiannually' => -1,
            'annually' => -1,
            'biennially' => -1,
            'triennially' => -1
        ];
    }

    public static function addCustomField($productId, $name, $description = null)
    {
        static $querySelectCustomFields = 'SELECT `id` FROM `tblcustomfields` WHERE `type`= ? AND `relid` = ? AND fieldname = ?';
        static $stmtSelectCustomFields = null;

        $pdo = self::getPdo();
        if (!$stmtSelectCustomFields) {
            $stmtSelectCustomFields = $pdo->prepare($querySelectCustomFields);
        }
        $stmtSelectCustomFields->execute(['product', $productId, $name]);
        $fieldid = $stmtSelectCustomFields->fetchColumn();
        if (!$fieldid) {
            $columns = [
                'type' => 'product',
                'relid' => $productId,
                'fieldname' => $name,
                'fieldtype' => 'text',
                'description' => $description ?? $name,
                'fieldoptions' => '',
                'regexpr' => '',
                'adminonly' => 'on',
                'required' => '',
                'showorder' => '',
                'showinvoice' => '',
                'sortorder' => 0,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ];
            $sql = self::makeInsertInto('tblcustomfields', $columns);
            return $pdo->
            prepare($sql)->
            execute(array_values($columns));
        }
        return 0;
    }

    public static function out(): string
    {
        $currenciesToOut = ['%'];
        $apiUrl = self::getModuleSettings('apiurl');
        $apiHost = parse_url($apiUrl, PHP_URL_HOST);
        $apiHostArr = explode('.', $apiHost);
        $domainFirstLevel = strtolower($apiHostArr[count($apiHostArr) - 1]);
        switch ($domainFirstLevel) {
            case 'com':
                $currenciesToOut[] = 'USD';
                $currenciesToOut[] = 'EUR';
                break;
            case 'ru':
                $currenciesToOut[] = 'RUB';
                break;
        }
        $currenciesHere = self::getEntityByCondition('tblcurrencies');
        foreach ($currenciesHere as $currency) {
            if (!in_array($currency['code'], $currenciesToOut)) {
                $currenciesToOut[] = $currency['code'];
            }
        }
        $select = '<select class="form-control input-inline input-100" name="c[%s]">';
        foreach ($currenciesToOut as $currency) {
            if ($currency == '%') {
                $currency = '%%';
            }
            $select .= '<option value="' . $currency . '">' . $currency . '</option>';
        }
        $select .= '</select>';
        $out = '<form action="" method="get">';
        $out .= '<input type="hidden" name="module" value="' . HostkeyResellerModConstants::HOSTKEYRESELLERMOD_MODULE_NAME . '" />';
        $out .= '<input type="hidden" name="action" value="load" />';
        $out .= '<table class="form" style="width: auto;;"><thead><tr><th> Select products to resell </th><th style="min-width: 25em;"> Set price multiplier </th></tr></thead><tbody>';
        foreach (HostkeyResellerModConstants::getProductGroupsButtons() as $name => $desc) {
            $out .= '<tr>';
            $out .= '<td class="fieldarea"><input type="checkbox" name="g[' . $name . ']" checked=""> ' . $desc . '</td>';
            $out .= '<td class="fieldarea"><p style="white-space: nowrap;"><input class="form-control input-inline input-100" type="text" name="m[' . $name . ']" value="0"> ' . sprintf(
                    $select,
                    $name
                ) . ' price increase</p></td>';
            $out .= '</tr>';
        }
        $out .= '<tr>';
        $out .= '<td class="fieldarea"></td>';
        $out .= '<td class="fieldarea"><select class="form-control input-inline input-100" name="r">'
            . '<option value="0">Not round</option>'
            . '<option value="10">0.1, 0.2, etc</option>'
            . '<option value="4">0.25, 0.5, 0.75</option>'
            . '<option value="2">0.5, 1.0</option>'
            . '<option value="1">1.0</option>'
            . '</select> round price to</td>';
        $out .= '</tr>';
        $out .= '</tr></tbody></table>';
        $out .= '<button type="submit" class="btn btn-success">Import products/Adjust prices</button>';
        $out .= '</form>';
        $stmt = self::getPdo()->prepare('SELECT COUNT(*) AS cnt FROM `tblproducts` WHERE `servertype` = ?');
        $stmt->execute([HostkeyResellerModConstants::HOSTKEYRESELLERMOD_MODULE_NAME]);
        $productCount = $stmt->fetchColumn();
        if ($productCount > 0) {
            $out .= '<h2>Or Remove Hostkey products</h2>';
            $out .= '<form action="" method="get">';
            $out .= '<input type="hidden" name="module" value="' . HostkeyResellerModConstants::HOSTKEYRESELLERMOD_MODULE_NAME . '" />';
            $out .= '<input type="hidden" name="action" value="clear" />';
            $out .= '<button type="submit" class="btn btn-danger">Remove Hostkey products</button>';
            $out .= '</form>';
        }
        return $out;
    }

    /**
     * @throws HostkeyResellerModException
     */
    public static function getClientInfo()
    {
        static $clientInfo = false;
        if (!$clientInfo) {
            $params = [
                'token' => self::getTokenByApiKey(),
            ];
            $res = self::makeInvapiCall($params, 'whmcs', 'get_client');
            $clientInfo = $res['result'] == 'success' ? $res['client'] : null;
        }
        return $clientInfo;
    }

    public static function getEntityById($table, $id)
    {
        $pdo = self::getPdo();
        $query = 'SELECT * FROM `' . $table . '` WHERE `id` = ?';
        $stmt = $pdo->prepare($query);
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public static function getEntityByCondition($table, array $condition = [], $forceArray = false)
    {
        $pdo = self::getPdo();
        $where = [];
        foreach (array_keys($condition) as $field) {
            $where[] = '`' . $field . '` = ?';
        }
        $query = 'SELECT * FROM `' . $table . '`' . (count($where) ? (' WHERE ' . implode(' AND ', $where)) : '');
        $stmt = $pdo->prepare($query);
        $stmt->execute(array_values($condition));
        $objects = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return ((is_array($objects) && (count($objects) == 1)) && !$forceArray) ? $objects[0] : $objects;
    }

    public static function getCountByCondition($table, array $condition)
    {
        $pdo = self::getPdo();
        $where = [];
        foreach (array_keys($condition) as $field) {
            $where[] = '`' . $field . '` = ?';
        }
        $query = 'SELECT count(*) FROM `' . $table . '` WHERE ' . implode(' AND ', $where);
        $stmt = $pdo->prepare($query);
        $stmt->execute(array_values($condition));
        return $stmt->fetchColumn();
    }

    public static function getModuleSettings($name = null)
    {
        static $settings = [];
        if (!$settings) {
            $pdo = self::getPdo();
            $sqlSettings = 'SELECT * FROM `tbladdonmodules` WHERE `module` = ?';
            $stmt = $pdo->prepare($sqlSettings);
            $stmt->execute([HostkeyResellerModConstants::HOSTKEYRESELLERMOD_MODULE_NAME]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $settings[$row['setting']] = $row['value'];
            }
        }
        return $name ? ($settings[$name] ?? null) : $settings;
    }

    public static function getAuthUrl(): string
    {
        $settings = self::getModuleSettings();
        return $settings['apiurl'] . 'auth.php?action=login&key=' . $settings['apikey'];
    }

    public static function makeInvapiCallUrl($module, $action = false): string
    {
        $apiurl = self::getModuleSettings('apiurl');
        $parsedUrl = parse_url($apiurl);
        return $parsedUrl['scheme'] . '://' . $parsedUrl['host'] . '/' . $module . '.php' . ($action ? '?action=' . $action : '');
    }

    /**
     * @throws HostkeyResellerModException
     */
    public static function makeInvapiCall($paramsToCall, $module, $action = false)
    {
        $url = self::makeInvapiCallUrl($module);
        if ($action) {
            $paramsToCall['action'] = $action;
        }
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => 1,
            CURLOPT_POSTFIELDS => http_build_query($paramsToCall),
        ];
        $curl = curl_init();
        curl_setopt_array($curl, $options);
        if (!$curl) {
            self::error('Attempt to call invapi. Unable the host:' . $url);
        }
        $resultJson = curl_exec($curl);
        self::logInvapi($module, $action, $paramsToCall, $resultJson);
        curl_close($curl);
        if (!$resultJson) {
            $error = curl_error($curl) ?? 'Unknown error';
            self::error('Attempt to call invapi. ' . $error);
        }
        return json_decode($resultJson, true);
    }

    /**
     * @throws HostkeyResellerModException
     */
    public static function getTokenByApiKey()
    {
        static $token = false;
        if (!$token) {
            $url = self::getAuthUrl();
            $optionsAuth = [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
            ];
            $curl = curl_init();
            curl_setopt_array($curl, $optionsAuth);
            if (!$curl) {
                self::error('Attempt to get a token. Unable the host:' . $url);
            }
            $resultJsonAuth = curl_exec($curl);
            $error = curl_error($curl);
            curl_close($curl);
            if (!$resultJsonAuth) {
                self::error($error);
            }
            $resultObjectAuth = json_decode($resultJsonAuth, true);
            $token = $resultObjectAuth['result']['token'] ?? null;
            if (!$token) {
                self::error('Attempt to get a token. Token error');
            }
        }
        return $token;
    }

    public static function parseConfigOption($option): array
    {
        $pattern = '/(.+) \(#(\d+)\)/';
        $matches = [];
        preg_match($pattern, $option, $matches);
        return $matches;
    }

    public static function makePassword(): string
    {
        $length = HostkeyResellerModConstants::PASSWORD_LENGTH;
        $chars = [
            'qazxswedcvfrtgbnhyujmkiolp',
            'QAZXSWEDCVFRTGBNHYUJMKIOLP',
            '1234567890',
            '_'
        ];
        $password = [];
        $line = 0;
        while ($length--) {
            $password[] = $chars[$line][random_int(0, strlen($chars[$line]))];
            $line++;
            if ($line >= count($chars)) {
                $line = 0;
            }
        }
        shuffle($password);
        return implode('', $password);
    }

    /**
     * @throws HostkeyResellerModException
     */
    public static function assembleOrderInfo($invoiceId): array
    {
        $params = [];
        $order = self::getEntityByCondition('tblorders', ['invoiceid' => $invoiceId]);
        $hosting = self::getEntityByCondition('tblhosting', ['orderid' => $order['id']]);
        $product = self::getEntityById('tblproducts', $hosting['packageid']);
        $location = $product['configoption' . self::getNumberConfigOptionByName('location')];
        $params['hosting'] = $hosting['id'];
        $params['model']['client']['email'] = self::getClientInfo()['email'];
        $params['model']['billingcycle'] = $hosting['billingcycle'];
        $params['configoptions'] = [];
        $hostingOptions = self::getEntityByCondition('tblhostingconfigoptions', ['relid' => $hosting['id']]);
        foreach ($hostingOptions as $hostingOption) {
            $name = self::getEntityById('tblproductconfigoptions', $hostingOption['configid']);
            $value = self::getEntityById('tblproductconfigoptionssub', $hostingOption['optionid']);
            $params['configoptions'][$name['optionname']] = $value['optionname'];
        }
        $params['model']['domain'] = $hosting['domain'];
        $params['model']['product']['name'] = self::getEntityById('tblproducts', $hosting['packageid'])['name'];
        $params['model']['location'] = $location;
        return $params;
    }

    /**
     * @throws HostkeyResellerModException
     */
    public static function fillOrderCall($params): array
    {
        $pattern = '/' . self::getModuleSettings('presetnameprefix') . '(.*) \([A-Z]{2}\)/';
        $matches = [];
        $m = preg_match($pattern, $params['model']['product']['name'], $matches);
        if ($m) {
            $preset = $matches[1];
        } else {
            $preset = substr($params['model']['product']['name'], strlen(self::getModuleSettings('presetnameprefix')));
        }

        $matchesOs = self::parseConfigOption(
            $params['configoptions'][HostkeyResellerModConstants::CONFIG_OPTION_OS_NAME_PREFIX . $preset . ' (' . $params['model']['location'] . ')']
        );
        $osName = $matchesOs[1] ?? '';
        $osId = $matchesOs[2] ?? '';

        $matchesSoft = self::parseConfigOption(
            $params['configoptions'][HostkeyResellerModConstants::CONFIG_OPTION_SOFT_NAME_PREFIX . $preset . ' (' . $params['model']['location'] . ')']
        );
        $softName = $matchesSoft[1] ?? '';
        $softId = $matchesSoft[2] ?? '';

        $matchesTariff = self::parseConfigOption(
            $params['configoptions'][HostkeyResellerModConstants::CONFIG_OPTION_TRAFFIC_NAME_PREFIX . $preset . ' (' . $params['model']['location'] . ')']
        );
        $tariffId = $matchesTariff[2] ?? '';

        $token = self::getTokenByApiKey();

        $paramsToCall = [
//            'action' => 'order_instance',
            'token' => $token,
            'deploy_period' => strtolower($params['model']['billingcycle']),
            'deploy_notify' => '1',
            'email' => $params['model']['client']['email'] ?? '',
            'os_id' => $osId,
            'root_pass' => self::makePassword(),
            'hostname' => $params['model']['domain'],
            'os_name' => $osName,
            'traffic_plan' => $tariffId,
            'preset' => $preset,
            'location_name' => $params['model']['location'],
            'post_install_callback' => $_SERVER['REQUEST_SCHEME'] . '://' . ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME']) . '/modules/gateways/callback/' . HostkeyResellerModConstants::HOSTKEYRESELLERMOD_MODULE_NAME . '.php?hosting=' . $params['hosting'],
        ];
        if ($softId & $softName) {
            $paramsToCall['soft_id'] = $softId;
            $paramsToCall['soft_name'] = $softName;
        }
        return $paramsToCall;
    }

    /**
     * @throws HostkeyResellerModException
     */
    public static function orderInstance($paramsToCall)
    {
        return self::makeInvapiCall($paramsToCall, 'eq', 'order_instance');
    }

    /**
     * @throws HostkeyResellerModException
     */
    public static function payInvoice($invoiceId)
    {
        $paramsToGet = [
            'token' => self::getTokenByApiKey(),
            'invoice_id' => $invoiceId,
        ];
        $invoice = self::makeInvapiCall($paramsToGet, 'whmcs', 'get_invoice');
        if ($invoice['result'] == 'OK') {
            $paramsToCall = [
                'token' => self::getTokenByApiKey(),
                'invoice_id' => $invoiceId,
                'amount' => $invoice['total'],
            ];
            $r = self::makeInvapiCall($paramsToCall, 'whmcs', 'apply_credit');
        } else {
            $r = [
                'result' => 'Fail',
                'message' => 'No invoice',
            ];
        }
        return $r;
    }

    public static function InvoicePaid($invoiceId)
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
            $invoiceId,
        ];
        $pdo = HostkeyResellerModLib::getPdo();
        $customFieldValueStmt = $pdo->prepare($customFieldValueQuery);
        $customFieldValueStmt->execute($params);
        $presetId = $customFieldValueStmt->fetchColumn();
        if ($presetId) {
            $customerInvoice = HostkeyResellerModLib::getEntityById('tblinvoices', $invoiceId);
            $customerPaid = floatval($customerInvoice['total']);
            $paramsToShow = [
                'token' => HostkeyResellerModLib::getTokenByApiKey(),
                'id' => $presetId,
            ];
            $server = HostkeyResellerModLib::makeInvapiCall($paramsToShow, 'eq', 'show');
            $paramsToGetInvoices = [
                'token' => HostkeyResellerModLib::getTokenByApiKey(),
                'product' => $server['account_id'],
                'location' => $server['type_billing'],
            ];
            $res = HostkeyResellerModLib::makeInvapiCall($paramsToGetInvoices, 'whmcs', 'get_product_invoice');
            $invoices = $res['invoices'] ?? [];
            foreach ($invoices as $invoice) {
                $haveToPay = floatval($invoice['total']);
                if (($invoice['status'] == 'Unpaid') && ($customerPaid >= $haveToPay)) {
                    $customerPaid -= $haveToPay;
                    HostkeyResellerModLib::payInvoice($invoice['invoiceid']);
                    if ($customerPaid <= 0) {
                        break;
                    }
                }
            }
        } else {
            $r = HostkeyResellerModLib::makeOrder($invoiceId);
            if ($r && $r['invoice']) {
                HostkeyResellerModLib::addInvoiceId($invoiceId, $r['invoice']);
                HostkeyResellerModLib::payInvoice($r['invoice']);
            }
            return $r['invoice'];
        }
    }

    public static function addCustomFieldValue($productId, $hostingId, $name, $value)
    {
        static $querySelectCustomFields = 'SELECT `id` FROM `tblcustomfields` WHERE `type`= ? AND `relid` = ? AND fieldname = ?';
        static $stmtSelectCustomFields = null;

        $pdo = self::getPdo();
        if (!$stmtSelectCustomFields) {
            $stmtSelectCustomFields = $pdo->prepare($querySelectCustomFields);
        }
        $stmtSelectCustomFields->execute(['product', $productId, $name]);
        $fieldid = $stmtSelectCustomFields->fetchColumn();
        if ($fieldid) {
            $columns = [
                'fieldid' => $fieldid,
                'relid' => $hostingId,
                'value' => $value,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ];
            $count = self::getCountByCondition('tblcustomfieldsvalues', $columns);
            if (!$count) {
                $sql = self::makeInsertInto('tblcustomfieldsvalues', $columns);
                return $pdo->
                prepare($sql)->
                execute(array_values($columns));
            } else {
                return 0;
            }
        }
        return 0;
    }

    public static function getCustomFieldValue($hostingId, $fieldName)
    {
        static $query = 'SELECT cfv.`value` '
            . 'FROM `tblcustomfieldsvalues` AS cfv '
            . 'JOIN `tblcustomfields` AS cf ON  cfv.`fieldid` = cf.id '
            . 'JOIN `tblhosting` AS h ON h.id = cfv.`relid` '
            . 'WHERE cf.`type`= \'product\' AND cfv.`relid` = ? AND cf.`fieldname` = ?';
        static $stmt = null;
        $pdo = self::getPdo();
        if (!$stmt) {
            $stmt = $pdo->prepare($query);
        }
        $stmt->execute([$hostingId, $fieldName]);
        return $stmt->fetchColumn();
    }

    /**
     * @throws HostkeyResellerModException
     */
    public static function getServerIdByInvoiceId($invoiceId, $location): int
    {
        $params = [
            'action' => 'get_invoice',
            'token' => self::getTokenByApiKey(),
            'invoice_id' => $invoiceId,
            'location' => $location,
        ];
        $invoiceInfo = self::makeInvapiCall($params, 'whmcs', 'get_invoice');
        $ret = -1;
        if (($invoiceInfo['result'] == 'OK') && (count($invoiceInfo['items']['item']) > 0)) {
            $ret = intval($invoiceInfo['items']['item'][0]['inv_id']);
        }
        return max($ret, 0);
    }

    /**
     * @throws HostkeyResellerModException
     */
    public static function getApiKeyList($serverId)
    {
        $paramsToCall = [
            'action' => 'list_for_server',
            'token' => self::getTokenByApiKey(),
            'params' => [
                'server_id' => $serverId,
            ],
        ];
        $responce = self::makeInvapiCall($paramsToCall, 'api_keys');
        return $responce['data'] ?? [];
    }

    public static function addInvoiceId($invoiceId, $invoiceR)
    {
        $ret = null;
        $order = self::getEntityByCondition('tblorders', ['invoiceid' => $invoiceId]);
        if ($order) {
            $hosting = self::getEntityByCondition('tblhosting', ['orderid' => $order['id']]);
            $ret = self::addCustomFieldValue(
                $hosting['packageid'],
                $hosting['id'],
                HostkeyResellerModConstants::CUSTOM_FIELD_INVOICE_ID,
                $invoiceR
            );
        }
        return $ret;
    }

    /**
     * @throws HostkeyResellerModException
     */
    public static function addApiKey($name, $serverId)
    {
        $paramsToCall = [
            'action' => 'add',
            'token' => self::getTokenByApiKey(),
            'params' => [
                'name' => $name,
                'server_id' => $serverId,
                'active' => 1,
                'ip' => '',
                'login_notify_method' => 'none',
                'login_notify_address' => self::getClientInfo()['email'],
            ],
        ];
        $apiKey = self::makeInvapiCall($paramsToCall, 'api_keys');
        return $apiKey['data']['api_key'] ?? null;
    }

    /**
     * @throws HostkeyResellerModException
     */
    public static function makeOrder($invoiceId)
    {
        $obj = self::assembleOrderInfo($invoiceId);
        $params = self::fillOrderCall($obj);
        return self::orderInstance($params);
    }

    /**
     * @throws HostkeyResellerModException
     */
    public static function setHostingStatus($hostingId, $status): bool
    {
        if (!in_array($status, HostkeyResellerModConstants::getHostingStatuses())) {
            self::error('Invalid status: ' . $status);
        }
        $pdo = self::getPdo();
        $hs = $pdo->prepare('UPDATE `tblhosting` SET domainstatus = ? WHERE `id` = ?')->execute([$status, $hostingId]);
        if ($hs) {
            $hosting = self::getEntityById('tblhosting', $hostingId);
            $pdo->prepare('UPDATE `tblorders` SET status = ? WHERE `id` = ?')->execute([$status, $hosting['orderid']]);
        }
        return $hs;
    }

    public static function getLocation($hostingId)
    {
        $hosting = self::getEntityById('tblhosting', $hostingId);
        $product = self::getEntityById('tblproducts', $hosting['packageid']);
        $locationOptionNumber = self::getNumberConfigOptionByName('location');
        return $product['configoption' . $locationOptionNumber];
    }

    /**
     * @throws HostkeyResellerModException
     */
    public static function sendCancelRequest($hostingId, $type, $reason)
    {
        static $querySelectCustomFields = 'SELECT `id` FROM `tblcustomfields` WHERE `type`= ? AND `relid` = ? AND fieldname = ?';
        static $stmtSelectCustomFields = null;

        $types = [
            HostkeyResellerModConstants::CANCEL_REASON_IMMEDIATE => 1,
            HostkeyResellerModConstants::CANCEL_REASON_END_OF_BILLING_PERIOD => 0,
        ];
        $pdo = self::getPdo();
        $hosting = self::getEntityById('tblhosting', $hostingId);
        if (!$stmtSelectCustomFields) {
            $stmtSelectCustomFields = $pdo->prepare($querySelectCustomFields);
        }
        $stmtSelectCustomFields->execute(
            ['product', $hosting['packageid'], HostkeyResellerModConstants::CUSTOM_FIELD_INVOICE_ID]
        );
        $fieldid = $stmtSelectCustomFields->fetchColumn();
        $invoiceRow = self::getEntityByCondition('tblcustomfieldsvalues', ['fieldid' => $fieldid, 'relid' => $hostingId]
        );
        $invoice = $invoiceRow['value'];
        $location = self::getLocation($hostingId);
        $serverId = self::getServerIdByInvoiceId($invoice, $location);

        $invapiType = $types[$type];
        $paramsToCall = [
//            'action' => 'request_cancellation',
            'id' => $serverId,
            'pin' => 'not_set',
            'token' => self::getTokenByApiKey(),
            'cancellation_type' => $invapiType,
            'cancellation_reason' => $reason,
        ];
        self::makeInvapiCall($paramsToCall, 'whmcs', 'request_cancellation');
        if ($type == 'Immediate') {
            self::setHostingStatus($hostingId, HostkeyResellerModConstants::PL_HOSTING_STATUS_SUSPENDED);
        }
    }

    /**
     * @throws HostkeyResellerModException
     */
    public static function getServerData($serverId)
    {
        $params = [
//            'action' => 'show',
            'token' => self::getTokenByApiKey(),
            'id' => $serverId,
        ];
        $res = self::makeInvapiCall($params, 'eq', 'show');
        if ($res['result'] == 'OK') {
            return $res['server_data'];
        } else {
            return false;
        }
    }

    /**
     * @throws HostkeyResellerModException
     */
    public static function completeLinkToPreset($hostingId): array
    {
        $hosting = HostkeyResellerModLib::getEntityById('tblhosting', $hostingId);
        $packageid = $hosting['packageid'];
        $order = HostkeyResellerModLib::getEntityById('tblorders', $hosting['orderid']);
        $invoiceId = $order['invoiceid'];
        $apiKeyFieldRow = HostkeyResellerModLib::getEntityByCondition('tblcustomfields', [
            'fieldname' => HostkeyResellerModConstants::CUSTOM_FIELD_API_KEY_NAME,
            'relid' => $packageid
        ]);
        $apiKeyRow = HostkeyResellerModLib::getEntityByCondition('tblcustomfieldsvalues', [
            'fieldid' => $apiKeyFieldRow['id'],
            'relid' => $hostingId
        ]);
        $invoiceIdFieldRow = HostkeyResellerModLib::getEntityByCondition('tblcustomfields', [
            'fieldname' => HostkeyResellerModConstants::CUSTOM_FIELD_INVOICE_ID,
            'relid' => $packageid
        ]);
        $invoiceIdRow = HostkeyResellerModLib::getEntityByCondition('tblcustomfieldsvalues', [
            'fieldid' => $invoiceIdFieldRow['id'],
            'relid' => $hostingId
        ]);
        $invoiceIdExt = $invoiceIdRow['value'] ?? false;
        if ($invoiceIdExt && !$apiKeyRow) {
            $ret = [
                'result' => 'error',
                'server' => false,
                'apikey' => false,
                'addapikey' => false,
                'sethostingstatus' => false,
            ];
            $location = HostkeyResellerModLib::getLocation($hostingId);
            $serverId = HostkeyResellerModLib::getServerIdByInvoiceId($invoiceIdExt, $location);
            if ($serverId > 0) {
                $ret['server'] = true;
                $apiKey = HostkeyResellerModLib::addApiKey(
                    HostkeyResellerModConstants::CUSTOM_FIELD_API_KEY_NAME . ' [' . $serverId . '.' . $invoiceId . ']',
                    $serverId
                );
                if (!$apiKey) {
                    return $ret;
                }
                $ret['apikey'] = true;
                if (!HostkeyResellerModLib::addCustomFieldValue(
                    $packageid,
                    $hostingId,
                    HostkeyResellerModConstants::CUSTOM_FIELD_API_KEY_NAME,
                    $apiKey
                )) {
                    return $ret;
                }
                $ret['addapikey'] = true;
                if (!HostkeyResellerModLib::setHostingStatus(
                    $hostingId,
                    HostkeyResellerModConstants::PL_HOSTING_STATUS_ACTIVE
                )) {
                    return $ret;
                }
                $ret['sethostingstatus'] = true;
                $ret['result'] = 'OK';
                return $ret;
            }
        }
        return [
            'result' => 'OK'
        ];
    }
}
