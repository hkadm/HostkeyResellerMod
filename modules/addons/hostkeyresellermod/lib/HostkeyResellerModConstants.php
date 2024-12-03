<?php

namespace WHMCS\Module\Addon\Hostkeyresellermod;

class HostkeyResellerModConstants
{

    const HOSTKEYRESELLERMOD_MODULE_NAME = 'hostkeyresellermod';
    const HOSTKEYRESELLERMOD_TABLE_NAME = 'mod_hostkeyresellermod';
    const HOSTKEYRESELLERMOD_LOG_TABLE_NAME = 'mod_hostkeyresellermod_log';
    const GROUP_HEADLINE = 'Reseller plan for Hostkey servers';
    const CONFIG_GROUP_SERVER_OPTIONS_SUFFIX = ' server options';
    const CONFIG_OPTION_OS_NAME_PREFIX = 'OS ';
    const CONFIG_OPTION_SOFT_NAME_PREFIX = 'Soft ';
    const CONFIG_OPTION_TRAFFIC_NAME_PREFIX = 'Traffic ';
    const TAGLINE_SUFFIX = ' tagline';
    const CUSTOM_FIELD_API_KEY_NAME = 'Api key';
    const CUSTOM_FIELD_INVOICE_ID = 'Invoice ID';
    const CUSTOM_FIELD_PRESET_ID = 'Preset ID';
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
    const CANCEL_REASON_END_OF_BILLING_PERIOD = 'End of Billing Period';

    public static function getHostingStatuses(): array
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

    public static function getProductGroups(): array
    {
        return [
            'vps' => 'VPS products',
            'bm' => 'Bare metal products',
            'gpu' => 'GPU products',
        ];
    }

    public static function getProductGroupsButtons(): array
    {
        return array_map(function ($name) {
            return 'Import ' . $name;
        }, self::getProductGroups());
    }

    public static function getGroupPrefixes(): array
    {
        return [
            'gpu' => 'gpu',
            'vgpu' => 'gpu',
            'vm' => 'vps',
            'vds' => 'vps',
            'bm' => 'bm',
        ];
    }

    public static function getGroupByPresetName($name): string
    {
        $prefix = explode('.', $name)[0];
        return HostkeyResellerModConstants::getGroupPrefixes()[$prefix] ?? '';
    }

    public static function getGroupNameByPresetName($name): ?string
    {
        $group = self::getGroupByPresetName($name);
        return self::getProductGroups()[$group] ?? null;
    }
}
