<?php

namespace WHMCS\Module\Addon\Hostkeyresellermod;

use WHMCS\Module\Addon\Hostkeyresellermod\HostkeyResellerModConstants;
use WHMCS\Module\Addon\Hostkeyresellermod\HostkeyResellerModLib;

class HostkeyResellerModCleaner
{

    const DEFAULT_OLD_PRODUCT_NAME = 'Hostkey old product';

    /**
     *
     * @var \PDO
     */
    private $pdo;
    private $oldProductName = self::DEFAULT_OLD_PRODUCT_NAME;
    private $oldProductId;

    public function __construct($oldProductName = self::DEFAULT_OLD_PRODUCT_NAME)
    {
        $this->pdo = HostkeyResellerModLib::getPdo();
        $this->oldProductName = $oldProductName;
    }

    public static function create($oldProductName = self::DEFAULT_OLD_PRODUCT_NAME)
    {
        return new static($oldProductName);
    }

    public function clear()
    {
        $hostkeyProducts = HostkeyResellerModLib::getEntityByCondition('tblproducts', ['servertype' => HostkeyResellerModConstants::HOSTKEYRESELLERMOD_MODULE_NAME], true);
        foreach ($hostkeyProducts as $product) {
            $this->clearProduct($product);
        }
        if (HostkeyResellerModLib::isConsole()) {
            echo "Cleaning groups...\n";
        }
        $stmt = $this->pdo->prepare('SELECT COUNT(*) AS cnt FROM `tblproductgroups` WHERE `tagline` = ?');
        $stmt->execute([HostkeyResellerModConstants::GROUP_HEADLINE]);
        $groupCount = $stmt->fetchColumn();
        $this->clearGroups();
        if (HostkeyResellerModLib::isConsole()) {
            echo "Finished\n";
        }
        return ['groups' => $groupCount, 'products' => count($hostkeyProducts)];
    }

    protected function deleteProductInfo($product)
    {
        static $queryDeleteProductInfo = 'DELETE FROM `' . HostkeyResellerModConstants::HOSTKEYRESELLERMOD_TABLE_NAME . '` WHERE `type` = ? AND `relid`=  ?';
        static $stmtDeleteProductInfo = null;
        if (!$stmtDeleteProductInfo) {
            $stmtDeleteProductInfo = $this->pdo->prepare($queryDeleteProductInfo);
        }
        $stmtDeleteProductInfo->execute(['product', $product['id']]);
    }

    protected function clearProduct(array $product)
    {
        if (HostkeyResellerModLib::isConsole()) {
            echo 'Cleaning ' . $product['name'] . "...";
        }
        $hostings = HostkeyResellerModLib::getEntityByCondition('tblhosting', ['packageid' => $product['id']], true);
        $hostingsIds = [];
        if (count($hostings) > 0) {
            foreach ($hostings as $row) {
                $hostingsIds[] = $row['id'];
            }
            $this->customFieldsCleaning($product, $hostingsIds);
            $this->hostingCleaning($hostings);
        }
        $this->deleteConfigOptions($product);
        $this->deleteProduct($product);
        $this->deleteProductInfo($product);
        if (HostkeyResellerModLib::isConsole()) {
            echo " Done\n";
        }
    }

    protected function clearGroups()
    {
        $queryIds = 'SELECT `relid` FROM `' . HostkeyResellerModConstants::HOSTKEYRESELLERMOD_TABLE_NAME . '` WHERE `type` = ?';
        $stmtIds = $this->pdo->prepare($queryIds);
        $stmtIds->execute(['group']);
        $ids = $stmtIds->fetchAll(\PDO::FETCH_COLUMN, 0);
        $queryClearGroups = 'DELETE FROM `tblproductgroups` WHERE `id` IN (' . implode(',', $ids) . ')';
        $this->pdo->prepare($queryClearGroups)->execute();
        $queryClearGroupsInfo = 'DELETE FROM `' . HostkeyResellerModConstants::HOSTKEYRESELLERMOD_TABLE_NAME . '` WHERE `type` = ?';
        $this->pdo->prepare($queryClearGroupsInfo)->execute(['group']);
    }

    private function customFieldsCleaning($product, $hostingsIds)
    {
        static $querySelectCustomFields = 'SELECT `id` FROM `tblcustomfields` WHERE `type`= ? AND `relid` = ? AND fieldname = ?';
        static $queryDeleteCustomFields = 'DELETE FROM `tblcustomfields` WHERE `type`= \'product\' AND `relid` = ? AND fieldname = ?';
        static $stmtSelectCustomFields = null;
        static $stmtDeleteCustomFields = null;

        if (!$stmtSelectCustomFields) {
            $stmtSelectCustomFields = $this->pdo->prepare($querySelectCustomFields);
        }
        $queryDeleteCustomFieldsValue = 'DELETE FROM `tblcustomfieldsvalues` WHERE `fieldid`= ? AND `relid` IN (' . implode(',', $hostingsIds) . ')';
        $stmtDeleteCustomFieldsValue = $this->pdo->prepare($queryDeleteCustomFieldsValue);
        $customFielddNames = [HostkeyResellerModConstants::CUSTOM_FIELD_API_KEY_NAME, HostkeyResellerModConstants::CUSTOM_FIELD_INVOICE_ID];
        foreach ($customFielddNames as $name) {
            $stmtSelectCustomFields->execute(['product', $product['id'], $name]);
            $fieldid = $stmtSelectCustomFields->fetchColumn();
            $stmtDeleteCustomFieldsValue->execute([$fieldid]);
        }
        if (!$stmtDeleteCustomFields) {
            $stmtDeleteCustomFields = $this->pdo->prepare($queryDeleteCustomFields);
        }
        $stmtDeleteCustomFields->execute([$product['id'], HostkeyResellerModConstants::CUSTOM_FIELD_API_KEY_NAME]);
        $stmtDeleteCustomFields->execute([$product['id'], HostkeyResellerModConstants::CUSTOM_FIELD_INVOICE_ID]);
    }

    private function hostingCleaning($hostings)
    {
        static $queryHostingUpdate = 'UPDATE `tblhosting` SET `packageid` = ?, `domainstatus` = ? WHERE `id` = ?';
        static $queryHostingDelete = 'DELETE FROM `tblhostingconfigoptions` WHERE `relid` = ?';
        static $stmtHostingDelete = null;
        static $stmtHostingUpdate = null;

        if (!$stmtHostingUpdate) {
            $stmtHostingUpdate = $this->pdo->prepare($queryHostingUpdate);
        }
        if (!$stmtHostingDelete) {
            $stmtHostingDelete = $this->pdo->prepare($queryHostingDelete);
        }
        foreach ($hostings as $row) {
            $stmtHostingDelete->execute([$row['id']]);
            $stmtHostingUpdate->execute([$this->getOldProductId(), 'Suspended', $row['id']]);
        }
    }

    private function deleteConfigOptions($product)
    {
        $productConfigLink = HostkeyResellerModLib::getEntityByCondition('tblproductconfiglinks', ['pid' => $product['id']]);
        if ($productConfigLink) {
            $productConfigGroup = HostkeyResellerModLib::getEntityById('tblproductconfiggroups', $productConfigLink['gid']);
            if ($productConfigGroup) {
                $productConfigOptions = HostkeyResellerModLib::getEntityByCondition('tblproductconfigoptions', ['gid' => $productConfigGroup['id']], true);
                if ($productConfigOptions) {
                    $ids = [];
                    foreach ($productConfigOptions as $option) {
                        $ids[] = $option['id'];
                    }
                    $queryGetConfigOptionSubId = 'SELECT `id` FROM `tblproductconfigoptionssub` WHERE `configid` IN (' . implode(',', $ids) . ')';
                    $stmtGetConfigOptionSubId = $this->pdo->prepare($queryGetConfigOptionSubId);
                    $stmtGetConfigOptionSubId->execute();
                    $optionIds = $stmtGetConfigOptionSubId->fetchAll(\PDO::FETCH_COLUMN);
                    if (count($optionIds) > 0) {
                        $queryDeleteFromPricing = 'DELETE FROM `tblpricing` WHERE `type` = \'configoptions\' AND `relid` IN (' . implode(',', $optionIds) . ')';
                        $this->pdo->prepare($queryDeleteFromPricing)->execute();
                    }
                    $queryDeleteConfigOptionSub = 'DELETE FROM `tblproductconfigoptionssub` WHERE `configid` IN (' . implode(',', $ids) . ')';
                    $this->pdo->prepare($queryDeleteConfigOptionSub)->execute();
                }
                $this->pdo->prepare('DELETE FROM `tblproductconfigoptions` WHERE `gid` = ?')->execute([$productConfigGroup['id']]);
                $this->pdo->prepare('DELETE FROM `tblproductconfiggroups` WHERE `id` = ?')->execute([$productConfigGroup['id']]);
            }
            $this->pdo->prepare('DELETE FROM `tblproductconfiglinks` WHERE `pid` = ?')->execute([$product['id']]);
        }
    }

    private function deleteProduct($product)
    {
        static $queryDeleteProductSlug = 'DELETE FROM `tblproducts_slugs` WHERE `product_id`=  ?';
        static $stmtDeleteProductSlug = null;
        static $queryDeleteProductPricing = 'DELETE FROM `tblpricing` WHERE `type` = \'product\' AND `relid`=  ?';
        static $stmtDeleteProductPricing = null;
        static $queryDeleteProduct = 'DELETE FROM `tblproducts` WHERE `id`=  ?';
        static $stmtDeleteProduct = null;

        if (HostkeyResellerModLib::tableExists('tblproducts_slugs')) {
            if (!$stmtDeleteProductSlug) {
                $stmtDeleteProductSlug = $this->pdo->prepare($queryDeleteProductSlug);
            }
            $stmtDeleteProductSlug->execute([$product['id']]);
        }
        if (!$stmtDeleteProduct) {
            $stmtDeleteProduct = $this->pdo->prepare($queryDeleteProduct);
        }
        if (!$stmtDeleteProductPricing) {
            $stmtDeleteProductPricing = $this->pdo->prepare($queryDeleteProductPricing);
        }
        $stmtDeleteProductPricing->execute([$product['id']]);
        $stmtDeleteProduct->execute([$product['id']]);
    }

    protected function getOldProductId()
    {
        if (!$this->oldProductId) {
            $product = HostkeyResellerModLib::getEntityByCondition('tblproducts', ['name' => $this->oldProductName]);
            if ($product) {
                $this->oldProductId = $product['id'];
            } else {
                $fields = HostkeyResellerModLib::getDefaultProductFields();
                $fields['gid'] = $this->getOldGroupId();
                $fields['name'] = $this->oldProductName;
                $fields['description'] = $this->oldProductName;
                $fields['hidden'] = 1;
                $query = HostkeyResellerModLib::makeInsertInto('tblproducts', $fields);
                $stmt = $this->pdo->prepare($query);
                $stmt->execute(array_values($fields));
                $this->oldProductId = $this->pdo->lastInsertId();
            }
        }
        return $this->oldProductId;
    }

    protected function getOldGroupId()
    {
        $group = HostkeyResellerModLib::getEntityByCondition('tblproductgroups', ['name' => $this->oldProductName]);
        if ($group) {
            return $group['id'];
        } else {
            $stmt = $this->pdo->prepare('SELECT MAX(`order`) as `max` FROM `tblproductgroups`');
            $stmt->execute();
            $max = $stmt->fetch(\PDO::FETCH_ASSOC)['max'];
            $productGroup = [
                'name' => $this->oldProductName,
                'slug' => str_replace([' ', ';'], '-', strtolower($this->oldProductName)),
                'headline' => $this->oldProductName,
                'tagline' => '',
                'orderfrmtpl' => '',
                'disabledgateways' => '',
                'hidden' => 1,
                'order' => $max + 1,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ];
            $query = HostkeyResellerModLib::makeInsertInto('tblproductgroups', $productGroup);
            $this->pdo->prepare($query)->execute(array_values($productGroup));
            return $this->pdo->lastInsertId();
        }
    }

    public static function out($ret)
    {
        $out = '<h3>Done!</h3>';
        $out .= '<p>Removed ' . $ret['groups'] . ' groups and ' . $ret['products'] . ' products</p>';
        $out .= '<p>You can load product again or deactivate the module</p>';
        return $out;
    }
}
