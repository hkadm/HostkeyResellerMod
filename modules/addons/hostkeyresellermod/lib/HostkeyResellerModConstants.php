<?php

namespace WHMCS\Module\Addon\Hostkeyresellermod;

class HostkeyResellerModConstants
{

    const HOSTKEYRESELLERMOD_MODULE_NAME = 'hostkeyresellermod';
    const HOSTKEYRESELLERMOD_TABLE_NAME = 'mod_hostkeyresellermod';
    const GROUP_HEADLINE = 'Reseller plan for Hostkey servers';
    const CONFIG_GROUP_SERVER_OPTIONS_SUFFIX = ' server options';
    const CONFIG_OPTION_LOCATION_NAME_PREFIX = 'Location ';
    const CONFIG_OPTION_OS_NAME_PREFIX = 'OS ';
    const CONFIG_OPTION_SOFT_NAME_PREFIX = 'Soft ';
    const CONFIG_OPTION_TRAFFIC_NAME_PREFIX = 'Traffic ';
    const TAGLINE_SUFFIX = ' tagline';
    const CUSTOM_FIELD_API_KEY_NAME = 'Api key';
    const CUSTOM_FIELD_INVOICE_ID = 'Invoice ID';
    const DO_NOT_INSTALL_ITEM_NAME = 'Do not install';
    const PASSWORD_LENGTH = 12;
    const PL_HOSTING_STATUS_PENDING = 'Pending';
    const PL_HOSTING_STATUS_ACTIVE = 'Active';
    const PL_HOSTING_STATUS_SUSPENDED = 'Suspended';
    const PL_HOSTING_STATUS_TERMINATED = 'Terminated';
    const PL_HOSTING_STATUS_CANCELLED = 'Cancelled';
    const PL_HOSTING_STATUS_FRAUD = 'Fraud';
    const PL_HOSTING_STATUS_COMPLETED = 'Completed';
    const CANCEL_REASON_IMMEDIATE = 'Immediate';
    const CANCEL_REASON_END_OF_BILLING_RERIOD = 'End of Billing Period';

    public static function getHostingStatuses()
    {
        return [
            self::PL_HOSTING_STATUS_PENDING,
            self::PL_HOSTING_STATUS_ACTIVE,
            self::PL_HOSTING_STATUS_SUSPENDED,
            self::PL_HOSTING_STATUS_TERMINATED,
            self::PL_HOSTING_STATUS_CANCELLED,
            self::PL_HOSTING_STATUS_FRAUD,
            self::PL_HOSTING_STATUS_COMPLETED,
        ];
    }

    public static function getProductGroups()
    {
        return [
            'vps' => 'VPS products',
            'bm' => 'Bare metal products',
            'gpu' => 'GPU products',
        ];
    }

    public static function getProductGroupsButtons()
    {
        $ret = [];
        foreach (self::getProductGroups() as $key => $name) {
            $ret[$key] = 'Import ' . $name;
        }
        return $ret;
    }

    public static function getGroupPrefixes()
    {
        return [
            'gpu' => 'gpu',
            'vgpu' => 'gpu',
            'vm' => 'vps',
            'vds' => 'vps',
            'bm' => 'bm',
        ];
    }

    public static function getGroupByPresetName($name)
    {
        $prefix = explode('.', $name)[0];
        return HostkeyResellerModConstants::getGroupPrefixes()[$prefix] ?? '';
    }

    public static function getGroupNameByPresetName($name)
    {
        $group = self::getGroupByPresetName($name);
        return self::getProductGroups()[$group] ?? null;
    }
}
