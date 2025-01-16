<?php

namespace WHMCS\Module\Addon\Hostkeyresellermod;

use PDO;

class HostkeyResellerModCleaner
{

    /**
     *
     * @var PDO
     */
    private $pdo;

    public function __construct()
    {
        $this->pdo = HostkeyResellerModLib::getPdo();
    }

    public static function create(): HostkeyResellerModCleaner
    {
        return new static();
    }

    public function clear(): array
    {
        $hostkeyProducts = HostkeyResellerModLib::getEntityByCondition(
            'tblproducts',
            ['servertype' => HostkeyResellerModConstants::HOSTKEYRESELLERMOD_MODULE_NAME],
            true
        );
        foreach ($hostkeyProducts as $product) {
            $this->clearProduct($product);
        }
        if (HostkeyResellerModLib::isConsole()) {
            echo "Cleaning groups...\n";
        }
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) AS cnt FROM `' . HostkeyResellerModConstants::HOSTKEYRESELLERMOD_TABLE_NAME . '` WHERE `type` = ?'
        );
        $stmt->execute(['group']);
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
        foreach ($hostings as $row) {
            $hostingsIds[] = $row['id'];
        }
        if (count($hostingsIds) > 0) {
            $this->pdo->prepare('UPDATE `tblproducts` SET `hidden` = 1 WHERE `id` = ?')->execute($product['id']);
        } else {
            $this->customFieldsCleaning($product, $hostingsIds);
            $this->deleteConfigOptions($product);
            $this->deleteProduct($product);
            $this->deleteProductInfo($product);
        }
        if (HostkeyResellerModLib::isConsole()) {
            echo " Done\n";
        }
    }

    protected function clearGroups()
    {
        $queryIds = 'SELECT `relid` FROM `' . HostkeyResellerModConstants::HOSTKEYRESELLERMOD_TABLE_NAME . '` WHERE `type` = ?';
        $stmtIds = $this->pdo->prepare($queryIds);
        $stmtIds->execute(['group']);
        $ids = $stmtIds->fetchAll(PDO::FETCH_COLUMN, 0);
        if ($ids) {
            $queryClearGroups = 'DELETE FROM `tblproductgroups` WHERE `id` IN (' . implode(',', $ids) . ')';
            $this->pdo->prepare($queryClearGroups)->execute();
        }
        $queryClearGroupsInfo = 'DELETE FROM `' . HostkeyResellerModConstants::HOSTKEYRESELLERMOD_TABLE_NAME . '` WHERE `type` = ?';
        $this->pdo->prepare($queryClearGroupsInfo)->execute(['group']);
    }

    private function customFieldsCleaning($product, $hostingsIds)
    {
        static $querySelectCustomFields = 'SELECT `id` FROM `tblcustomfields` WHERE `type`= \'product\' AND `relid` = ? AND fieldname = ?';
        static $queryDeleteCustomFields = 'DELETE FROM `tblcustomfields` WHERE `type`= \'product\' AND `relid` = ? AND fieldname = ?';
        static $stmtSelectCustomFields = null;
        static $stmtDeleteCustomFields = null;
        static $customFieldNames = [
            HostkeyResellerModConstants::CUSTOM_FIELD_API_KEY_NAME,
            HostkeyResellerModConstants::CUSTOM_FIELD_INVOICE_ID,
            HostkeyResellerModConstants::CUSTOM_FIELD_PRESET_ID
        ];

        if (!$stmtSelectCustomFields) {
            $stmtSelectCustomFields = $this->pdo->prepare($querySelectCustomFields);
        }
        if (!$stmtDeleteCustomFields) {
            $stmtDeleteCustomFields = $this->pdo->prepare($queryDeleteCustomFields);
        }
        foreach ($customFieldNames as $name) {
            $stmtSelectCustomFields->execute([$product['id'], $name]);
            $fieldid = $stmtSelectCustomFields->fetchColumn();
            if ($hostingsIds) {
                $queryDeleteCustomFieldsValue = 'DELETE FROM `tblcustomfieldsvalues` WHERE `fieldid`= ? AND `relid` IN (' . implode(
                        ',',
                        $hostingsIds
                    ) . ')';
                $stmtDeleteCustomFieldsValue = $this->pdo->prepare($queryDeleteCustomFieldsValue);
                $stmtDeleteCustomFieldsValue->execute([$fieldid]);
            }
            $stmtDeleteCustomFields->execute([$product['id'], $name]);
        }
    }

    private function deleteConfigOptions($product)
    {
        $productConfigLink = HostkeyResellerModLib::getEntityByCondition(
            'tblproductconfiglinks',
            ['pid' => $product['id']]
        );
        if ($productConfigLink) {
            $productConfigGroup = HostkeyResellerModLib::getEntityById(
                'tblproductconfiggroups',
                $productConfigLink['gid']
            );
            if ($productConfigGroup) {
                $productConfigOptions = HostkeyResellerModLib::getEntityByCondition(
                    'tblproductconfigoptions',
                    ['gid' => $productConfigGroup['id']],
                    true
                );
                if ($productConfigOptions) {
                    $ids = [];
                    foreach ($productConfigOptions as $option) {
                        $ids[] = $option['id'];
                    }
                    $queryGetConfigOptionSubId = 'SELECT `id` FROM `tblproductconfigoptionssub` WHERE `configid` IN (' . implode(
                            ',',
                            $ids
                        ) . ')';
                    $stmtGetConfigOptionSubId = $this->pdo->prepare($queryGetConfigOptionSubId);
                    $stmtGetConfigOptionSubId->execute();
                    $optionIds = $stmtGetConfigOptionSubId->fetchAll(PDO::FETCH_COLUMN);
                    if (count($optionIds) > 0) {
                        $queryDeleteFromPricing = 'DELETE FROM `tblpricing` WHERE `type` = \'configoptions\' AND `relid` IN (' . implode(
                                ',',
                                $optionIds
                            ) . ')';
                        $this->pdo->prepare($queryDeleteFromPricing)->execute();
                    }
                    $queryDeleteConfigOptionSub = 'DELETE FROM `tblproductconfigoptionssub` WHERE `configid` IN (' . implode(
                            ',',
                            $ids
                        ) . ')';
                    $this->pdo->prepare($queryDeleteConfigOptionSub)->execute();
                }
                $this->pdo->prepare('DELETE FROM `tblproductconfigoptions` WHERE `gid` = ?')->execute(
                    [$productConfigGroup['id']]
                );
                $this->pdo->prepare('DELETE FROM `tblproductconfiggroups` WHERE `id` = ?')->execute(
                    [$productConfigGroup['id']]
                );
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

    public static function out($ret): string
    {
        $out = '<h3>Done!</h3>';
        $out .= '<p>Removed ' . $ret['groups'] . ' groups and ' . $ret['products'] . ' products</p>';
        $out .= '<p>You can load product again or deactivate the module</p>';
        return $out;
    }
}
